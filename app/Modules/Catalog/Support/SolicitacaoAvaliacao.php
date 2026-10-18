<?php

namespace App\Modules\Catalog\Support;

use App\Models\Setting;
use App\Modules\Catalog\Models\AvaliacaoSolicitacao;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-10-18): alguns
 * dias depois que o pedido do kazakora.com chega, o cliente recebe um e-mail
 * pedindo a avaliação de cada produto, com um cupom só dele para a próxima
 * compra. O cupom vai no e-mail para todo mundo, sem depender da nota que o
 * cliente der (cupom só para avaliação boa é proibido pelo CDC e pelas
 * plataformas). Configuração editada na tela de Avaliações do admin e
 * guardada via Setting.
 */
class SolicitacaoAvaliacao
{
    private const SETTING_KEY = 'avaliacao_pos_compra';

    /** Pedido entregue há mais tempo que isso (dias + folga) não recebe e-mail. */
    public const FOLGA_DIAS = 7;

    /** Envio só em horário comercial de São Paulo. */
    public const HORA_INICIO = 9;

    public const HORA_FIM = 20;

    public const PADRAO = [
        'ativo' => false,
        'dias_apos_entrega' => 3,
        'assunto' => '{nome}, o que achou da sua compra? Ganhe {desconto} 🎁',
        'titulo' => 'Conta pra gente o que achou!',
        'mensagem' => "Oi, {nome}! Seu pedido #{pedido} chegou e queremos saber se você gostou.\n\nSua avaliação ajuda outros clientes a escolher e ajuda a gente a melhorar. Leva menos de um minuto: é só tocar no produto abaixo e dar a sua nota.\n\nComo agradecimento, separamos um cupom de {desconto} para a sua próxima compra.",
        'botao' => 'Avaliar minha compra',
        'cupom_ativo' => true,
        'cupom_tipo' => Coupon::TYPE_PERCENTAGE,
        'cupom_valor' => 10,
        'cupom_validade_dias' => 30,
        'cupom_pedido_minimo' => null,
        'cupom_prefixo' => 'AVALIE',
    ];

    /** Variáveis que o texto do e-mail aceita. */
    public const VARIAVEIS = [
        '{nome}' => 'primeiro nome do cliente',
        '{pedido}' => 'número do pedido',
        '{produto}' => 'nome do primeiro produto',
        '{desconto}' => 'desconto do cupom (ex.: 10% de desconto)',
        '{cupom}' => 'código do cupom',
        '{validade}' => 'data final do cupom',
    ];

    public static function config(): array
    {
        $salvo = json_decode((string) Setting::get(self::SETTING_KEY), true);

        return array_replace(self::PADRAO, is_array($salvo) ? array_intersect_key($salvo, self::PADRAO) : []);
    }

    public static function salvar(array $dados): array
    {
        $atual = self::config();
        $novo = array_replace($atual, array_intersect_key($dados, self::PADRAO));
        $novo['cupom_prefixo'] = Coupon::normalizar($novo['cupom_prefixo']) ?: self::PADRAO['cupom_prefixo'];

        // Primeira ativação: pedido entregue antes disso não recebe e-mail.
        $salvo = json_decode((string) Setting::get(self::SETTING_KEY), true) ?: [];
        $novo['ativado_em'] = $novo['ativo'] && ! $atual['ativo']
            ? now()->toIso8601String()
            : ($salvo['ativado_em'] ?? null);

        Setting::set(self::SETTING_KEY, json_encode($novo, JSON_UNESCAPED_UNICODE));

        return self::config();
    }

    public static function ativadoEm(): ?Carbon
    {
        $salvo = json_decode((string) Setting::get(self::SETTING_KEY), true);

        return filled($salvo['ativado_em'] ?? null) ? Carbon::parse($salvo['ativado_em']) : null;
    }

    public static function emHorarioDeEnvio(?Carbon $agora = null): bool
    {
        $hora = ($agora ?? now())->copy()->setTimezone('America/Sao_Paulo')->hour;

        return $hora >= self::HORA_INICIO && $hora < self::HORA_FIM;
    }

    /**
     * Pedidos do kazakora.com entregues há pelo menos N dias, ainda sem
     * e-mail de avaliação. Pedido antigo (entregue antes da ativação ou
     * fora da folga) fica de fora para não disparar em massa.
     */
    public static function pedidosNaHora(array $config): Builder
    {
        $dias = max(0, (int) $config['dias_apos_entrega']);
        $entrega = 'coalesce(orders.entregue_em, orders.updated_at)';
        $desde = now()->subDays($dias + self::FOLGA_DIAS);
        $ativadoEm = self::ativadoEm();
        if ($ativadoEm && $ativadoEm->gt($desde)) {
            $desde = $ativadoEm;
        }

        return Order::query()
            ->where('origin', Order::ORIGIN_STORE)
            ->where('projeto_origem', Order::PROJETO_KAZAKORA)
            ->where('status', Order::STATUS_COMPLETED)
            ->vendaEfetiva()
            ->whereRaw("{$entrega} <= ?", [now()->subDays($dias)])
            ->whereRaw("{$entrega} >= ?", [$desde])
            ->whereDoesntHave('avaliacaoSolicitacao');
    }

