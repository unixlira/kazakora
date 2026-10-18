<?php

namespace App\Modules\Suporte\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\CancelamentoDoPedido;
use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Models\MensagemSuporte;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manuela no chat de suporte da loja (pedido 2026-10-11): responde primeiro,
 * com o contexto real do pedido, e abre o chamado quando o caso é sério
 * (troca, devolução, reembolso, defeito, atraso, reclamação, pedido de
 * humano). Nunca promete reembolso/troca — isso é do atendente. Cancelamento
 * que o cliente ainda pode fazer sozinho vai para o botão da página (2026-10-18).
 */
class ManuelaSuporte
{
    public const SEM_IA = 'Recebi sua mensagem! 💛 Já encaminhei para a nossa equipe, que vai te responder por aqui em breve.';

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function responder(Chamado $chamado): void
    {
        $chamado->loadMissing('order.items', 'order.invoice', 'order.payments', 'order.channelShipment', 'order.latestCorreiosPrePostagem', 'user');

        if ($chamado->humano_ativo) {
            return;
        }

        $resposta = $this->cancelamentoPeloBotao($chamado) ?? $this->perguntarAoGemini($chamado);

        $chamado->registrar(MensagemSuporte::MANUELA, $resposta['resposta']);

        if ($resposta['abrir_chamado'] && $chamado->status === Chamado::CONVERSA) {
            $chamado->forceFill([
                'status' => Chamado::ABERTO,
                'assunto' => mb_substr($resposta['assunto'] ?: 'Atendimento do pedido '.($chamado->order?->numero ?? ''), 0, 160),
                'prioridade' => in_array($resposta['prioridade'], Chamado::PRIORIDADES, true) ? $resposta['prioridade'] : 'normal',
                'resumo' => $resposta['resumo'] ?: null,
                'aberto_por' => 'manuela',
                'aberto_em' => now(),
            ])->save();

            $chamado->registrar(MensagemSuporte::SISTEMA, "Chamado {$chamado->numero} aberto. Nossa equipe vai continuar o seu atendimento por aqui.");
        }
    }

    /**
     * Cliente pedindo para cancelar compra que ele mesmo pode cancelar
     * (Lira, 2026-10-18): resposta fixa apontando o botão, sem chamado e
     * sem depender da IA (sem Gemini, a resposta padrão abria chamado).
     *
     * @return array{resposta: string, abrir_chamado: bool, assunto: ?string, prioridade: ?string, resumo: ?string}|null
     */
    private function cancelamentoPeloBotao(Chamado $chamado): ?array
    {
        $ultima = $chamado->mensagens()->where('autor', MensagemSuporte::CLIENTE)->latest('id')->value('conteudo');

        if (! $chamado->order || ! preg_match('/cancel/iu', (string) $ultima)
            || app(CancelamentoDoPedido::class)->bloqueioDoCliente($chamado->order) !== null) {
            return null;
        }

        return [
            'resposta' => 'Você cancela na hora, sem esperar a equipe: toque no botão "Cancelar Pedido" no topo desta página, escolha o motivo e confirme. 💛 Se já pagou, o valor volta pela mesma forma de pagamento e a confirmação chega no seu e-mail.',
            'abrir_chamado' => false,
            'assunto' => null,
            'prioridade' => null,
            'resumo' => null,
        ];
    }

    /** @return array{resposta: string, abrir_chamado: bool, assunto: ?string, prioridade: ?string, resumo: ?string} */
    private function perguntarAoGemini(Chamado $chamado): array
    {
        $semIa = ['resposta' => self::SEM_IA, 'abrir_chamado' => true, 'assunto' => null, 'prioridade' => 'normal', 'resumo' => null];

        if (! $this->gemini->isConfigured()) {
            return $semIa;
        }

        $historico = $chamado->mensagens()->latest('id')->limit(20)->get()->reverse()
            ->filter(fn (MensagemSuporte $m) => in_array($m->autor, [MensagemSuporte::CLIENTE, MensagemSuporte::MANUELA, MensagemSuporte::ATENDENTE], true))
            ->map(fn (MensagemSuporte $m) => [
                'role' => $m->autor === MensagemSuporte::CLIENTE ? 'user' : 'model',
                'parts' => [['text' => $m->conteudo]],
            ])->values()->all();

        if (! $historico || $historico[array_key_last($historico)]['role'] !== 'user') {
            return $semIa;
        }

        try {
            $texto = $this->gemini->generate(
                (string) config('services.gemini.chat_model'),
                $historico,
                $this->instrucoes($chamado),
                1024,
                GeminiUsageLog::PURPOSE_REPLY,
            );

            $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', trim($texto))), true);
            if (! is_array($json) || blank($json['resposta'] ?? null)) {
                return $semIa;
            }

            return [
                'resposta' => mb_substr((string) $json['resposta'], 0, 2000),
                'abrir_chamado' => (bool) ($json['abrir_chamado'] ?? false),
                'assunto' => $json['assunto'] ?? null,
                'prioridade' => $json['prioridade'] ?? 'normal',
                'resumo' => $json['resumo'] ?? null,
            ];
        } catch (Throwable $erro) {
            Log::warning('suporte.manuela_falhou', ['chamado' => $chamado->id, 'erro' => $erro->getMessage()]);

            return $semIa;
        }
    }

