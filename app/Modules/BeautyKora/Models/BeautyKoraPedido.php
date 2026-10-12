<?php

namespace App\Modules\BeautyKora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item comprado no site Beauty Kora (pedido 2026-10-12), um registro por
 * produto do carrinho, igual o site já gravava no PocketBase. Nasce
 * "pending" quando o cliente vai para o pagamento.
 */
class BeautyKoraPedido extends Model
{
    public const STATUS = ['pending', 'paid', 'shipped', 'delivered', 'canceled'];

    protected $table = 'beautykora_pedidos';

    protected $fillable = [
        'cliente_id', 'endereco_id', 'produto_id', 'variante_id', 'produto_nome', 'produto_imagem',
        'quantidade', 'valor_pago_centavos', 'status', 'endereco',
        'lote', 'email_checkout', 'hostinger_pedido_id', 'hostinger_numero', 'pago_em',
    ];

    protected function casts(): array
    {
        return ['endereco' => 'array', 'pago_em' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(BeautyKoraCliente::class, 'cliente_id');
    }

    /** Formato que o site espera (era a coleção orders do PocketBase). */
    public function paraSite(): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (string) $this->cliente_id,
            'address_id' => $this->endereco_id ? (string) $this->endereco_id : null,
            'address' => $this->endereco,
            'product_id' => $this->produto_id,
            'product_name' => $this->produto_nome,
            'product_image' => $this->produto_imagem,
            'quantity' => $this->quantidade,
            'valor_pago' => $this->valor_pago_centavos,
            'status' => $this->status,
            'data_compra' => $this->created_at?->toIso8601String(),
            'pago_em' => $this->pago_em?->toIso8601String(),
        ];
    }
}
