<?php

namespace App\Modules\Fiscal\Services;

use App\Models\Setting;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\NumeracaoOcorrencia;
use App\Modules\Fiscal\Support\CancelamentoAutomaticoDaNota;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Modules\Fiscal\Support\SituacaoNaSefaz;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Support\MercadoLivreFull;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conciliação fiscal autônoma. Pedido do Lira em 12/11/2026: "se foi
 * cancelado ou devolvido o sistema tem que fazer automático, com alerta".
 *
 * conferir() SÓ LÊ: junta o que está fora do lugar (venda sem nota, nota
 * viva de pedido cancelado, devolução parada, ocorrência de numeração
 * aberta, buraco na série, nota sem pedido) e alertar() transforma cada
 * grupo num alerta de Sistema › Alertas (tipo fiscal_conciliacao) que diz
 * o que o sistema vai fazer. Grupo que zerou tem o alerta fechado sozinho.
 *
 * inutilizarBuracos() é a única parte que age na SEFAZ, e só com
 * fiscal:conciliar --executar no servidor de produção com
 * nfe.ambiente=producao: número da nossa série sem nota há mais de 3 dias,
 * conferido na SEFAZ pela chave quando há nota local, faixa a faixa pelo
 * InutilizacaoService. Erro da SEFAZ vira alerta e a faixa não é tentada de
 * novo até alguém resolver o alerta (nunca retentativa em loop).
 */
class ConciliacaoFiscalService
{
    public const RESUMO = 'fiscal.conciliacao.resumo';

    /** Exigência da SEFAZ: 15 a 255 caracteres. Sem acento de propósito. */
    public const JUSTIFICATIVA = 'Numeracao nao utilizada por falha no sistema emissor';

    /** Faixa maior que isso não é inutilizada sozinha: provavelmente é configuração errada. */
    public const MAIOR_FAIXA_AUTOMATICA = 20;

    private const ORIGENS_DOCUMENTO = [Order::ORIGIN_SALES_RETURN_INVOICE, Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_MANUAL_INVOICE];

    /** @var array<string, array{titulo: string, acao: string, link: ?string}> */
    public const GRUPOS = [
        'vendas_sem_nota' => [
            'titulo' => 'venda(s) paga(s) sem NF-e válida há mais de 2h',
            'acao' => 'O sistema tenta de novo sozinho (nfe:retry-stuck a cada 15 min; canais do Bling pelo invoices:sync-bling). Nota rejeitada ou denegada repetida, carrinho bloqueado e pedido da loja precisam de conferência.',
            'link' => '/admin/notas-fiscais',
        ],
        'canceladas_com_nota' => [
            'titulo' => 'nota(s) autorizada(s) de pedido cancelado sem cancelamento nem devolução',
            'acao' => 'Até 24h o sistema cancela na SEFAZ sem multa (a cada 10 min); depois disso emite a devolução sozinho às 08:20. Venda que valeu e estorno coberto pelo ML ficam de fora.',
            'link' => '/admin/notas-fiscais',
        ],
        'devolucoes_paradas' => [
            'titulo' => 'devolução(ões) com a nota preparada parada há mais de 15 dias',
            'acao' => 'A nota de entrada sai sozinha quando o produto chega. Se o produto já voltou, registre o recebimento no caso ou use o botão Emitir nota de devolução; se não vai voltar, encerre o caso.',
            'link' => '/admin/devolucoes',
        ],
        'ocorrencias_abertas' => [
            'titulo' => 'ocorrência(s) de numeração sem conferência',
            'acao' => 'Número consumido por outra chave (duplicidade 539): falta o XML dessa nota. Confira no Fechamento fiscal e marque como conferida. O sistema não age sozinho.',
            'link' => '/admin/notas-fiscais/fechamento',
        ],
        'buracos' => [
            'titulo' => 'faixa(s) de número da série sem nota',
            'acao' => 'O sistema inutiliza sozinho, uma vez por dia (07:30), a faixa sem nota há mais de 3 dias, depois de conferir na SEFAZ as notas locais. Erro da SEFAZ vira alerta próprio.',
            'link' => '/admin/notas-fiscais/fechamento',
        ],
        'notas_sem_pedido' => [
            'titulo' => 'nota(s) autorizada(s) sem pedido no sistema',
            'acao' => 'Confira de onde veio a nota (Bling, canal ou outro emissor) e ligue ao pedido. O sistema não age sozinho.',
            'link' => '/admin/notas-fiscais',
        ],
    ];

