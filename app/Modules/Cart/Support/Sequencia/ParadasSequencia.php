<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Models\User;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Support\WhatsAppPhone;

/**
 * Quando a sequência de carrinho abandonado para (pedido do Lira 21/11/2026):
 * - comprou: pedido do mesmo e-mail, telefone ou usuário depois do abandono,
 *   ou o lead do checkout virou "convertido";
 * - pediu_parar: respondeu "parar"/"sair" no WhatsApp ou clicou no
 *   descadastro do e-mail;
 * - sem_promocoes: o cliente desmarcou receber promoções (`recebe_promocoes`);
 * - sem_contato: nem e-mail nem celular;
 * - fim: mandou os 6 disparos, ou a sequência passou do D+30 sem conseguir.
 */
class ParadasSequencia
{
    /** Dias depois do início em que a sequência acaba mesmo sem ter mandado tudo. */
    public const DIAS_VALIDADE = 32;

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
        $email = $registro->email;
        $userId = $registro->user_id;

        if (($email || $userId) && Order::query()
            ->where('created_at', '>=', $desde)
            ->where(fn ($q) => $q
                ->when($email, fn ($q) => $q->orWhere('shipping_email', $email))
                ->when($userId, fn ($q) => $q->orWhere('user_id', $userId)))
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
            ->where('created_at', '>=', $desde)
            ->where(fn ($q) => $q->where('shipping_phone', 'like', '%'.$final)->orWhere('shipping_whatsapp', 'like', '%'.$final))
            ->limit(50)
            ->get(['shipping_phone', 'shipping_whatsapp'])
            ->contains(fn (Order $o) => WhatsAppPhone::mesmoNumero($registro->telefone, [$o->shipping_phone, $o->shipping_whatsapp]));
    }

    private function cliente(Registro $registro): ?User
    {
        if ($registro->user_id && ($user = User::query()->find($registro->user_id))) {
            return $user;
        }

        return $registro->email ? User::query()->where('email', $registro->email)->first() : null;
    }
}
