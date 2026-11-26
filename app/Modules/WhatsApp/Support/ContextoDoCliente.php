<?php

namespace App\Modules\WhatsApp\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * O que a loja já sabe deste cliente antes de a Manuela perguntar (pedido do
 * Lira 26/11/2026, conversa 70): o lembrete de carrinho que acabou de sair
 * para o número (com o produto e o link), os pedidos recentes dele e se ele
 * acabou de mandar o comprovante do Pix de um pedido.
 *
 * Caso real: às 10:20 saiu o lembrete do Microfone de Lapela, às 12:30 o
 * pedido 4562 foi pago e às 12:31 o cliente mandou a foto do comprovante e
 * "Feito". A Manuela pediu o nome do produto e depois listou 3 microfones.
 *
 * Pedidos do cliente: pelo telefone (com e sem 55 e sem o 9º dígito), pelo
 * telefone gravado com defeito ("(55) 11991-2345": o 55 virou DDD e o número
 * foi cortado), pelo cadastro cujo telefone bate e pelo usuário/e-mail do
 * carrinho do lembrete.
 */
class ContextoDoCliente
{
    /** Pedidos olhados (os mais recentes). */
    public const DIAS_PEDIDOS = 30;

    /** Lembrete de sistema mais antigo que isto não conta como contexto. */
    public const DIAS_LEMBRETE = 7;

    /** Pedido feito há até tantas horas é "o pedido que ele acabou de fazer". */
    public const HORAS_PEDIDO_RECENTE = 6;

    public const STATUS_PAGOS = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public const STATUS_AGUARDANDO = [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT];

    /** "Feito", "paguei", "já fiz o pix", "segue o comprovante"... (a mensagem inteira). */
    private const PAGAMENTO_FEITO = '/^(ok[,!. ]*)?(j[aá] )?(t[aá] )?(feito|fiz|pronto|paguei|pago|pagamento feito|pix feito|fiz o pix|fiz o pagamento|transferi|enviei|mandei|segue( o)? comprovante|segue|comprovante|t[aá] pago|est[aá] pago|pagamento realizado|realizado)( o pix| o pagamento| o comprovante| a[ií]| j[aá])?[\s.!]*$/u';

    /**
     * @return array{lembrete: ?array, pedidos: list<array>, produto: ?array}
     */
    public function montar(WhatsAppConversation $conversation): array
    {
        $lembrete = $this->ultimoLembrete($conversation);
        $pedidos = $this->pedidos($conversation, $lembrete);

        // Produto do contexto: o do pedido mais recente; sem pedido, o do lembrete.
        $produto = isset($pedidos[0]['produtos'][0]) ? $pedidos[0]['produtos'][0] : ($lembrete['produto'] ?? null);

        return ['lembrete' => $lembrete, 'pedidos' => $pedidos, 'produto' => $produto];
    }

    /** O texto inteiro é "feito", "paguei", "segue o comprovante"... */
    public static function avisoDePagamento(?string $texto): bool
    {
        $t = mb_strtolower(trim((string) $texto));

        return $t !== '' && mb_strlen($t) <= 60 && (bool) preg_match(self::PAGAMENTO_FEITO, $t);
    }

    /**
     * Pedido que o cliente acabou de fazer (pago ou esperando o Pix), se houver.
     *
     * @param  array{pedidos: list<array>}  $contexto
     */
    public static function pedidoRecente(array $contexto): ?array
    {
        $limite = now()->subHours(self::HORAS_PEDIDO_RECENTE);

        foreach ($contexto['pedidos'] as $pedido) {
            if ($pedido['criado_em'] && $pedido['criado_em']->greaterThanOrEqualTo($limite)
                && ($pedido['pago'] || $pedido['aguardando'])) {
                return $pedido;
            }
        }

        return null;
    }

