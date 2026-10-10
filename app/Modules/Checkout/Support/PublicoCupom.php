<?php

namespace App\Modules\Checkout\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Públicos do disparo de cupom em lote (pedido 2026-10-10). Sempre só
 * clientes (nunca equipe), com e-mail e que não pediram pra sair das
 * promoções.
 */
class PublicoCupom
{
    public const CARRINHO_ABANDONADO = 'carrinho_abandonado';

    public const TODOS = 'clientes_todos';

    public const COMPRARAM = 'clientes_compraram';

    public const SEM_COMPRA = 'clientes_sem_compra';

    public const ANIVERSARIANTES = 'aniversariantes_mes';

    public const OPCOES = [
        self::CARRINHO_ABANDONADO => 'Carrinho abandonado (começou a compra e não pagou)',
        self::TODOS => 'Todos os clientes',
        self::COMPRARAM => 'Clientes que já compraram',
        self::SEM_COMPRA => 'Clientes cadastrados que nunca compraram',
        self::ANIVERSARIANTES => 'Aniversariantes do mês',
    ];

    /**
     * Ocasiões (épocas sazonais): só preenchem assunto e texto — o público
     * continua escolhido à parte. {nome}, {cupom}, {desconto} e {validade}
     * são trocados na hora do envio.
     */
    public const OCASIOES = [
        'carrinho' => ['nome' => 'Lembrete de carrinho', 'assunto' => '{nome}, seu carrinho ainda está esperando por você 🛒', 'mensagem' => "Vimos que você começou uma compra e não finalizou. Para facilitar, separamos um presente: use o cupom {cupom} e ganhe {desconto}.\n\nÉ só voltar à loja e finalizar. Válido {validade}."],
        'black_friday' => ['nome' => 'Black Friday', 'assunto' => 'Black Friday KazaKora: {desconto} com o cupom {cupom} 🖤', 'mensagem' => "A Black Friday chegou na KazaKora! Use o cupom {cupom} e ganhe {desconto} em toda a loja.\n\nCorre que é por tempo limitado — válido {validade}."],
        'natal' => ['nome' => 'Natal', 'assunto' => 'Presente de Natal para você, {nome} 🎄', 'mensagem' => "Neste Natal, a KazaKora quer deixar sua casa ainda mais especial. Use o cupom {cupom} e ganhe {desconto}.\n\nVálido {validade}. Boas festas!"],
        'ano_novo' => ['nome' => 'Ano Novo', 'assunto' => 'Comece o ano com a casa organizada ✨', 'mensagem' => "Ano novo, casa nova! Use o cupom {cupom} e ganhe {desconto} para começar o ano com tudo no lugar.\n\nVálido {validade}."],
        'dia_consumidor' => ['nome' => 'Dia do Consumidor (15/03)', 'assunto' => 'Dia do Consumidor: {desconto} só para você', 'mensagem' => "Hoje o dia é seu! Use o cupom {cupom} e ganhe {desconto} na KazaKora.\n\nVálido {validade}."],
        'dia_maes' => ['nome' => 'Dia das Mães', 'assunto' => 'Dia das Mães: presenteie com {desconto} 💐', 'mensagem' => "Encontre o presente perfeito para quem cuida de tudo. Use o cupom {cupom} e ganhe {desconto}.\n\nVálido {validade}."],
        'dia_namorados' => ['nome' => 'Dia dos Namorados', 'assunto' => 'Dia dos Namorados com {desconto} ❤️', 'mensagem' => "Surpreenda quem você ama! Use o cupom {cupom} e ganhe {desconto} na KazaKora.\n\nVálido {validade}."],
        'dia_pais' => ['nome' => 'Dia dos Pais', 'assunto' => 'Dia dos Pais: {desconto} no presente certo', 'mensagem' => "Ferramentas, gadgets e utilidades que todo pai ama. Use o cupom {cupom} e ganhe {desconto}.\n\nVálido {validade}."],
        'dia_criancas' => ['nome' => 'Dia das Crianças', 'assunto' => 'Dia das Crianças: diversão com {desconto} 🧸', 'mensagem' => "Brinquedos e novidades para a criançada. Use o cupom {cupom} e ganhe {desconto}.\n\nVálido {validade}."],
        'aniversario' => ['nome' => 'Aniversário do cliente', 'assunto' => 'Feliz aniversário, {nome}! Tem presente aqui 🎂', 'mensagem' => "A KazaKora deseja um feliz aniversário! Para comemorar, use o cupom {cupom} e ganhe {desconto}.\n\nVálido {validade}."],
        'volta_clientes' => ['nome' => 'Sentimos sua falta', 'assunto' => '{nome}, sentimos sua falta 💜', 'mensagem' => "Faz tempo que você não passa por aqui! Temos novidades e um cupom especial: use {cupom} e ganhe {desconto}.\n\nVálido {validade}."],
    ];

    public static function query(string $publico, int $dias = 30): Builder
    {
        $pagos = fn ($orders) => $orders->whereIn('status', Coupon::STATUS_USO);

        $query = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->where('recebe_promocoes', true)
            ->whereNotNull('email');

        return match ($publico) {
            // Começou a compra na loja nos últimos X dias e não tem pedido pago depois disso.
            self::CARRINHO_ABANDONADO => $query
                ->whereHas('orders', fn ($orders) => $orders
                    ->where('origin', Order::ORIGIN_STORE)
                    ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT, Order::STATUS_CANCELLED])
                    ->where('created_at', '>=', now()->subDays($dias)))
                ->whereDoesntHave('orders', fn ($orders) => $pagos($orders)->where('created_at', '>=', now()->subDays($dias))),
            self::COMPRARAM => $query->whereHas('orders', $pagos),
            self::SEM_COMPRA => $query->whereDoesntHave('orders', $pagos),
            self::ANIVERSARIANTES => $query->whereNotNull('birth_date')->whereMonth('birth_date', now()->month),
            default => $query,
        };
    }

    /** Troca {nome}, {cupom}, {desconto} e {validade} pelo valor de cada cliente. */
    public static function preencher(string $texto, Coupon $coupon, ?User $user = null): string
    {
        $primeiroNome = $user ? (explode(' ', trim((string) $user->name))[0] ?: 'cliente') : 'cliente';

        return strtr($texto, [
            '{nome}' => $primeiroNome,
            '{cupom}' => $coupon->code,
            '{desconto}' => $coupon->descricaoDesconto(),
            '{validade}' => $coupon->expires_at ? 'até '.$coupon->expires_at->format('d/m/Y') : 'por tempo limitado',
        ]);
    }
}
