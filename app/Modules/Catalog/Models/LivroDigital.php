<?php

namespace App\Modules\Catalog\Models;

use App\Models\Setting;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Checkout\Models\Order;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Entregáveis › Livros Digitais (pedido 2026-10-20): PDF de brinde que
 * aparece na página do produto. O PDF fica no disco privado (`local`), só o
 * admin baixa; a capa (opcional) fica no `public`. `todos_produtos` = vale
 * para a loja inteira; senão só nos produtos de `produtos` (pais).
 *
 * @property int $id
 * @property string $titulo
 * @property string $arquivo
 * @property string $nome_arquivo
 * @property int $tamanho
 * @property ?string $capa
 * @property ?string $imagem_quadro
 * @property bool $ativo
 * @property bool $todos_produtos
 */
class LivroDigital extends Model
{
    use Auditable;

    protected $table = 'livros_digitais';

    /**
     * Chave da promoção: além do link de download (que vai sempre), os
     * livros vão anexados no e-mail de confirmação do pedido. Ligada por
     * padrão (aprovado pelo Lira em 2026-10-21): só '0' desliga.
     */
    public const SETTING_EMAIL_PEDIDO = 'livros_digitais.email_pedido';

    /** Livros ganhos em cada pedido (garantirParaPedido). */
    public const TABELA_PEDIDO = 'pedido_livros_digitais';

    protected $fillable = ['titulo', 'arquivo', 'nome_arquivo', 'tamanho', 'capa', 'imagem_quadro', 'ativo', 'todos_produtos'];

    protected function casts(): array
    {
        return [
            'tamanho' => 'integer',
            'ativo' => 'boolean',
            'todos_produtos' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PromocoesDoProduto::limparCache());
        static::deleted(fn () => PromocoesDoProduto::limparCache());
    }

    public function produtos(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'livro_digital_produto', 'livro_digital_id', 'product_id');
    }

    public function getCapaUrlAttribute(): ?string
    {
        return $this->capa ? asset('storage/'.$this->capa) : null;
    }

    /** Imagem dos tablets no quadro da página do produto (disco `public`). */
    public function getImagemQuadroUrlAttribute(): ?string
    {
        return $this->imagem_quadro ? asset('storage/'.$this->imagem_quadro) : null;
    }

    /**
     * Ativos que aparecem na página do produto (variação vale o pai). Se o
     * produto tem livros próprios (escolhidos para ele), mostra SÓ esses; os
     * de "todos os produtos" ficam para quem não tem nenhum (Lira,
     * 2026-10-21: guias de ferramentas não se misturam com os de costas).
     */
    public static function doProduto(Product $produto): Collection
    {
        $ids = array_unique([$produto->id, $produto->parent_product_id ?? $produto->id]);

        $especificos = self::query()
            ->where('ativo', true)
            ->where('todos_produtos', false)
            ->whereHas('produtos', fn (Builder $p) => $p->whereIn('products.id', $ids))
            ->orderBy('id')
            ->get();

        if ($especificos->isNotEmpty()) {
            return $especificos;
        }

        return self::query()
            ->where('ativo', true)
            ->where('todos_produtos', true)
            ->orderBy('id')
            ->get();
    }

    public static function emailPedidoLigado(): bool
    {
        return Setting::get(self::SETTING_EMAIL_PEDIDO, '1') !== '0';
    }

    public static function ligarEmailPedido(bool $ligar): void
    {
        Setting::set(self::SETTING_EMAIL_PEDIDO, $ligar ? '1' : '0');
    }

    /**
     * Livros que o pedido ganhou (pedido 2026-10-21), gravados na compra por
     * garantirParaPedido(). Ficam com o cliente mesmo que o livro seja
     * desativado ou a promoção acabe depois; só some se o livro for apagado.
     */
    public static function doPedido(Order $order): Collection
    {
        if (! self::pedidoDaLoja($order)) {
            return new Collection;
        }

        return self::query()
            ->whereIn('id', DB::table(self::TABELA_PEDIDO)->where('order_id', $order->id)->select('livro_digital_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Grava os livros do pedido com a regra que a página do produto mostrava
     * na hora (PromocoesDoProduto: chave da Promoção PDFs, produtos ligados e
     * livros ativos), somando os produtos sem repetir livro. Só pedido do
     * kazakora.com: é lá que o quadro dos livros aparece.
     */
    public static function garantirParaPedido(Order $order): Collection
    {
        if (! self::pedidoDaLoja($order)) {
            return new Collection;
        }

        $order->loadMissing('items.product');

        $ids = $order->items
            ->toBase()
            ->map(fn ($item) => $item->product)
            ->filter()
            ->flatMap(fn (Product $produto) => array_column(PromocoesDoProduto::paraPagina($produto)['livrosDigitais'], 'id'))
            ->unique()
            ->values();

        DB::table(self::TABELA_PEDIDO)->insertOrIgnore($ids->map(fn (int $id) => [
            'order_id' => $order->id,
            'livro_digital_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());

        return self::doPedido($order);
    }

    /** Link de download do livro no pedido (assinado: abre do e-mail sem login). */
    public function urlDoPedido(Order $order): string
    {
        return URL::signedRoute('pedidos.livro.baixar', ['order' => $order->id, 'livro' => $this->id]);
    }

    /** Download liberado: pedido pago (ou já enviado/entregue), nunca pendente ou cancelado. */
    public static function pedidoLiberado(Order $order): bool
    {
        return in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true);
    }

    private static function pedidoDaLoja(Order $order): bool
    {
        return $order->origin === Order::ORIGIN_STORE && $order->site_origem === null;
    }
}