    public function __construct(
        private readonly FechamentoFiscalService $fechamento,
        private readonly SalesReturnService $devolucoes,
        private readonly PackDoPedido $pack,
        private readonly AlertasAdmin $alertas,
    ) {}

    /**
     * @return array<string, list<array{id: int, texto: string}>>
     */
    public function conferir(int $dias = 60): array
    {
        return [
            'vendas_sem_nota' => $this->vendasSemNota($dias),
            'canceladas_com_nota' => $this->canceladasComNota($dias),
            'devolucoes_paradas' => $this->devolucoesParadas(),
            'ocorrencias_abertas' => $this->ocorrenciasAbertas(),
            'buracos' => $this->buracos(),
            'notas_sem_pedido' => $this->notasSemPedido($dias),
        ];
    }

    /**
     * Um alerta por grupo (lista de ids + o que o sistema faz); grupo vazio
     * fecha o alerta aberto. Grava o resumo (Setting fiscal.conciliacao.resumo
     * + log); buraco que não estava no resumo anterior sai marcado [novo].
     *
     * @param  array<string, list<array{id: int, texto: string}>>  $achados
     * @param  list<array<string, mixed>>  $inutilizacoes
     */
    public function alertar(array $achados, array $inutilizacoes = []): void
    {
        $anterior = json_decode((string) Setting::get(self::RESUMO, '{}'), true) ?: [];
        $buracosAntes = $anterior['buracos'] ?? [];

        foreach (self::GRUPOS as $grupo => $def) {
            $itens = $achados[$grupo] ?? [];

            if ($itens === []) {
                rescue(fn () => $this->alertas->resolver(AlertasAdmin::FISCAL_CONCILIACAO, $grupo), report: false);

                continue;
            }

            $lista = collect($itens)->take(15)->map(function ($item) use ($grupo, $buracosAntes) {
                $novo = $grupo === 'buracos' && ! in_array($item['texto'], $buracosAntes, true) ? ' [novo]' : '';

                return $item['texto'].$novo;
            })->implode('; ');
            $resto = count($itens) > 15 ? ' e mais '.(count($itens) - 15) : '';

            rescue(fn () => $this->alertas->disparar(
                AlertasAdmin::FISCAL_CONCILIACAO,
                $grupo,
                count($itens).' '.$def['titulo'],
                "{$lista}{$resto}. {$def['acao']}",
                $def['link'],
            ), report: false);
        }

        $resumo = [
            'gerado_em' => now()->toDateTimeString(),
            'totais' => collect($achados)->map(fn ($itens) => count($itens))->all(),
            'ids' => collect($achados)->except('buracos')->map(fn ($itens) => collect($itens)->pluck('id')->take(100)->all())->all(),
            'buracos' => collect($achados['buracos'] ?? [])->pluck('texto')->all(),
            'inutilizacoes' => $inutilizacoes,
        ];

        Setting::set(self::RESUMO, json_encode($resumo, JSON_UNESCAPED_UNICODE));
        Log::info('fiscal.conciliacao', $resumo);
    }

    /** Trava de ambiente da inutilização automática. */
    public function inutilizacaoLiberada(): bool
    {
        return config('nfe.ambiente') === Invoice::AMBIENTE_PRODUCAO && CancelamentoAutomaticoDaNota::servidorDeProducao();
    }

