<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\NumeracaoOcorrencia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Fechamento fiscal do mês pro contador (pedido do Nelson, Contabilidade
 * Galícia, 2026-10-08): quais séries foram usadas, notas autorizadas,
 * canceladas (no prazo e fora dele, com a multa estimada), devoluções,
 * números pulados por duplicidade, números sem nota (a inutilizar) e os
 * XMLs que faltam no sistema. Vira e-mail todo dia 1º com CSV e ZIP.
 *
 * Série e número vêm da CHAVE de acesso quando ela existe: o importador do
 * Bling já sobrescreveu a coluna com a numeração interna dele (ver
 * InvoiceService::proximoNumero()), a chave é o que a SEFAZ registrou.
 */
class FechamentoFiscalService
{
    /** Status que consomem número na SEFAZ (a nota existe lá). */
    private const STATUS_COM_NUMERO = [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_CANCELLED, Invoice::STATUS_DENIED, Invoice::STATUS_EXTERNAL];

    /** Operações fiscais que são devolução (de venda = entrada, de compra = saída). */
    private const DEVOLUCOES = ['sales_return', 'purchase_return'];

    public function __construct(private readonly UfespService $ufesp) {}

    /** @return array<string, mixed> */
    public function gerar(Carbon $mes): array
    {
        $inicio = $mes->copy()->startOfMonth();
        $fim = $mes->copy()->endOfMonth();
        $ambiente = (string) config('nfe.ambiente');

        $notas = Invoice::query()
            ->with('order:id,origin,external_order_id,fiscal_operation_type')
            ->where('ambiente', $ambiente)
            ->where(fn ($q) => $q->whereBetween('autorizada_em', [$inicio, $fim])
                ->orWhere(fn ($q) => $q->whereNull('autorizada_em')->whereBetween('created_at', [$inicio, $fim])))
            ->orderBy('autorizada_em')
            ->orderBy('id')
            ->get()
            ->map(fn (Invoice $nota) => $this->linha($nota));

        $comNumero = $notas->filter(fn ($n) => in_array($n['status'], self::STATUS_COM_NUMERO, true));
        $canceladas = $notas->where('status', Invoice::STATUS_CANCELLED)->values();
        $foraDoPrazo = $canceladas->where('fora_do_prazo', true);

        $ocorrencias = NumeracaoOcorrencia::query()
            ->where('ambiente', $ambiente)
            ->whereBetween('created_at', [$inicio, $fim])
            ->with('order:id,origin,external_order_id')
            ->orderBy('serie')->orderBy('numero_inicial')
            ->get();

        return [
            'mes' => $inicio->format('Y-m'),
            'mes_extenso' => ucfirst($inicio->locale('pt_BR')->translatedFormat('F/Y')),
            'ambiente' => $ambiente,
            'serie_kazakora' => (int) config('nfe.serie'),
            'gerado_em' => now()->format('d/m/Y H:i'),
            'ufesp' => ['ano' => (int) $inicio->year, 'valor' => $this->ufesp->valor((int) $inicio->year), ...($this->ufesp->registro((int) $inicio->year) ?? [])],
            'series' => $this->porSerie($comNumero),
            'totais' => [
                'autorizadas' => $comNumero->whereIn('status', [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL])->count(),
                'valor_autorizado' => round($comNumero->whereIn('status', [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL])->sum('valor'), 2),
                'canceladas' => $canceladas->count(),
                'canceladas_fora_do_prazo' => $foraDoPrazo->count(),
                'multa_estimada' => round($foraDoPrazo->sum('multa'), 2),
                'devolucoes' => $comNumero->where('devolucao', true)->count(),
                'sem_xml' => $comNumero->where('tem_xml', false)->count(),
                'rejeitadas_ou_paradas' => $notas->whereNotIn('status', self::STATUS_COM_NUMERO)->count(),
            ],
            'canceladas' => $canceladas->all(),
            'devolucoes' => $comNumero->where('devolucao', true)->values()->all(),
            'sem_xml' => $comNumero->where('tem_xml', false)->values()->all(),
            'duplicidades' => $ocorrencias->where('tipo', NumeracaoOcorrencia::TIPO_DUPLICIDADE)->map(fn ($o) => $this->ocorrencia($o))->values()->all(),
            'inutilizacoes' => $ocorrencias->where('tipo', NumeracaoOcorrencia::TIPO_INUTILIZACAO)->map(fn ($o) => $this->ocorrencia($o))->values()->all(),
            'buracos' => $this->buracos((int) config('nfe.serie'), $ambiente),
            'notas' => $notas->all(),
        ];
    }