    /**
     * Resposta pronta para o comprovante/"feito" logo depois do pedido: agradece
     * e confirma, sem pergunta nenhuma.
     */
    public static function respostaComprovante(array $pedido, ?string $nome): string
    {
        $oi = $nome ? "{$nome}, " : '';
        $produto = $pedido['produtos'][0]['nome'] ?? null;
        $doProduto = $produto ? " do {$produto}" : '';

        if ($pedido['pago']) {
            return "{$oi}recebi aqui, muito obrigada! Seu pedido {$pedido['numero']}{$doProduto} já está pago e confirmado. Assim que ele for despachado, te mando o código de rastreio por aqui.";
        }

        return "{$oi}recebi o comprovante, muito obrigada! Seu pedido {$pedido['numero']}{$doProduto} está registrado. Assim que o pagamento compensar ele é confirmado, e quando for despachado te mando o código de rastreio por aqui.";
    }

    /** Bloco do prompt da Manuela com o contexto (vazio quando não há nada). */
    public static function blocoDoPrompt(array $contexto): string
    {
        $linhas = [];

        if ($l = $contexto['lembrete']) {
            $quando = $l['quando']?->copy()->setTimezone('America/Sao_Paulo')->format('d/m H:i');
            $linhas[] = "- Última mensagem automática da loja para este número ({$quando}): {$l['tipo']}"
                .($l['produto'] ? " do produto \"{$l['produto']['nome']}\"".($l['produto']['id'] ? " (origem loja, id {$l['produto']['id']})" : '') : '')
                .($l['link'] ? ", link {$l['link']}" : '').'.';
        }

        foreach ($contexto['pedidos'] as $p) {
            $quando = $p['criado_em']?->copy()->setTimezone('America/Sao_Paulo')->format('d/m H:i');
            $produtos = collect($p['produtos'])->map(fn ($x) => $x['nome'].($x['quantidade'] > 1 ? " ({$x['quantidade']} un.)" : ''))->implode(', ');
            $linhas[] = "- Pedido {$p['numero']} de {$quando}: {$produtos}. Situação: {$p['situacao']}"
                .($p['rastreio'] ? ", rastreio {$p['rastreio']}" : '').'.';
        }

        if ($linhas === []) {
            return '';
        }

        return "O que a loja já sabe deste cliente (use ANTES de perguntar qualquer coisa):\n".implode("\n", $linhas)."\n"
            ."Regras deste contexto:\n"
            ."- Se o contexto já diz qual é o produto (lembrete de carrinho ou pedido), NÃO pergunte qual é o produto e NÃO liste opções: fale direto dele. Para detalhes, abra a ficha com abrir_produto (origem loja e o id acima).\n"
            ."- Comprovante de Pix, foto de pagamento, \"feito\" ou \"paguei\" depois de um pedido: agradeça e confirme o pedido (número, produto, que está pago e que o rastreio vai por aqui), sem fazer pergunta.\n"
            ."- Dúvida sobre o pedido: responda com o número e a situação acima, sem pedir o número de novo.\n"
            .'- Seja curta e calorosa, como uma pessoa da loja. No máximo 1 pergunta por mensagem, e só se for realmente necessária.';
    }

    /**
     * Corta a resposta para no máximo 1 pergunta (as frases sem pergunta ficam).
     * "É o X? Quer mais informações sobre ele?" vira "É o X?".
     */
    public static function umaPerguntaSo(string $texto): string
    {
        if (substr_count($texto, '?') <= 1) {
            return $texto;
        }

        $partes = preg_split('/(?<=[.!?])\s+/u', trim($texto)) ?: [$texto];
        $saida = [];
        $perguntou = false;
        foreach ($partes as $parte) {
            if (str_contains($parte, '?')) {
                if ($perguntou) {
                    continue;
                }
                $perguntou = true;
            }
            $saida[] = $parte;
        }

        return trim(implode(' ', $saida));
    }

