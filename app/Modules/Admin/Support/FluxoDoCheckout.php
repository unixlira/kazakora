<?php

namespace App\Modules\Admin\Support;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Support\OrigemVisita;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Support\SiteAtual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Passo a passo do checkout de um lead (pedido do Lira 2026-11-23): em
 * Admin > Carrinhos abandonados e Admin > Leads, em que ponto a pessoa parou
 * e a linha do tempo do que ela fez, campo a campo, com o horário.
 *
 * Fonte: checkout_eventos, ligado ao lead pelo visitor_id na janela do lead
 * (criado_em - HORAS_ANTES até atualizado_em + HORAS_DEPOIS). Os eventos só
 * têm o NOME do campo, nunca o que foi digitado; aqui também nada do lead
 * (nome, CPF, e-mail, telefone) entra na resposta.
 *
 * Vale para os dois modelos: LeadCheckout (lead_checkout) e Lead (leads).
 */
class FluxoDoCheckout
{
    public const HORAS_ANTES = 3;

    public const HORAS_DEPOIS = 3;

    /** Etapas em ordem: a mais adiantada que a pessoa alcançou é onde ela parou. */
    public const ETAPAS = [
        'carrinho' => 0,
        'abertura' => 1,
        'identificacao' => 2,
        'entrega' => 3,
        'pagamento' => 4,
        'finalizar' => 5,
        'comprou' => 6,
    ];

    private const CAMPOS_IDENTIFICACAO = ['nome', 'email', 'cpf', 'celular', 'telefone'];

    private const CAMPOS_ENTREGA = ['cep', 'endereco', 'numero', 'complemento', 'bairro', 'cidade', 'uf'];

    private const NOME_CAMPO = [
        'nome' => 'nome', 'email' => 'e-mail', 'cpf' => 'CPF', 'celular' => 'celular', 'telefone' => 'celular',
        'cep' => 'CEP', 'endereco' => 'endereço', 'numero' => 'número', 'complemento' => 'complemento',
        'bairro' => 'bairro', 'cidade' => 'cidade', 'uf' => 'estado', 'cupom' => 'cupom',
        'entrega' => 'entrega', 'pagamento' => 'pagamento', 'cartao' => 'cartão', 'carrinho' => 'carrinho', 'outro' => 'outra parte da tela',
    ];

    private const NOME_PAGAMENTO = ['pix' => 'Pix', 'boleto' => 'boleto', 'cartao' => 'cartão'];

    private const COLUNAS = ['id', 'site', 'visitor_id', 'evento', 'campo', 'valor', 'rolagem', 'dispositivo', 'origem', 'created_at'];

    /**
     * Linha do tempo completa de um lead (endpoint da janela "Ver fluxo").
     *
     * @return array{tem_registro: bool, passos: list<array<string, mixed>>, parou: array<string, mixed>, resumo: array<string, mixed>}
     */
    public function fluxo(Lead|LeadCheckout $lead): array
    {
        $eventos = $this->eventosDe($lead);
        $parou = $this->parou($lead, $eventos);
        $produtos = $this->nomesDosProdutos($eventos);
        $utm = collect([$lead->utm_source, $lead->utm_medium, $lead->utm_campaign])->filter()->implode(' / ');

        $passos = [];
        $anterior = null;
        foreach ($eventos as $e) {
            $chave = $e->evento.'|'.$e->campo.'|'.$e->valor;
            // Mesmo evento repetido em seguida (foco no mesmo campo, por exemplo): uma linha só.
            if ($chave === $anterior && $e->evento !== 'abriu') {
                $passos[count($passos) - 1]['vezes']++;

                continue;
            }
            $anterior = $chave;
            $passos[] = [
                'hora' => $e->created_at?->toIso8601String(),
                'evento' => $e->evento,
                'campo' => $e->campo !== null && $e->evento !== CheckoutEvento::PRODUTO_CARRINHO && $e->evento !== 'produto_comprar' ? $e->campo : null,
                'etapa' => $this->etapaDoEvento($e),
                'titulo' => $this->titulo($e, $produtos),
                'detalhe' => $this->detalhe($e, $utm),
                'vezes' => 1,
            ];
        }

        $inicio = $eventos->first()?->created_at;
        $fim = $eventos->last()?->created_at;

        return [
            'tem_registro' => $eventos->isNotEmpty(),
            'sem_visitante' => blank($lead->visitor_id),
            'passos' => $passos,
            'parou' => $parou,
            'resumo' => [
                'inicio' => $inicio?->toIso8601String(),
                'fim' => $fim?->toIso8601String(),
                'duracao_segundos' => $inicio && $fim ? (int) $inicio->diffInSeconds($fim, true) : null,
                'segundos_ativos' => (int) $eventos->where('evento', 'saiu')->sum('valor') ?: null,
                'aberturas' => $eventos->where('evento', 'abriu')->count(),
            ],
        ];
    }