    /**
     * Números da série que não viraram nota na SEFAZ e ainda não foram
     * inutilizados: sem nenhum registro no sistema, ou reservados por uma
     * nota que foi rejeitada e cujo pedido já foi cancelado (não vai ser
     * reenviada). Agrupados em faixas contínuas, que é como a SEFAZ
     * inutiliza. Nota rejeitada/pendente de pedido ainda ativo NÃO entra:
     * o número dela continua reservado pra reenvio.
     *
     * @return list<array{inicio: int, fim: int, quantidade: int, motivo: string}>
     */
    public function buracos(int $serie, string $ambiente): array
    {
        $usados = [];
        $abandonados = [];

        Invoice::query()
            ->with('order:id,status')
            ->where('ambiente', $ambiente)
            ->get(['id', 'order_id', 'serie', 'numero', 'chave_acesso', 'status', 'updated_at'])
            ->each(function (Invoice $nota) use ($serie, &$usados, &$abandonados) {
                [$serieReal, $numero] = $this->serieENumero($nota);

                if ($serieReal !== $serie || ! $numero) {
                    return;
                }

                $parado = in_array($nota->status, [Invoice::STATUS_REJECTED, Invoice::STATUS_ERROR], true)
                    && $nota->updated_at?->lt(now()->subDay())
                    && (! $nota->order || $nota->order->status === Order::STATUS_CANCELLED);

                if ($parado) {
                    $abandonados[$numero] = true;
                } else {
                    $usados[$numero] = true;
                }
            });

        NumeracaoOcorrencia::query()
            ->where('ambiente', $ambiente)
            ->where('serie', $serie)
            ->get(['tipo', 'numero_inicial', 'numero_final'])
            ->each(function (NumeracaoOcorrencia $o) use (&$usados) {
                // Inutilizado ou consumido por outra chave (duplicidade): não é buraco.
                for ($n = $o->numero_inicial; $n <= $o->numero_final; $n++) {
                    $usados[$n] = true;
                }
            });

        $abandonados = array_diff_key($abandonados, $usados);

        if ($usados === [] && $abandonados === []) {
            return [];
        }

        $primeiro = max((int) config('nfe.numero_inicial') + 1, min(array_keys($usados + $abandonados)));
        $ultimo = max(array_keys($usados + $abandonados));
        $faixas = [];
        $atual = null;

        for ($n = $primeiro; $n <= $ultimo; $n++) {
            $motivo = isset($usados[$n]) ? null : (isset($abandonados[$n]) ? 'nota rejeitada de pedido cancelado' : 'sem nota no sistema');

            if ($motivo !== null && $atual && $atual['fim'] === $n - 1 && $atual['motivo'] === $motivo) {
                $atual['fim'] = $n;

                continue;
            }

            if ($atual) {
                $faixas[] = $atual;
                $atual = null;
            }

            if ($motivo !== null) {
                $atual = ['inicio' => $n, 'fim' => $n, 'motivo' => $motivo];
            }
        }

        if ($atual) {
            $faixas[] = $atual;
        }

        return array_map(fn ($f) => [...$f, 'quantidade' => $f['fim'] - $f['inicio'] + 1], $faixas);
    }

