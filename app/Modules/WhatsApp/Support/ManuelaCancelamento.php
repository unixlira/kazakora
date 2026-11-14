<?php

namespace App\Modules\WhatsApp\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;

/**
 * Cancelamento na conversa da Manuela (pedido do Lira 2026-11-14): "a Manuela
 * não cancela pedido, mas tem que conversar e entender o motivo e deixar as
 * informações preparadas para um humano chegar e já saber como lidar".
 *
 * Achado do teste dele: o cliente escreveu "me arrependi da compra, quero
 * cancelar" e ela pediu o número do pedido (que a conversa já tinha, acabara
 * de confirmar), disse que uma pessoa ia acompanhar e parou.
 *
 * Aqui ficam as duas peças que entram no prompt: os pedidos do cliente desta
 * conversa (para ela não perguntar o número) e a regra da conversa de
 * cancelamento, que termina numa FICHA para o atendente. A ficha vem na
 * resposta depois de FICHA_TAG, nunca vai ao cliente (ManuelaAgentClient) e
 * fica na conversa (metadata.ficha_atendente), à vista em WhatsApp > Conversas.
 */
class ManuelaCancelamento
{
    public const FICHA_TAG = '[FICHA]';

    public const FICHA_KEY = 'ficha_atendente';

    private const MAX_PEDIDOS = 3;

    private const SITUACOES = [
        Order::STATUS_PENDING => 'aguardando pagamento',
        Order::STATUS_AWAITING_PAYMENT => 'aguardando pagamento',
        Order::STATUS_PAID => 'pago, ainda não enviado',
        Order::STATUS_SHIPPED => 'enviado',
        Order::STATUS_COMPLETED => 'entregue',
        Order::STATUS_CANCELLED => 'cancelado',
    ];

    public function __construct(private readonly ClienteDaConversa $clientes) {}

    /** Pedidos do cliente desta conversa, para o prompt. Sem valor em dinheiro (ManuelaSemPreco). */
    public function pedidos(?WhatsAppConversation $conversation): string
    {
        $pedidos = $conversation?->exists ? ($this->clientes->identificar($conversation)['pedidos'] ?? []) : [];

        if ($pedidos === []) {
            return 'Pedidos deste cliente: nenhum encontrado pelo telefone desta conversa.';
        }

        $ids = array_slice(array_column($pedidos, 'id'), 0, self::MAX_PEDIDOS);
        $orders = Order::query()->with('items')->whereIn('id', $ids)->get()->sortBy(fn (Order $o) => array_search($o->id, $ids, true));

        $linhas = $orders->map(function (Order $o) {
            $itens = $o->items->map(fn ($i) => trim(((int) $i->quantity > 1 ? $i->quantity.'x ' : '').$i->product_name))->filter()->implode('; ');

            return sprintf(
                '- Pedido %s, feito em %s, situação: %s. Itens: %s.',
                $o->numero,
                $o->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y') ?? 'data não informada',
                self::SITUACOES[$o->status] ?? (string) $o->status,
                $itens !== '' ? $itens : 'não informados',
            );
        })->implode("\n");

        return "Pedidos deste cliente (do mais recente para o mais antigo; é daqui que sai o número, não pergunte o que já está aqui):\n".$linhas;
    }

    /** Regra da conversa de cancelamento, igual nas duas lojas. */
    public static function regra(string $humano): string
    {
        $ficha = self::FICHA_TAG;

        return <<<REGRA
Cancelamento, arrependimento, devolução ou pedido do dinheiro de volta (regra do Lira, 14/11/2026):
- Você não cancela e não promete cancelamento, reembolso nem prazo: quem conclui é uma pessoa do time. O seu trabalho é conversar, entender o caso e deixar tudo anotado para essa pessoa já chegar sabendo como lidar.
- Comece toda resposta deste assunto com {$humano}.
- Número do pedido: se ele está em "Pedidos deste cliente", não pergunte. Com um pedido só, cite o número e os itens e confirme que é esse. Com mais de um, pergunte qual deles citando os itens. Só peça o número se a lista estiver vazia.
- Depois pergunte o motivo, com interesse e sem julgar, uma pergunta por mensagem. O que a pessoa do time precisa saber: por que o cliente quer cancelar; se o produto já chegou (só pergunte quando a situação for enviado ou entregue); e o que ele prefere, o dinheiro de volta ou outra solução, como troca ou outro modelo (só quando fizer sentido pelo motivo). No máximo três perguntas no total; não repita pergunta já respondida.
- Se o motivo for uma dúvida que você consegue esclarecer com segurança (como usar, prazo que já está no pedido), esclareça e pergunte uma única vez se ele ainda quer cancelar. Não insista, não discuta e não tente segurar o cliente.
- Quando já souber o motivo e o que o cliente quer, diga que deixou tudo anotado e que uma pessoa do time vai concluir. Não peça mais nada depois disso.
- FICHA: em toda resposta deste assunto, depois do texto para o cliente, pule uma linha e escreva {$ficha} seguido da ficha para o atendente, numa linha só, neste formato: "Pedido: ...; Situação: ...; Motivo: ... (com as palavras do cliente); Produto chegou: sim, não ou não informado; Cliente quer: ...; Falta perguntar: ...; Observações: ...". Escreva "não informado" no que ainda não souber. O cliente não vê a ficha; não cite a ficha no texto para ele.
REGRA;
    }

    /**
     * Separa o texto do cliente da ficha do atendente.
     *
     * @return array{0: string, 1: string} texto para o cliente, ficha (vazia se não veio)
     */
    public static function separar(string $resposta): array
    {
        $pos = mb_stripos($resposta, self::FICHA_TAG);

        if ($pos === false) {
            return [$resposta, ''];
        }

        $ficha = trim(preg_replace('/\s+/u', ' ', mb_substr($resposta, $pos + mb_strlen(self::FICHA_TAG))) ?? '', " \t\n\r:-");

        return [trim(mb_substr($resposta, 0, $pos)), $ficha];
    }

    /** Guarda a ficha na conversa. Devolve true quando é a primeira ficha (hora de avisar o time). */
    public static function guardar(WhatsAppConversation $conversation, string $ficha): bool
    {
        $metadata = $conversation->metadata ?? [];
        $primeira = blank($metadata[self::FICHA_KEY]['texto'] ?? null);
        $metadata[self::FICHA_KEY] = ['texto' => mb_substr($ficha, 0, 1500), 'em' => now()->toISOString()];
        $conversation->update(['metadata' => $metadata]);

        return $primeira;
    }
}
