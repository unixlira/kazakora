<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Models\User;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Support\WhatsAppPhone;

/**
 * Quando a sequência de carrinho abandonado para (pedido do Lira 21/11/2026):
 * - comprou: pedido PAGO (pago, enviado ou entregue) do mesmo e-mail, telefone
 *   ou usuário depois do abandono, ou o lead do checkout virou "convertido".
 *   Pedido só gerado (Pix/boleto esperando) ou cancelado não para: a sequência
 *   segue até o pagamento (decisão do Lira em 25/11/2026);
 * - pediu_parar: respondeu "parar"/"sair" no WhatsApp ou clicou no
 *   descadastro do e-mail;
 * - sem_promocoes: o cliente desmarcou receber promoções (`recebe_promocoes`);
 * - sem_contato: nem e-mail nem celular;
 * - fim: mandou os 4 disparos (D+1, D+3, D+5, D+7), ou passou do D+7 (com
 *   2 dias de folga para a janela das 9h–20h) sem conseguir.
 *
 * Compra (26/11/2026, pedido 4562 com o telefone gravado errado): além do
 * e-mail, telefone e usuário do carrinho, vale o pedido do usuário cujo
 * e-mail ou celular é o do carrinho e o e-mail do usuário do carrinho.
 */
class ParadasSequencia
{
    /** Dias depois do início em que a sequência acaba mesmo sem ter mandado tudo. */
    public const DIAS_VALIDADE = 9;

    /** Pedido que conta como compra para parar a sequência (o mesmo de LeadsDigitados::pago). */
    public const STATUS_PAGOS = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    /** Motivo de parada, ou null quando a sequência segue. */
    public function motivo(Registro $registro): ?string
    {
        if ($this->comprou($registro)) {
            return Registro::PAROU_COMPROU;
        }

        $cliente = $this->cliente($registro);
        if ($cliente && $cliente->recebe_promocoes === false) {
            return Registro::PAROU_SEM_PROMOCOES;
        }

        if (! $registro->email && ! $registro->telefone) {
            return Registro::PAROU_SEM_CONTATO;
        }

        if ($registro->disparos_enviados >= CalendarioSequencia::TOTAL
            || ($registro->inicio_sequencia_em && now()->greaterThan($registro->inicio_sequencia_em->copy()->addDays(self::DIAS_VALIDADE)))) {
            return Registro::PAROU_FIM;
        }

        return null;
    }

    /** Confere e, se for o caso, para. Devolve o motivo (null = segue). */
    public function aplicar(Registro $registro): ?string
    {
        $motivo = $this->motivo($registro);

        if ($motivo !== null) {
            $registro->parar($motivo, $motivo === Registro::PAROU_COMPROU ? ['convertido_em' => now()] : []);
        }

        return $motivo;
    }

    /** Para todos os registros abertos (de todo site) da pessoa. */
    public function pediuParar(?string $email = null, ?string $telefone = null): int
    {
        $parados = 0;

        if (filled($email)) {
            Registro::query()->where('parado', false)->where('email', mb_strtolower(trim($email)))->get()
                ->each(function (Registro $r) use (&$parados) {
                    $r->parar(Registro::PAROU_PEDIU);
                    $parados++;
                });
        }

        $final = substr((string) preg_replace('/\D/', '', (string) $telefone), -8);
        if (strlen($final) === 8) {
            Registro::query()->where('parado', false)->where('telefone', 'like', '%'.$final)->get()
                ->filter(fn (Registro $r) => WhatsAppPhone::mesmoNumero($telefone, [$r->telefone]))
                ->each(function (Registro $r) use (&$parados) {
                    $r->parar(Registro::PAROU_PEDIU);
                    $parados++;
                });
        }

        return $parados;
    }

