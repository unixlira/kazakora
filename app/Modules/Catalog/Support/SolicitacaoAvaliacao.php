<?php

namespace App\Modules\Catalog\Support;

use App\Models\Setting;
use App\Modules\Catalog\Models\AvaliacaoSolicitacao;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-10-18): alguns
 * dias depois que o pedido do kazakora.com chega, o cliente recebe um e-mail
 * que leva à página própria de avaliação (/avaliar-pedido/{token}, sem
 * login), onde dá as estrelas de cada produto. Só aí nasce o cupom dele para
 * a próxima compra, com o desconto pela nota (regra do Lira: 1★ 1%, 2★ 2%,
 * 3★ 3%, 4★ 4%, 5★ 10%; pedido com mais de um produto usa a média
 * arredondada). Configuração editada na tela de Avaliações do admin e
 * guardada via Setting. Só e-mail: a Manuela (WhatsApp) não entra nesta rotina.
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
        'mensagem' => "Oi, {nome}! Seu pedido #{pedido} chegou e queremos saber se você gostou.\n\nSua avaliação ajuda outros clientes a escolher e ajuda a gente a melhorar. Leva menos de um minuto: é só tocar no botão abaixo e dar as suas estrelas.\n\nComo agradecimento, sua avaliação vale um cupom de {desconto} para a próxima compra.",
        'botao' => 'Avaliar minha compra',
        'cupom_ativo' => true,
        // Desconto em % por nota: posição 0 = 1 estrela ... posição 4 = 5 estrelas.
        'cupom_por_estrela' => [1, 2, 3, 4, 10],
        'cupom_validade_dias' => 30,
        'cupom_pedido_minimo' => null,
        'cupom_prefixo' => 'AVALIE',
    ];

    /** Variáveis que o texto do e-mail aceita. */
    public const VARIAVEIS = [
        '{nome}' => 'primeiro nome do cliente',
        '{pedido}' => 'número do pedido',
        '{produto}' => 'nome do primeiro produto',
        '{desconto}' => 'maior desconto que a avaliação rende (ex.: até 10% de desconto)',
    ];

    public static function config(): array
    {
        $salvo = json_decode((string) Setting::get(self::SETTING_KEY), true);
        $config = array_replace(self::PADRAO, is_array($salvo) ? array_intersect_key($salvo, self::PADRAO) : []);
        $config['cupom_por_estrela'] = self::tabelaDeDesconto($config['cupom_por_estrela']);

        return $config;
    }

    /** Sempre 5 posições numéricas (1 a 5 estrelas); o que faltar vem do padrão. */
    public static function tabelaDeDesconto(mixed $tabela): array
    {
        $tabela = is_array($tabela) ? array_values($tabela) : [];

        return array_map(
            fn (int $i) => round(max(0, (float) ($tabela[$i] ?? self::PADRAO['cupom_por_estrela'][$i])), 2),
            range(0, 4),
        );
    }

    /** % de desconto que a nota (1 a 5 estrelas) rende. */
    public static function percentualDaNota(array $config, int $nota): float
    {
        return (float) $config['cupom_por_estrela'][min(5, max(1, $nota)) - 1];
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
     * Produtos do pedido que o cliente ainda não avaliou, com foto:
     * [['id', 'nome', 'imagem']]. O link do e-mail é o da página do pedido.
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
            ])
            ->values()
            ->all();
    }

    /**
     * Todos os produtos do pedido para a página de avaliação, com a nota e o
     * comentário que o cliente já tiver dado (avaliou pela página do produto
     * antes): [['id', 'nome', 'imagem', 'nota', 'comentario']].
     */
    public static function produtosDaPagina(Order $order): array
    {
        $order->loadMissing(['items.product.images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('position')]);
        $avaliacoes = $order->user_id
            ? Review::query()->where('user_id', $order->user_id)->get()->keyBy('product_id')
            : collect();

        return $order->items
            ->filter(fn ($item) => $item->product)
            ->unique('product_id')
            ->map(fn ($item) => [
                'id' => $item->product->id,
                'nome' => $item->product->name,
                'imagem' => $item->product->images->first()?->thumb_url,
                'nota' => $avaliacoes->get($item->product_id)?->rating,
                'comentario' => $avaliacoes->get($item->product_id)?->comment,
            ])
            ->values()
            ->all();
    }

    /**
     * Cliente mandou as estrelas na página: grava a avaliação de cada produto,
     * calcula a nota do pedido (média arredondada) e cria o cupom pelo
     * desconto da nota. Uma vez só por pedido (trava na linha). Devolve a
     * solicitação atualizada, ou null quando o pedido já tinha sido avaliado.
     *
     * @param  array<int, array{rating: int, comment: ?string}>  $avaliacoes  por product_id
     */
    public static function registrarAvaliacao(AvaliacaoSolicitacao $solicitacao, array $avaliacoes): ?AvaliacaoSolicitacao
    {
        return DB::transaction(function () use ($solicitacao, $avaliacoes) {
            $solicitacao = AvaliacaoSolicitacao::query()->lockForUpdate()->findOrFail($solicitacao->id);
            if ($solicitacao->nota !== null) {
                return null;
            }

            $order = $solicitacao->order;
            $doPedido = $order->items()->pluck('product_id')->all();
            $avaliacoes = array_intersect_key($avaliacoes, array_flip($doPedido));
            if ($avaliacoes === []) {
                return null;
            }

            foreach ($avaliacoes as $productId => $avaliacao) {
                Review::query()->updateOrCreate(
                    ['user_id' => $solicitacao->user_id, 'product_id' => $productId],
                    ['rating' => (int) $avaliacao['rating'], 'comment' => filled($avaliacao['comment'] ?? null) ? trim($avaliacao['comment']) : null],
                );
            }

            $config = self::config();
            $nota = (int) round(collect($avaliacoes)->avg('rating'));
            // E-mail do modelo antigo já levou o cupom: fica valendo o que ele mandou.
            $cupom = $solicitacao->coupon;
            $percentual = $cupom ? (float) $cupom->discount_value : self::percentualDaNota($config, $nota);
            $cupom ??= self::criarCupom($order, $config, $percentual);

            $solicitacao->update([
                'nota' => $nota,
                'desconto_percentual' => $cupom ? $percentual : 0,
                'coupon_id' => $cupom?->id,
                'avaliado_em' => $solicitacao->avaliado_em ?? now(),
            ]);

            return $solicitacao->setRelation('coupon', $cupom);
        });
    }

    /**
     * Cupom só deste cliente, com o % que a nota rendeu: 1 uso, válido por
     * N dias, código PREFIXO + 6 letras/números (sem 0/O/1/I para não confundir).
     */
    public static function criarCupom(Order $order, array $config, float $percentual): ?Coupon
    {
        if (! $config['cupom_ativo'] || $percentual <= 0) {
            return null;
        }

        do {
            $codigo = $config['cupom_prefixo'].strtr(Str::upper(Str::random(6)), ['0' => '8', 'O' => 'P', '1' => '7', 'I' => 'K']);
        } while (Coupon::query()->where('code', $codigo)->exists());

        return Coupon::query()->create([
            'code' => $codigo,
            'name' => "Avaliação pós-compra · pedido #{$order->numero}",
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => $percentual,
            'min_order_value' => $config['cupom_pedido_minimo'] ?: null,
            'max_uses' => 1,
            'max_uses_per_customer' => 1,
            'one_per_customer' => true,
            'starts_at' => now(),
            'expires_at' => now()->addDays(max(1, (int) $config['cupom_validade_dias']))->endOfDay(),
            'is_active' => true,
        ]);
    }

    /** "até 10% de desconto": o maior desconto da tabela por estrelas. */
    public static function descricaoDesconto(array $config): string
    {
        $maior = max($config['cupom_por_estrela']);
        if (! $config['cupom_ativo'] || $maior <= 0) {
            return '';
        }

        return 'até '.(new Coupon)->forceFill([
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => $maior,
        ])->descricaoDesconto();
    }

    /** Troca {nome}, {pedido}... pelos valores do pedido. */
    public static function preencher(string $texto, array $valores): string
    {
        return strtr($texto, $valores);
    }

    public static function valores(Order $order, array $config, array $produtos): array
    {
        $nome = Str::of(trim((string) ($order->user?->name ?: $order->shipping_name)))->explode(' ')->first() ?: 'cliente';

        return [
            '{nome}' => Str::title(mb_strtolower($nome)),
            '{pedido}' => (string) $order->numero,
            '{produto}' => $produtos[0]['nome'] ?? 'seu produto',
            '{desconto}' => self::descricaoDesconto($config),
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