    /**
     * Inutiliza as faixas da série sem nota há mais de 3 dias. Para na
     * primeira recusa da SEFAZ.
     *
     * @return list<array{faixa: string, resultado: string, detalhe: string}>
     */
    public function inutilizarBuracos(int $limite = 10): array
    {
        if (! $this->inutilizacaoLiberada()) {
            return [];
        }

        $serie = (int) config('nfe.serie');
        $ambiente = Invoice::AMBIENTE_PRODUCAO;
        $corte = now()->subDays(3);
        $notas = $this->notasDaSerie($serie, $ambiente);
        $feitos = [];

        foreach ($this->fechamento->buracos($serie, $ambiente) as $faixa) {
            if (count($feitos) >= $limite) {
                break;
            }

            $rotulo = $faixa['inicio'] === $faixa['fim'] ? (string) $faixa['inicio'] : "{$faixa['inicio']} a {$faixa['fim']}";
            $chaveAlerta = "inutilizacao:{$serie}:{$faixa['inicio']}-{$faixa['fim']}";

            if (AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::FISCAL_CONCILIACAO)->where('chave', $chaveAlerta)->exists()) {
                $feitos[] = ['faixa' => $rotulo, 'resultado' => 'aguardando', 'detalhe' => 'erro anterior em aberto em Sistema › Alertas'];

                continue;
            }

            if ($faixa['quantidade'] > self::MAIOR_FAIXA_AUTOMATICA) {
                $this->alertarFaixa($chaveAlerta, $rotulo, "A faixa tem {$faixa['quantidade']} números, mais que o limite de ".self::MAIOR_FAIXA_AUTOMATICA.' para inutilizar sozinho. Confira (pode ser configuração de numeração) e inutilize pelo Fechamento fiscal.');
                $feitos[] = ['faixa' => $rotulo, 'resultado' => 'grande_demais', 'detalhe' => "{$faixa['quantidade']} números"];

                continue;
            }

            $locais = $notas->filter(fn ($n) => $n['numero'] >= $faixa['inicio'] && $n['numero'] <= $faixa['fim']);
            $proxima = $notas->filter(fn ($n) => $n['numero'] > $faixa['fim'])->sortBy('numero')->first();

            $recente = $locais->isNotEmpty()
                ? $locais->contains(fn ($n) => ! $n['atualizada_em'] || $n['atualizada_em']->gt($corte))
                : (! $proxima || ! $proxima['criada_em'] || $proxima['criada_em']->gt($corte));

            if ($recente) {
                continue;
            }

            if ($bloqueio = app(InvoiceService::class)->bloqueadoPorConsumoIndevidoAte()) {
                $feitos[] = ['faixa' => $rotulo, 'resultado' => 'bloqueado', 'detalhe' => "SEFAZ bloqueando (656) até {$bloqueio}"];

                break;
            }

            $impedimento = $this->conferirNaSefaz($locais);

            if ($impedimento !== null) {
                $this->alertarFaixa($chaveAlerta, $rotulo, "{$impedimento} O número não foi inutilizado; confira a nota.");
                $feitos[] = ['faixa' => $rotulo, 'resultado' => 'conferir', 'detalhe' => $impedimento];

                continue;
            }

            try {
                $ocorrencia = app(InutilizacaoService::class)->inutilizar($faixa['inicio'], $faixa['fim'], self::JUSTIFICATIVA, null);
            } catch (Throwable $e) {
                $erro = mb_substr($e->getMessage(), 0, 300);
                Log::warning('fiscal.inutilizacao_automatica.falhou', ['serie' => $serie, 'faixa' => $rotulo, 'erro' => $erro]);
                $this->alertarFaixa($chaveAlerta, $rotulo, "A SEFAZ recusou a inutilização: {$erro}. O sistema não tenta de novo esta faixa até o alerta ser resolvido.");
                $feitos[] = ['faixa' => $rotulo, 'resultado' => 'erro', 'detalhe' => $erro];

                // Recusa da SEFAZ: para a rodada em vez de insistir com as próximas.
                break;
            }

            Log::info('fiscal.inutilizacao_automatica', ['serie' => $serie, 'faixa' => $rotulo, 'protocolo' => $ocorrencia->protocolo, 'motivo' => $faixa['motivo']]);

            // Nota parada que o nfe:retry-stuck marcou para inutilização: resolvida.
            foreach ($locais as $nota) {
                rescue(fn () => $this->alertas->resolver(AlertasAdmin::FISCAL_CONCILIACAO, "parada:{$nota['id']}"), report: false);
            }

            $feitos[] = ['faixa' => $rotulo, 'resultado' => 'inutilizada', 'detalhe' => "protocolo {$ocorrencia->protocolo} ({$faixa['motivo']})"];
        }

