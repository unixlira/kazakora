<?php

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Admin\Support\FluxoDoCheckout;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Support\OrigemVisita;
use App\Support\SiteAtual;
use Illuminate\Support\Carbon;

/**
 * Janela "Jornada" da tela única de Leads (plano do Lira 25/11/2026, §6):
 * dados do cliente, origem completa e a linha do tempo do checkout (a mesma
 * do "Ver fluxo" de hoje, FluxoDoCheckout) até o pedido e o pagamento, com o
 * resumo no topo ("Parou em ..." ou "Pago em ..."). Os eventos guardam só o
 * NOME de cada campo, nunca o que foi digitado.
 */
class JornadaDoLead
{
    public function __construct(private readonly FluxoDoCheckout $fluxo)
    {
    }

    /** @return array<string, mixed> */
    public function montar(LinhaLead $linha): array
    {
        $modelo = $linha->lead ?? $linha->checkout;
        $fluxo = $modelo ? $this->fluxo->fluxo($modelo) : null;
        $passos = $fluxo['passos'] ?? [];
        $pedido = $linha->pedido;
        $origem = $linha->origem();

        if ($pedido && ! collect($passos)->contains('evento', CheckoutEvento::PEDIDO_CRIADO)) {
            $passos[] = $this->passo($pedido->created_at, CheckoutEvento::PEDIDO_CRIADO, 'Pedido criado', 'comprou');
        }
        foreach ($passos as &$passo) {
            if ($pedido && $passo['evento'] === CheckoutEvento::PEDIDO_CRIADO) {
                $passo['pedido'] = ['id' => $pedido->id, 'numero' => $pedido->external_order_id];
            }
        }
        unset($passo);

        $pagoEm = $this->pagoEm($linha);
        if ($pagoEm) {
            $passos[] = $this->passo($pagoEm, 'pagamento_confirmado', 'Pagamento confirmado', 'comprou');
        }
        usort($passos, fn ($a, $b) => strcmp((string) $a['hora'], (string) $b['hora']));

        return [
            'chave' => $linha->chave(),
            'cliente' => [
                'nome' => $linha->nome(),
                'email' => $linha->email(),
                'telefone' => $linha->telefone(),
                'cpf' => $linha->cpf(),
                'cep' => $linha->cep(),
                'endereco' => $pedido ? collect([$pedido->shipping_city ?? null, $pedido->shipping_state ?? null])->filter()->implode(' / ') ?: null : null,
                'loja' => SiteAtual::SITES[$linha->site()] ?? $linha->site(),
                'sessao_criada_em' => $linha->criadoEm()?->toIso8601String(),
            ],
            'origem' => [
                ...$origem,
                'rotulo' => $origem['origem'] ? (OrigemVisita::ROTULOS[$origem['origem']] ?? $origem['origem']) : null,
            ],
            'situacao' => $linha->situacao,
            'resumo' => $this->resumo($linha, $fluxo, $pagoEm),
            'pedido' => $pedido ? ['id' => $pedido->id, 'numero' => $pedido->external_order_id] : null,
            'tem_registro' => (bool) ($fluxo['tem_registro'] ?? false),
            'sem_visitante' => (bool) ($fluxo['sem_visitante'] ?? true),
            'passos' => $passos,
            'tempos' => $fluxo['resumo'] ?? null,
        ];
    }

    /** @return array{pago: bool, texto: string} */
    private function resumo(LinhaLead $linha, ?array $fluxo, ?Carbon $pagoEm): array
    {
        $codigo = $linha->situacao['codigo'] ?? null;
        if ($codigo === LeadStatusResolver::PAGO) {
            return ['pago' => true, 'texto' => 'Pago'.($pagoEm ? ' em '.$pagoEm->copy()->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') : '')];
        }
        if ($codigo === LeadStatusResolver::AGUARDANDO) {
            return ['pago' => false, 'texto' => 'Pedido gerado, aguardando pagamento'];
        }

        $parou = $fluxo['parou']['texto'] ?? null;

        return ['pago' => false, 'texto' => $parou ? 'Parou em: '.preg_replace('/^Parou (n[oa]s? |ao )/u', '', $parou) : 'Parou em: sem registro de passos'];
    }

    private function pagoEm(LinhaLead $linha): ?Carbon
    {
        if (($linha->situacao['codigo'] ?? null) !== LeadStatusResolver::PAGO) {
            return null;
        }

        return $linha->lead?->convertido_em ?? $linha->checkout?->convertido_em ?? $linha->pedido?->updated_at;
    }

    private function passo(?Carbon $hora, string $evento, string $titulo, string $etapa): array
    {
        return ['hora' => $hora?->toIso8601String(), 'evento' => $evento, 'campo' => null, 'etapa' => $etapa, 'titulo' => $titulo, 'detalhe' => null, 'vezes' => 1];
    }
}