    /** E-mail de quem recebe; null quando não há para quem mandar. */
    public static function emailDo(Order $order): ?string
    {
        $email = trim((string) ($order->shipping_email ?: $order->user?->email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** Por que o pedido não recebe o e-mail; null quando recebe. */
    public static function motivoParaPular(Order $order): ?string
    {
        if (! $order->user_id || ! $order->user) {
            return 'pedido sem conta: só cliente com login avalia';
        }
        if (! self::emailDo($order)) {
            return 'sem e-mail';
        }
        if ($order->user->recebe_promocoes === false) {
            return 'cliente saiu das promoções';
        }
        if (self::produtosParaAvaliar($order) === []) {
            return 'cliente já avaliou todos os produtos';
        }

        return null;
    }

    /**
     * Produtos do pedido que o cliente ainda não avaliou, com foto e link
     * para avaliar: [['id', 'nome', 'imagem', 'link']].
     */
    public static function produtosParaAvaliar(Order $order): array
    {
        $order->loadMissing(['items.product.images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('position')]);
        $jaAvaliados = $order->user_id
            ? Review::query()->where('user_id', $order->user_id)->pluck('product_id')->all()
            : [];

        return $order->items
            ->filter(fn ($item) => $item->product && ! in_array($item->product_id, $jaAvaliados, true))
            ->unique('product_id')
            ->map(fn ($item) => [
                'id' => $item->product->id,
                'nome' => $item->product->name,
                'imagem' => $item->product->images->first()?->thumb_url,
                'link' => route('avaliacoes.avaliar', $item->product->slug),
            ])
            ->values()
            ->all();
    }

    /**
     * Cupom só deste cliente: 1 uso, válido por N dias, código
     * PREFIXO + 6 letras/números (sem 0/O/1/I para não confundir).
     */
    public static function criarCupom(Order $order, array $config): ?Coupon
    {
        if (! $config['cupom_ativo'] || (float) $config['cupom_valor'] <= 0) {
            return null;
        }

        do {
            $codigo = $config['cupom_prefixo'].strtr(Str::upper(Str::random(6)), ['0' => '8', 'O' => 'P', '1' => '7', 'I' => 'K']);
        } while (Coupon::query()->where('code', $codigo)->exists());

        return Coupon::query()->create([
            'code' => $codigo,
            'name' => "Avaliação pós-compra · pedido #{$order->numero}",
            'discount_type' => $config['cupom_tipo'],
            'discount_value' => $config['cupom_valor'],
            'min_order_value' => $config['cupom_pedido_minimo'] ?: null,
            'max_uses' => 1,
            'max_uses_per_customer' => 1,
            'one_per_customer' => true,
            'starts_at' => now(),
            'expires_at' => now()->addDays(max(1, (int) $config['cupom_validade_dias']))->endOfDay(),
            'is_active' => true,
        ]);
    }

    /** "10% de desconto" / "R$ 20,00 de desconto" a partir da configuração. */
    public static function descricaoDesconto(array $config): string
    {
        return (new Coupon)->forceFill([
            'discount_type' => $config['cupom_tipo'],
            'discount_value' => $config['cupom_valor'],
        ])->descricaoDesconto();
    }

    /** Troca {nome}, {pedido}... pelos valores do pedido. */
    public static function preencher(string $texto, array $valores): string
    {
        return strtr($texto, $valores);
    }

    public static function valores(Order $order, ?Coupon $cupom, array $config, array $produtos): array
    {
        $nome = Str::of(trim((string) ($order->user?->name ?: $order->shipping_name)))->explode(' ')->first() ?: 'cliente';

        return [
            '{nome}' => Str::title(mb_strtolower($nome)),
            '{pedido}' => (string) $order->numero,
            '{produto}' => $produtos[0]['nome'] ?? 'seu produto',
            '{desconto}' => $cupom ? $cupom->descricaoDesconto() : self::descricaoDesconto($config),
            '{cupom}' => $cupom?->code ?? '',
            '{validade}' => $cupom?->expires_at?->format('d/m/Y') ?? '',
        ];
    }

    /** Cliente avaliou: marca os pedidos de avaliação dele que tinham o produto. */
    public static function marcarAvaliado(int $userId, int $productId): void
    {
        AvaliacaoSolicitacao::query()
            ->where('user_id', $userId)
            ->whereNull('avaliado_em')
            ->whereHas('order.items', fn ($query) => $query->where('product_id', $productId))
            ->update(['avaliado_em' => now()]);
    }
}