    /**
     * Onde cada lead da página parou, com uma consulta só (lista das telas).
     *
     * @param  iterable<Lead|LeadCheckout>  $leads
     * @return array<int, array<string, mixed>> id do lead => parou
     */
    public function paradas(iterable $leads): array
    {
        $leads = collect($leads);
        $comVisitante = $leads->filter(fn ($l) => filled($l->visitor_id) && $l->created_at);
        $eventos = collect();
        if ($comVisitante->isNotEmpty()) {
            $eventos = CheckoutEvento::query()
                ->whereIn('visitor_id', $comVisitante->pluck('visitor_id')->unique()->values())
                ->whereBetween('created_at', [
                    $comVisitante->min('created_at')->copy()->subHours(self::HORAS_ANTES),
                    $comVisitante->max(fn ($l) => $l->updated_at ?? $l->created_at)->copy()->addHours(self::HORAS_DEPOIS),
                ])
                ->orderBy('created_at')->orderBy('id')
                ->get(self::COLUNAS)
                ->groupBy('visitor_id');
        }

        return $leads->mapWithKeys(fn ($lead) => [
            $lead->id => $this->parou($lead, $this->filtrar($lead, $eventos->get($lead->visitor_id, collect()))),
        ])->all();
    }

    /** Eventos do visitante do lead, na janela do lead e do mesmo site, em ordem. */
    public function eventosDe(Lead|LeadCheckout $lead): Collection
    {
        if (blank($lead->visitor_id) || ! $lead->created_at) {
            return collect();
        }

        [$de, $ate] = $this->janela($lead);

        $eventos = CheckoutEvento::query()
            ->where('visitor_id', $lead->visitor_id)
            ->whereBetween('created_at', [$de, $ate])
            ->orderBy('created_at')->orderBy('id')
            ->get(self::COLUNAS);

        return $this->filtrar($lead, $eventos);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function janela(Model $lead): array
    {
        return [
            $lead->created_at->copy()->subHours(self::HORAS_ANTES),
            ($lead->updated_at ?? $lead->created_at)->copy()->addHours(self::HORAS_DEPOIS),
        ];
    }

    /** Mesmo site (site nulo em checkout_eventos = loja KazaKora) e dentro da janela. */
    private function filtrar(Model $lead, Collection $eventos): Collection
    {
        [$de, $ate] = $this->janela($lead);
        $site = $lead->site ?: SiteAtual::KAZAKORA;

        return $eventos
            ->filter(fn ($e) => ($e->site ?: SiteAtual::KAZAKORA) === $site
                && $e->created_at && $e->created_at->between($de, $ate))
            ->values();
    }

    /**
     * Etapa mais adiantada que a pessoa alcançou, com o detalhe do último
     * passo nela. Sem eventos: usa o que o próprio lead guardou.
     *
     * @return array{etapa: string, texto: string, hora: ?string, tem_registro: bool}
     */
    public function parou(Lead|LeadCheckout $lead, Collection $eventos): array
    {
        $convertido = $lead->status === 'convertido';

        if ($eventos->isEmpty()) {
            return $this->parouSemRegistro($lead, $convertido);
        }

        $maior = -1;
        $ultimo = null;
        foreach ($eventos as $e) {
            $etapa = $this->etapaDoEvento($e);
            if ($etapa === null) {
                continue;
            }
            $nivel = self::ETAPAS[$etapa];
            if ($nivel >= $maior) {
                $maior = $nivel;
                $ultimo = $e;
            }
        }

        $hora = $eventos->last()->created_at?->toIso8601String();

        if ($convertido || $maior === self::ETAPAS['comprou']) {
            return ['etapa' => 'comprou', 'texto' => 'Comprou (virou pedido)', 'hora' => $hora, 'tem_registro' => true];
        }

        if ($ultimo === null) {
            return ['etapa' => 'abertura', 'texto' => 'Parou na abertura (só abriu o checkout)', 'hora' => $hora, 'tem_registro' => true];
        }

        $etapa = $this->etapaDoEvento($ultimo);
        // Erro mostrado depois do último passo da etapa: entra no detalhe.
        $erro = $eventos->filter(fn ($e) => $e->evento === 'erro' && $e->created_at->gte($ultimo->created_at) && $e->id !== $ultimo->id)->last();
        $sufixoErro = $erro ? ', deu erro em '.$this->nomeCampo($erro->campo) : '';

        $texto = match ($etapa) {
            'carrinho' => 'Parou no carrinho (não abriu o checkout)',
            'abertura' => 'Parou na abertura ('.$this->detalheAbertura($eventos).')',
            'identificacao' => 'Parou na identificação ('.$this->acaoNoCampo($ultimo).$sufixoErro.')',
            'entrega' => 'Parou na entrega ('.($ultimo->evento === 'cep_respondido' ? 'CEP respondeu, frete calculado' : $this->acaoNoCampo($ultimo)).$sufixoErro.')',
            'pagamento' => 'Parou no pagamento ('.$this->detalhePagamento($eventos, $ultimo).$sufixoErro.')',
            'finalizar' => 'Parou ao finalizar (clicou em Finalizar'.(($p = $this->ultimaForma($eventos)) ? ' com '.$p : '').$sufixoErro.')',
            default => 'Parou no checkout',
        };

        return ['etapa' => $etapa, 'texto' => $texto, 'hora' => $hora, 'tem_registro' => true];
    }

    /** @return array{etapa: string, texto: string, hora: ?string, tem_registro: bool} */
    private function parouSemRegistro(Lead|LeadCheckout $lead, bool $convertido): array
    {
        $hora = $lead->updated_at?->toIso8601String();
        if ($convertido) {
            return ['etapa' => 'comprou', 'texto' => 'Comprou (virou pedido)', 'hora' => $hora, 'tem_registro' => false];
        }

        if ($lead instanceof LeadCheckout) {
            $etapa = $lead->etapa === LeadCheckout::ETAPA_PAGAMENTO ? 'pagamento' : 'entrega';
            $texto = $etapa === 'pagamento' ? 'Parou no pagamento' : 'Parou na entrega';
        } else {
            $campo = $lead->ultimo_campo;
            $etapa = $campo === null ? 'abertura' : (in_array($campo, self::CAMPOS_ENTREGA, true) ? 'entrega' : 'identificacao');
            $texto = match ($etapa) {
                'abertura' => 'Parou na abertura',
                'entrega' => 'Parou na entrega (último campo: '.$this->nomeCampo($campo).')',
                default => 'Parou na identificação (último campo: '.$this->nomeCampo($campo).')',
            };
        }

        return ['etapa' => $etapa, 'texto' => $texto.' · sem registro de passos', 'hora' => $hora, 'tem_registro' => false];
    }

    private function etapaDoEvento(CheckoutEvento $e): ?string
    {
        $campo = (string) $e->campo;

        return match ($e->evento) {
            CheckoutEvento::PRODUTO_CARRINHO, 'produto_comprar' => 'carrinho',
            'abriu', 'viu_formulario', 'rolagem' => 'abertura',
            'foco', 'preencheu' => in_array($campo, self::CAMPOS_IDENTIFICACAO, true) ? 'identificacao'
                : (in_array($campo, self::CAMPOS_ENTREGA, true) ? 'entrega' : null),
            'cep_respondido' => 'entrega',
            'pagamento' => 'pagamento',
            'finalizar' => 'finalizar',
            CheckoutEvento::PEDIDO_CRIADO => 'comprou',
            'erro' => in_array($campo, self::CAMPOS_IDENTIFICACAO, true) ? 'identificacao'
                : (in_array($campo, [...self::CAMPOS_ENTREGA, 'entrega'], true) ? 'entrega'
                : (in_array($campo, ['pagamento', 'cartao'], true) ? 'pagamento' : null)),
            default => null,
        };
    }

    private function titulo(CheckoutEvento $e, array $produtos): string
    {
        return match ($e->evento) {
            CheckoutEvento::PRODUTO_CARRINHO => 'Pôs no carrinho: '.($produtos[(string) $e->campo] ?? 'produto #'.$e->campo),
            'produto_comprar' => 'Clicou em Comprar: '.($produtos[(string) $e->campo] ?? 'produto #'.$e->campo),
            'abriu' => 'Abriu o checkout',
            'viu_formulario' => 'Viu o formulário',
            'rolagem' => 'Rolou a página até '.(int) $e->valor.'%',
            'foco' => 'Clicou no campo '.$this->nomeCampo($e->campo),
            'preencheu' => 'Preencheu '.$this->nomeCampo($e->campo),
            'cep_respondido' => 'O CEP respondeu (frete calculado)',
            'pagamento' => 'Escolheu pagamento: '.(self::NOME_PAGAMENTO[$e->campo] ?? (string) $e->campo),
            'finalizar' => 'Clicou em Finalizar',
            'erro' => 'Viu um erro em '.$this->nomeCampo($e->campo),
            // Botão do cupom (2026-11-23): o código digitado nunca é gravado.
            'cupom_clicou' => 'Clicou em Aplicar cupom',
            'cupom_aplicado' => 'Cupom aplicado',
            'cupom_erro' => 'Cupom deu erro',
            'saiu' => 'Saiu do checkout',
            CheckoutEvento::PEDIDO_CRIADO => 'Pedido criado',
            default => (string) $e->evento,
        };
    }

    private function detalhe(CheckoutEvento $e, string $utm): ?string
    {
        if ($e->evento === 'abriu') {
            $partes = array_filter([
                $e->origem ? 'Veio de: '.(OrigemVisita::ROTULOS[$e->origem] ?? $e->origem) : 'Origem não registrada',
                $utm !== '' ? 'utm: '.$utm : null,
                $e->dispositivo ? 'no '.$e->dispositivo : null,
            ]);

            return implode(' · ', $partes);
        }

        if ($e->evento === 'saiu') {
            return 'Ficou '.$this->duracao((int) $e->valor).($e->rolagem !== null ? ' · rolou até '.(int) $e->rolagem.'%' : '');
        }

        if ($e->evento === 'cep_respondido') {
            return 'O preço do frete não fica registrado no passo a passo.';
        }

        return null;
    }

    private function nomesDosProdutos(Collection $eventos): array
    {
        $ids = $eventos->whereIn('evento', [CheckoutEvento::PRODUTO_CARRINHO, 'produto_comprar'])
            ->pluck('campo')->filter(fn ($id) => ctype_digit((string) $id))->unique()->values();

        return $ids->isEmpty() ? [] : Product::query()->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(fn ($nome, $id) => [(string) $id => $nome])->all();
    }

    private function nomeCampo(?string $campo): string
    {
        return self::NOME_CAMPO[$campo] ?? (string) $campo;
    }

    private function acaoNoCampo(CheckoutEvento $e): string
    {
        $campo = $this->nomeCampo($e->campo);

        return match ($e->evento) {
            'preencheu' => $campo.' preenchido',
            'foco' => 'clicou em '.$campo,
            'erro' => 'deu erro em '.$campo,
            default => $campo,
        };
    }

    private function detalheAbertura(Collection $eventos): string
    {
        $rolagem = (int) $eventos->where('evento', 'rolagem')->max('valor');
        if ($rolagem > 0) {
            return 'abriu e rolou até '.$rolagem.'%, sem digitar';
        }

        return $eventos->contains('evento', 'viu_formulario') ? 'viu o formulário, sem digitar' : 'só abriu o checkout';
    }

    private function detalhePagamento(Collection $eventos, CheckoutEvento $ultimo): string
    {
        $forma = $this->ultimaForma($eventos);
        if ($ultimo->evento === 'erro') {
            return ($forma ? 'escolheu '.$forma.', ' : '').'deu erro em '.$this->nomeCampo($ultimo->campo);
        }

        return $forma ? 'escolheu '.$forma : 'chegou no pagamento';
    }

    private function ultimaForma(Collection $eventos): ?string
    {
        $campo = $eventos->where('evento', 'pagamento')->last()?->campo;

        return $campo ? (self::NOME_PAGAMENTO[$campo] ?? $campo) : null;
    }

    private function duracao(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos.' s';
        }
        $min = intdiv($segundos, 60);
        if ($min < 60) {
            return $min.' min '.str_pad((string) ($segundos % 60), 2, '0', STR_PAD_LEFT).' s';
        }

        return intdiv($min, 60).' h '.str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT).' min';
    }
}