    /** Nome curto do produto do pedido: "Kit Microfone de Lapela Sem Fio" (sem o "para Gravação de..."). */
    public static function nomeCurto(?string $nome): string
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) $nome));
        if (mb_strlen($nome) > 40) {
            $corte = preg_split('/\s+(para|com|\+|-|–)\s+/u', $nome, 2)[0] ?? $nome;
            if (mb_strlen($corte) >= 12) {
                $nome = $corte;
            }
        }

        return Str::limit($nome, 70, '…');
    }

    /** @return array{tipo: string, quando: ?\Illuminate\Support\Carbon, produto: ?array{id: ?int, nome: string}, link: ?string, carrinho: ?array}|null */
    private function ultimoLembrete(WhatsAppConversation $conversation): ?array
    {
        /** @var WhatsAppMessage|null $msg */
        $msg = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('sent_by', 'system')
            ->where('created_at', '>=', now()->subDays(self::DIAS_LEMBRETE))
            ->orderByDesc('id')
            ->first();

        if (! $msg) {
            return null;
        }

        $payload = $msg->payload ?? [];
        $carrinhoId = (int) ($payload['carrinho_sequencia_id'] ?? 0);

        if ($carrinhoId <= 0) {
            // Outra mensagem de sistema (confirmação de pedido, rastreio): o pedido entra pela lista de pedidos.
            return ['tipo' => 'aviso de pedido', 'quando' => $msg->created_at, 'produto' => null, 'link' => null, 'carrinho' => null];
        }

        $carrinho = $this->carrinho($carrinhoId);
        $nome = $payload['variaveis']['2'] ?? $payload['variaveis'][2] ?? null;
        $produtoId = $carrinho['produto_id'] ?? null;
        if (! $nome && $carrinho) {
            $nome = collect($carrinho['itens'])->firstWhere('product_id', $produtoId)['nome'] ?? null;
        }
        preg_match('~https?://\S+~', (string) $msg->body, $m);

        return [
            'tipo' => 'lembrete de carrinho abandonado',
            'quando' => $msg->created_at,
            'produto' => $nome ? ['id' => $produtoId ? (int) $produtoId : null, 'nome' => self::nomeCurto((string) $nome), 'quantidade' => 1] : null,
            'link' => $m[0] ?? null,
            'carrinho' => $carrinho,
        ];
    }

    /** O carrinho da sequência (leitura direta da tabela, sem depender do módulo do carrinho). */
    private function carrinho(int $id): ?array
    {
        try {
            if (! Schema::hasTable('adm_abandoned_carts')) {
                return null;
            }
            $linha = DB::table('adm_abandoned_carts')->where('id', $id)->first(['produto_id', 'itens', 'user_id', 'email']);
        } catch (Throwable) {
            return null;
        }

        if (! $linha) {
            return null;
        }

        return [
            'produto_id' => $linha->produto_id,
            'itens' => json_decode((string) $linha->itens, true) ?: [],
            'user_id' => $linha->user_id,
            'email' => $linha->email,
        ];
    }

    /** @return list<array> */
    private function pedidos(WhatsAppConversation $conversation, ?array $lembrete): array
    {
        $waId = (string) ($conversation->wa_id ?: $conversation->phone);
        $variantes = self::variantes($waId);
        $desde = now()->subDays(self::DIAS_PEDIDOS);

        $userIds = collect([$lembrete['carrinho']['user_id'] ?? null]);
        $emails = collect([$lembrete['carrinho']['email'] ?? null])->filter()->map(fn ($e) => mb_strtolower(trim($e)));

        $finais = collect($variantes)->map(fn ($v) => substr($v, -4))->unique()->values();
        if ($finais->isNotEmpty()) {
            $userIds = $userIds->concat(
                User::query()
                    ->where(function ($q) use ($finais) {
                        foreach ($finais as $f) {
                            $q->orWhere('phone', 'like', "%{$f}");
                        }
                    })
                    ->limit(100)
                    ->get(['id', 'phone'])
                    ->filter(fn (User $u) => self::bate($u->phone, $variantes))
                    ->pluck('id')
            );
        }
        $userIds = $userIds->filter()->unique()->values();

        if ($finais->isEmpty() && $userIds->isEmpty() && $emails->isEmpty()) {
            return [];
        }

        $pedidos = Order::query()
            ->with('items')
            ->where('created_at', '>=', $desde)
            ->where(function ($q) use ($finais, $userIds, $emails) {
                foreach ($finais as $f) {
                    $q->orWhere('shipping_phone', 'like', "%{$f}")->orWhere('shipping_whatsapp', 'like', "%{$f}");
                }
                if ($userIds->isNotEmpty()) {
                    $q->orWhereIn('user_id', $userIds->all());
                }
                if ($emails->isNotEmpty()) {
                    $q->orWhereIn('shipping_email', $emails->all());
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->filter(fn (Order $o) => ($o->user_id && $userIds->contains($o->user_id))
                || ($o->shipping_email && $emails->contains(mb_strtolower($o->shipping_email)))
                || self::bate($o->shipping_phone, $variantes)
                || self::bate($o->shipping_whatsapp, $variantes))
            ->reject(fn (Order $o) => $o->status === Order::STATUS_CANCELLED)
            ->take(3)
            ->values();

        return $pedidos->map(fn (Order $o) => [
            'id' => $o->id,
            'numero' => (string) ($o->numero ?: $o->id),
            'status' => $o->status,
            'pago' => in_array($o->status, self::STATUS_PAGOS, true),
            'aguardando' => in_array($o->status, self::STATUS_AGUARDANDO, true),
            'situacao' => match ($o->status) {
                Order::STATUS_PAID => 'pago, aguardando envio',
                Order::STATUS_SHIPPED => 'enviado',
                Order::STATUS_COMPLETED => 'entregue',
                Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT => 'aguardando pagamento',
                default => (string) $o->status,
            },
            'criado_em' => $o->created_at,
            'nome' => $o->shipping_name ? mb_convert_case(mb_strtolower((string) strtok(trim($o->shipping_name), ' ')), MB_CASE_TITLE) : null,
            'rastreio' => rescue(fn () => $o->trackingCode(), null, false),
            'produtos' => $o->items->map(fn ($i) => [
                'id' => $i->product_id ? (int) $i->product_id : null,
                'nome' => self::nomeCurto($i->product_name),
                'quantidade' => (int) $i->quantity,
            ])->values()->all(),
        ])->all();
    }

    /**
     * Formas de o mesmo número aparecer gravado: 55+DDD+número (com e sem o 9),
     * DDD+número (com e sem o 9) e o defeito "55 virou DDD e o número foi
     * cortado" (os 11 primeiros dígitos de 55+DDD+9+8).
     *
     * @return list<string>
     */
    public static function variantes(?string $telefone): array
    {
        $d = ltrim((string) preg_replace('/\D+/', '', (string) $telefone), '0');
        if (strlen($d) < 10) {
            return [];
        }

        $nacional = (str_starts_with($d, '55') && strlen($d) >= 12) ? substr($d, 2) : $d;
        $formas = [$nacional];
        if (strlen($nacional) === 11 && $nacional[2] === '9') {
            $formas[] = substr($nacional, 0, 2).substr($nacional, 3);
        } elseif (strlen($nacional) === 10) {
            $formas[] = substr($nacional, 0, 2).'9'.substr($nacional, 2);
        }

        $todas = [];
        foreach ($formas as $f) {
            $todas[] = $f;
            $todas[] = '55'.$f;
        }
        // Defeito: "(55) 11991-2345" para 5511991234567.
        if (strlen($nacional) === 11) {
            $todas[] = substr('55'.$nacional, 0, 11);
        }

        return array_values(array_unique($todas));
    }

    /** @param list<string> $variantes */
    private static function bate(?string $telefone, array $variantes): bool
    {
        $d = ltrim((string) preg_replace('/\D+/', '', (string) $telefone), '0');

        return strlen($d) >= 10 && in_array($d, $variantes, true);
    }
}