    private function instrucoes(Chamado $chamado): string
    {
        $nome = strtok((string) $chamado->user?->name, ' ') ?: 'cliente';

        return <<<TXT
Você é a Manuela, atendente da loja KazaKora (e-commerce). Fale em português do Brasil, com simpatia, frases curtas e no máximo 4 linhas. O cliente se chama {$nome}.
Use SOMENTE as informações do pedido abaixo; se não souber, diga que vai verificar com a equipe. Nunca invente prazo, código ou valor.
CANCELAMENTO: {$this->orientacaoDeCancelamento($chamado->order)}
Você NÃO pode prometer nem executar troca, devolução, reembolso ou estorno — nesses casos explique que vai abrir um chamado para a equipe resolver.
Abra chamado (abrir_chamado = true) quando: troca, devolução, reembolso, estorno, produto com defeito ou errado, pedido não chegou ou atrasou muito, reclamação séria, Procon/advogado, ou quando o cliente pedir um atendente humano. Para dúvidas simples (status, prazo, nota fiscal, código de rastreio), responda e não abra chamado.
Responda APENAS com um JSON, sem texto fora dele:
{"resposta": "texto para o cliente", "abrir_chamado": true|false, "assunto": "título curto do chamado", "prioridade": "baixa|normal|alta|urgente", "resumo": "resumo do problema para a equipe"}

PEDIDO DO CLIENTE:
{$this->contexto($chamado->order)}
TXT;
    }

    /**
     * Cancelamento sem intermediário (Lira, 2026-10-18): se a compra ainda
     * pode ser cancelada pelo cliente, a Manuela manda usar o botão
     * "Cancelar pedido" da página da compra, que cancela na hora. Chamado só
     * quando o botão não serve mais (compra já enviada ou de marketplace).
     */
    private function orientacaoDeCancelamento(?Order $order): string
    {
        $bloqueio = $order ? app(CancelamentoDoPedido::class)->bloqueioDoCliente($order) : 'Nenhum pedido vinculado.';

        if ($bloqueio === null) {
            return 'esta compra ainda pode ser cancelada pelo próprio cliente, na hora e sem atendente. Se ele quiser cancelar, explique que é só clicar no botão "Cancelar Pedido" no topo desta mesma página da compra, escolher o motivo e enviar; o cancelamento é imediato e o valor pago volta pela mesma forma de pagamento. NÃO abra chamado para isso.';
        }

        return "o botão de cancelar não está disponível para esta compra ({$bloqueio}). Se o cliente quiser cancelar, não prometa o cancelamento: diga que vai abrir um chamado para a equipe verificar e abra (abrir_chamado = true).";
    }

    public function contexto(?Order $order): string
    {
        if (! $order) {
            return 'Nenhum pedido vinculado a esta conversa.';
        }

        $status = [
            Order::STATUS_AWAITING_PAYMENT => 'aguardando pagamento',
            Order::STATUS_PAID => 'pago, em preparação',
            Order::STATUS_SHIPPED => 'enviado',
            Order::STATUS_COMPLETED => 'entregue',
            Order::STATUS_CANCELLED => 'cancelado',
        ][$order->status] ?? $order->status;

        $itens = $order->items->map(fn ($i) => "- {$i->quantity}x {$i->product_name}")->implode("\n");
        $pagamento = $order->payments->last();
        $rastreio = $order->trackingCode();

        return implode("\n", array_filter([
            "Número do pedido: {$order->numero}",
            'Feito em: '.$order->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            "Situação: {$status}",
            'Total: R$ '.number_format((float) $order->total, 2, ',', '.'),
            "Itens:\n{$itens}",
            $pagamento ? 'Pagamento: '.($pagamento->method_type === 'pix' ? 'Pix' : 'cartão').' ('.$pagamento->status.')' : null,
            $order->invoice ? "Nota fiscal: nº {$order->invoice->numero} ({$order->invoice->status})" : 'Nota fiscal: ainda não emitida',
            $rastreio ? "Código de rastreio: {$rastreio}".($order->shipping_carrier_name ? " ({$order->shipping_carrier_name})" : '') : 'Código de rastreio: ainda não gerado',
            'Entrega para: '.trim(($order->shipping_city ?? '').'/'.($order->shipping_state ?? ''), '/'),
            'Frete grátis em todos os pedidos. Prazo de envio: até 1 dia útil após o pagamento.',
        ]));
    }
}
