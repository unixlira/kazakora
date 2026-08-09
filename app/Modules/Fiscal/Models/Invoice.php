<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_SENT = 'sent';

    public const STATUS_AUTHORIZED = 'authorized';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DENIED = 'denied';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_ERROR = 'error';

    // Canal de origem já emite a própria NF-e (confirmado ao vivo pro
    // Mercado Livre, 2026-08-02 — chave de acesso real, CNPJ e CPF do
    // pedido batendo, emitida pelo "Faturador" deles) — Kazakora
    // deliberadamente não tenta emitir de novo pra não duplicar nota
    // fiscal da mesma venda. Ver InvoiceService::issue().
    public const STATUS_EXTERNAL = 'external';

    public const AMBIENTE_PRODUCAO = 'producao';

    public const AMBIENTE_HOMOLOGACAO = 'homologacao';

    // 'pedido' = fluxo normal, sempre tem order_id (automático ao pagar, ou
    // emissão manual pra um Order existente). 'sefaz' = trazida pela
    // sincronização de Distribuição DFe (ver NFeDistribuicaoService), sem
    // Order local correspondente — destinatário fica em destinatario_nome/
    // destinatario_documento em vez de vir de order.shipping_name/user.
    public const ORIGEM_PEDIDO = 'pedido';

    public const ORIGEM_SEFAZ = 'sefaz';

    protected $fillable = [
        'order_id',
        'origem',
        'destinatario_nome',
        'destinatario_documento',
        'nsu',
        'status',
        'ambiente',
        'serie',
        'numero',
        'valor_total',
        'chave_acesso',
        'protocolo_autorizacao',
        'autorizada_em',
        'motivo_rejeicao',
        'xml_path',
        'danfe_path',
        'protocolo_cancelamento',
        'motivo_cancelamento',
        'cancelada_em',
    ];

    protected function casts(): array
    {
        return [
            'serie' => 'integer',
            'numero' => 'integer',
            'valor_total' => 'decimal:2',
            'autorizada_em' => 'datetime',
            'cancelada_em' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