        return $feitos;
    }

    /**
     * Nota local da faixa: só inutiliza se a SEFAZ disser que ela não existe
     * (217). Devolve o motivo de não inutilizar, ou null.
     *
     * @param  Collection<int, array<string, mixed>>  $locais
     */
    private function conferirNaSefaz(Collection $locais): ?string
    {
        foreach ($locais as $nota) {
            if (strlen((string) $nota['chave']) !== 44) {
                continue;
            }

            try {
                $situacao = app(SituacaoNaSefaz::class)->consultar($nota['chave']);
            } catch (Throwable $e) {
                return "A consulta da nota nº {$nota['numero']} (invoice {$nota['id']}) na SEFAZ falhou: ".mb_substr($e->getMessage(), 0, 150).'.';
            }

            if ($situacao['existe'] !== false) {
                return "A SEFAZ respondeu {$situacao['cStat']} - {$situacao['xMotivo']} para a nota nº {$nota['numero']} (invoice {$nota['id']}).";
            }
        }

        return null;
    }

    private function alertarFaixa(string $chave, string $rotulo, string $mensagem): void
    {
        rescue(fn () => $this->alertas->disparar(
            AlertasAdmin::FISCAL_CONCILIACAO,
            $chave,
            "Inutilização automática da série ".config('nfe.serie')." nº {$rotulo} não foi feita",
            $mensagem,
            '/admin/notas-fiscais/fechamento',
        ), report: false);
    }

    /** @return Collection<int, array{id: int, numero: int, chave: ?string, criada_em: mixed, atualizada_em: mixed}> */
    private function notasDaSerie(int $serie, string $ambiente): Collection
    {
        return Invoice::query()
            ->where('ambiente', $ambiente)
            ->get(['id', 'serie', 'numero', 'chave_acesso', 'created_at', 'updated_at'])
            ->map(function (Invoice $nota) use ($serie) {
                [$serieReal, $numero] = $this->fechamento->serieENumero($nota);

                return $serieReal === $serie && $numero
                    ? ['id' => $nota->id, 'numero' => $numero, 'chave' => preg_replace('/\D/', '', (string) $nota->chave_acesso) ?: null, 'criada_em' => $nota->created_at, 'atualizada_em' => $nota->updated_at]
                    : null;
            })
            ->filter()
            ->values();
    }

    /** @return list<array{id: int, texto: string}> */
    private function vendasSemNota(int $dias): array
    {
        return Order::query()
            ->with('invoice')
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->whereNotIn('origin', self::ORIGENS_DOCUMENTO)
            ->whereBetween('created_at', [now()->subDays($dias), now()->subHours(2)])
            // Full do ML: quem fatura é o ML (ver MercadoLivreFull).
            ->whereDoesntHave('channelShipment', fn ($q) => $q->where('shipping_method', MercadoLivreFull::LOGISTIC_TYPE))
            ->where(fn ($q) => $q->doesntHave('invoice')
                ->orWhereHas('invoice', fn ($q) => $q->whereNotIn('status', [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL])))
            ->orderBy('id')
            ->get()
            // Carrinho do ML: o pedido coberto pela nota do titular não tem nota própria.
            ->reject(fn (Order $pedido) => $this->pack->cobertoPor($pedido) !== null)
            ->map(fn (Order $pedido) => [
                'id' => $pedido->id,
                'texto' => "#{$pedido->id} {$pedido->origin} (".($pedido->invoice ? "nota {$pedido->invoice->status}" : 'sem nota').')',
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, texto: string}> */
    private function canceladasComNota(int $dias): array
    {
        $cobertosPeloMl = MercadoPagoPagamento::query()->where('status_detalhe', 'bpp_covered')->whereNotNull('order_id')->pluck('order_id')->all();
        $valeram = collect(config('nfe.vendas_que_valeram', []))->map(fn ($id) => (int) $id)->all();

        return Invoice::query()
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->where('ambiente', (string) config('nfe.ambiente'))
            ->where('autorizada_em', '>=', now()->subDays($dias))
            // Nota do próprio TikTok: cancelamento e devolução são da plataforma.
            ->where(fn ($q) => $q->whereNull('emitida_por')->orWhere('emitida_por', '!=', Invoice::EMITIDA_TIKTOK))
            ->whereHas('order', fn ($q) => $q
                ->whereNotIn('origin', self::ORIGENS_DOCUMENTO)
                ->whereNotIn('id', array_merge($valeram, $cobertosPeloMl))
                ->where(fn ($q) => $q->where('status', Order::STATUS_CANCELLED)->orWhereNotNull('estornado_em')))
            ->with('order.items')
            ->orderBy('id')
            ->get()
            // Devolução já emitida para todos os itens: resolvida.
            ->filter(fn (Invoice $nota) => $this->devolucoes->impedimento($nota) !== null
                || $this->devolucoes->itensDevolviveis($nota)->sum('disponivel') > 0)
            ->map(function (Invoice $nota) {
                $janela = $nota->janelaDeCancelamento() === Invoice::JANELA_NORMAL ? 'cancela até '.$nota->prazosDeCancelamento()['normal_ate']?->format('d/m H:i') : 'devolução às 08:20';
                $impedimento = $this->devolucoes->impedimento($nota);

                return [
                    'id' => $nota->id,
                    'texto' => "NF-e {$nota->serie}-{$nota->numero} do pedido #{$nota->order_id} ({$janela})".($impedimento ? " ATENÇÃO: {$impedimento}" : ''),
                ];
            })
            ->values()
            ->all();
    }

    /** @return list<array{id: int, texto: string}> */
    private function devolucoesParadas(): array
    {
        $corte = now()->subDays(15);

        return MarketplaceReturn::query()
            ->where('devolucao_fiscal_status', DevolucaoFiscalAutomatica::PREPARADA)
            ->where(fn ($q) => $q->where('opened_at', '<', $corte)->orWhere(fn ($q) => $q->whereNull('opened_at')->where('created_at', '<', $corte)))
            ->orderBy('id')
            ->get(['id', 'order_id', 'opened_at', 'created_at'])
            ->map(fn (MarketplaceReturn $caso) => [
                'id' => $caso->id,
                'texto' => "caso {$caso->id} do pedido #{$caso->order_id} (aberto ".($caso->opened_at ?? $caso->created_at)?->format('d/m').')',
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, texto: string}> */
    private function ocorrenciasAbertas(): array
    {
        return NumeracaoOcorrencia::query()
            ->where('ambiente', (string) config('nfe.ambiente'))
            ->whereNull('resolvido_em')
            ->orderBy('id')
            ->get()
            ->map(fn (NumeracaoOcorrencia $o) => [
                'id' => $o->id,
                'texto' => "{$o->tipo} série {$o->serie} nº {$o->numero_inicial}".($o->numero_final !== $o->numero_inicial ? " a {$o->numero_final}" : '').($o->order_id ? " (pedido #{$o->order_id})" : ''),
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, texto: string}> */
    private function buracos(): array
    {
        $serie = (int) config('nfe.serie');

        return collect($this->fechamento->buracos($serie, (string) config('nfe.ambiente')))
            ->map(fn ($f) => [
                'id' => $f['inicio'],
                'texto' => "série {$serie} nº {$f['inicio']}".($f['fim'] !== $f['inicio'] ? " a {$f['fim']}" : '')." ({$f['motivo']})",
            ])
            ->values()
            ->all();
    }

    /** @return list<array{id: int, texto: string}> */
    private function notasSemPedido(int $dias): array
    {
        return Invoice::query()
            ->where('ambiente', (string) config('nfe.ambiente'))
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->where(fn ($q) => $q->whereNull('autorizada_em')->orWhere('autorizada_em', '>=', now()->subDays($dias)))
            ->where(fn ($q) => $q->whereNull('order_id')->orWhereDoesntHave('order'))
            ->orderBy('id')
            ->get(['id', 'serie', 'numero', 'chave_acesso', 'destinatario_nome'])
            ->map(function (Invoice $nota) {
                [$serie, $numero] = $this->fechamento->serieENumero($nota);

                return ['id' => $nota->id, 'texto' => "invoice {$nota->id} NF-e {$serie}-{$numero}".($nota->destinatario_nome ? " ({$nota->destinatario_nome})" : '')];
            })
            ->values()
            ->all();
    }
}