    /** CSV (separador ;, padrão do Excel em pt-BR) com todas as notas do mês. */
    public function csv(array $relatorio): string
    {
        $colunas = ['Série', 'Número', 'Situação', 'Tipo', 'Chave de acesso', 'Autorizada em', 'Cancelada em', 'Horas até cancelar', 'Cancelada fora do prazo', 'Multa estimada', 'Valor', 'Destinatário', 'Pedido', 'Canal', 'XML no sistema'];
        $linhas = [implode(';', $colunas)];

        foreach ($relatorio['notas'] as $n) {
            $linhas[] = implode(';', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [
                $n['serie'], $n['numero'], $n['situacao'], $n['tipo'], $n['chave'] ? "'".$n['chave'] : '',
                $n['autorizada_em'], $n['cancelada_em'], $n['horas_ate_cancelar'], $n['fora_do_prazo'] ? 'sim' : '',
                $n['multa'] ? number_format($n['multa'], 2, ',', '') : '', number_format($n['valor'], 2, ',', ''),
                $n['destinatario'], $n['pedido'], $n['canal'], $n['tem_xml'] ? 'sim' : 'NÃO',
            ]));
        }

        // BOM pro Excel abrir os acentos certo.
        return "\u{FEFF}".implode("\r\n", $linhas)."\r\n";
    }

    /**
     * ZIP com os XMLs do mês (notas, eventos de cancelamento e
     * inutilizações) + o CSV. Fica em storage/app/private/fechamentos.
     */
    public function zip(array $relatorio): string
    {
        $caminho = "fechamentos/fechamento-fiscal-{$relatorio['mes']}.zip";
        $disco = Storage::disk('local');
        $disco->makeDirectory('fechamentos');

        $zip = new ZipArchive;
        if ($zip->open($disco->path($caminho), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não deu pra criar o ZIP do fechamento.');
        }

        $zip->addFromString("notas-{$relatorio['mes']}.csv", $this->csv($relatorio));

        $ids = collect($relatorio['notas'])->pluck('id');
        Invoice::query()->whereIn('id', $ids)->get(['id', 'serie', 'numero', 'chave_acesso', 'xml_path', 'xml_cancelamento_path'])
            ->each(function (Invoice $nota) use ($zip, $disco) {
                [$serie, $numero] = $this->serieENumero($nota);
                $nome = sprintf('%03d-%09d', $serie, $numero);

                if ($nota->xml_path && $disco->exists($nota->xml_path)) {
                    $zip->addFromString("nfe/{$nome}.xml", $disco->get($nota->xml_path));
                }
                if ($nota->xml_cancelamento_path && $disco->exists($nota->xml_cancelamento_path)) {
                    $zip->addFromString("cancelamentos/{$nome}-cancelamento.xml", $disco->get($nota->xml_cancelamento_path));
                }
            });

        foreach ($relatorio['inutilizacoes'] as $inut) {
            if ($inut['xml_path'] && $disco->exists($inut['xml_path'])) {
                $zip->addFromString('inutilizacoes/'.basename($inut['xml_path']), $disco->get($inut['xml_path']));
            }
        }

        $zip->close();

        return $caminho;
    }

    /** @return array{0: int, 1: int} [série, número] reais da nota */
    public function serieENumero(Invoice $nota): array
    {
        $chave = preg_replace('/\D/', '', (string) $nota->chave_acesso);

        return strlen($chave) === 44
            ? [(int) substr($chave, 22, 3), (int) substr($chave, 25, 9)]
            : [(int) $nota->serie, (int) $nota->numero];
    }

    /** @return array<string, mixed> */
    private function linha(Invoice $nota): array
    {
        [$serie, $numero] = $this->serieENumero($nota);
        $horas = $nota->autorizada_em && $nota->cancelada_em ? (int) round($nota->autorizada_em->diffInMinutes($nota->cancelada_em) / 60) : null;
        $limite = (int) config('nfe.cancelamento_horas', 24);
        $foraDoPrazo = $nota->status === Invoice::STATUS_CANCELLED && ($nota->cancelamento_extemporaneo || ($horas !== null && $horas >= $limite));
        $operacao = $nota->order?->fiscal_operation_type;
        $ufesp = $this->ufesp->valor((int) ($nota->cancelada_em ?? $nota->autorizada_em ?? now())->year);

        return [
            'id' => $nota->id,
            'serie' => $serie,
            'numero' => $numero,
            'status' => $nota->status,
            'situacao' => self::SITUACOES[$nota->status] ?? $nota->status,
            'tipo' => $operacao === 'sales_return' ? 'entrada' : 'saida',
            'devolucao' => in_array($operacao, self::DEVOLUCOES, true),
            'operacao' => $operacao,
            'chave' => $nota->chave_acesso,
            'autorizada_em' => $nota->autorizada_em?->format('d/m/Y H:i'),
            'cancelada_em' => $nota->cancelada_em?->format('d/m/Y H:i'),
            'horas_ate_cancelar' => $horas,
            'fora_do_prazo' => $foraDoPrazo,
            'multa' => $foraDoPrazo ? round(max((float) $nota->valor_total * 0.01, 6 * $ufesp), 2) : null,
            'tem_xml_cancelamento' => (bool) $nota->xml_cancelamento_path,
            'motivo' => $nota->status === Invoice::STATUS_CANCELLED ? $nota->motivo_cancelamento : $nota->motivo_rejeicao,
            'valor' => (float) $nota->valor_total,
            'destinatario' => $nota->destinatario_nome,
            'pedido' => $nota->order_id,
            'canal' => $nota->order?->origin,
            'tem_xml' => (bool) $nota->xml_path,
        ];
    }

    private const SITUACOES = [
        Invoice::STATUS_AUTHORIZED => 'Autorizada',
        Invoice::STATUS_CANCELLED => 'Cancelada',
        Invoice::STATUS_DENIED => 'Denegada',
        Invoice::STATUS_EXTERNAL => 'Emitida pelo canal',
        Invoice::STATUS_REJECTED => 'Rejeitada',
        Invoice::STATUS_ERROR => 'Erro',
        Invoice::STATUS_PENDING => 'Pendente',
        Invoice::STATUS_SIGNED => 'Assinada',
        Invoice::STATUS_SENT => 'Enviada',
    ];

    /** @return list<array<string, mixed>> */
    private function porSerie(Collection $notas): array
    {
        return $notas->groupBy('serie')->sortKeys()->map(function (Collection $daSerie, $serie) {
            $validas = $daSerie->whereIn('status', [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL]);

            return [
                'serie' => (int) $serie,
                'emissor' => (int) $serie === (int) config('nfe.serie') ? 'Kazakora' : 'Outro emissor (Bling/canal)',
                'primeiro' => $daSerie->min('numero'),
                'ultimo' => $daSerie->max('numero'),
                'autorizadas' => $validas->count(),
                'valor' => round($validas->sum('valor'), 2),
                'canceladas' => $daSerie->where('status', Invoice::STATUS_CANCELLED)->count(),
                'denegadas' => $daSerie->where('status', Invoice::STATUS_DENIED)->count(),
                'devolucoes' => $daSerie->where('devolucao', true)->count(),
            ];
        })->values()->all();
    }

    /** @return array<string, mixed> */
    private function ocorrencia(NumeracaoOcorrencia $o): array
    {
        return [
            'id' => $o->id,
            'serie' => $o->serie,
            'inicio' => $o->numero_inicial,
            'fim' => $o->numero_final,
            'motivo' => $o->motivo,
            'pedido' => $o->order_id,
            'protocolo' => $o->protocolo,
            'xml_path' => $o->xml_path,
            'resolvido_em' => $o->resolvido_em?->format('d/m/Y H:i'),
            'criado_em' => $o->created_at?->format('d/m/Y H:i'),
        ];
    }
}