    /**
     * Mensagem do cliente no WhatsApp que é pedido para parar: a mensagem
     * inteira é "parar", "sair", "pare", "stop"... (frase com a palavra no meio
     * não conta, para não parar quem só está conversando).
     */
    public static function pedidoDeParada(?string $texto): bool
    {
        $texto = mb_strtolower(trim((string) $texto));

        return (bool) preg_match('/^(parar|pare|para|sair|stop|cancelar|descadastrar|n[aã]o quero mais( receber)?( mensagens?)?)[\s.!]*$/u', $texto);
    }

    private function comprou(Registro $registro): bool
    {
        if ($registro->origem === Registro::ORIGEM_LEAD) {
            $status = LeadCheckout::query()->whereKey($registro->origem_id)->value('status');
            if ($status === LeadCheckout::STATUS_CONVERTIDO) {
                return true;
            }
        }

        $desde = $registro->abandonado_em ?? $registro->created_at;
        [$emails, $userIds] = $this->identidades($registro);

        if (($emails || $userIds) && Order::query()
            ->whereIn('status', self::STATUS_PAGOS)
            ->where('created_at', '>=', $desde)
            ->where(fn ($q) => $q
                ->when($emails, fn ($q) => $q->orWhereIn('shipping_email', $emails))
                ->when($userIds, fn ($q) => $q->orWhereIn('user_id', $userIds)))
            ->exists()) {
            return true;
        }

        // Telefone: o pedido guarda com máscara ("(11) 99999-0000"); filtra
        // pelos 4 últimos dígitos no banco e compara o número inteiro aqui.
        $final = substr((string) preg_replace('/\D/', '', (string) $registro->telefone), -4);
        if (strlen($final) !== 4) {
            return false;
        }

        return Order::query()
            ->whereIn('status', self::STATUS_PAGOS)
            ->where('created_at', '>=', $desde)
            ->where(fn ($q) => $q->where('shipping_phone', 'like', '%'.$final)->orWhere('shipping_whatsapp', 'like', '%'.$final))
            ->limit(50)
            ->get(['shipping_phone', 'shipping_whatsapp'])
            ->contains(fn (Order $o) => WhatsAppPhone::mesmoNumero($registro->telefone, [$o->shipping_phone, $o->shipping_whatsapp]));
    }

    /**
     * E-mails e usuários da pessoa do carrinho: os do registro, o usuário com
     * esse e-mail, o e-mail do usuário e o usuário cujo celular é o do carrinho.
     *
     * @return array{0: list<string>, 1: list<int>}
     */
    private function identidades(Registro $registro): array
    {
        $emails = array_filter([$registro->email ? mb_strtolower($registro->email) : null]);
        $userIds = array_filter([(int) $registro->user_id]);

        $usuarios = User::query()
            ->where(fn ($q) => $q
                ->when($userIds, fn ($q) => $q->orWhereIn('id', $userIds))
                ->when($emails, fn ($q) => $q->orWhereIn('email', $emails)))
            ->when(! $userIds && ! $emails, fn ($q) => $q->whereRaw('1 = 0'))
            ->limit(5)
            ->get(['id', 'email']);

        $final = substr((string) preg_replace('/\D/', '', (string) $registro->telefone), -8);
        if (strlen($final) === 8) {
            $usuarios = $usuarios->merge(User::query()
                ->where('phone', 'like', '%'.substr($final, 0, 4).'%'.substr($final, 4))
                ->limit(20)
                ->get(['id', 'email', 'phone'])
                ->filter(fn (User $u) => WhatsAppPhone::mesmoNumero($registro->telefone, [$u->phone])));
        }

        foreach ($usuarios as $usuario) {
            $userIds[] = (int) $usuario->id;
            if ($usuario->email) {
                $emails[] = mb_strtolower($usuario->email);
            }
        }

        return [array_values(array_unique($emails)), array_values(array_unique(array_filter($userIds)))];
    }

    private function cliente(Registro $registro): ?User
    {
        if ($registro->user_id && ($user = User::query()->find($registro->user_id))) {
            return $user;
        }

        return $registro->email ? User::query()->where('email', $registro->email)->first() : null;
    }
}
