<?php

namespace App\Modules\Marketplace\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;

class MarketplaceAccount extends Model
{
    use Auditable;

    public const CHANNEL_MERCADO_LIVRE = 'mercado_livre';

    public const CHANNEL_SHOPEE = 'shopee';

    public const CHANNEL_TIKTOK_SHOP = 'tiktok_shop';

    public const CHANNEL_AMAZON = 'amazon';

    public const CHANNEL_SHEIN = 'shein';

    // Não é um canal de venda em si — é a conta OAuth do Bling, usada como
    // ponte pro TikTok Shop (ver BlingAuthService). Pedidos importados por
    // ela continuam sendo gravados com origin=CHANNEL_TIKTOK_SHOP; esta
    // constante só existe pra rastrear separadamente "o Bling está
    // conectado" na mesma tabela/tela que os outros canais.
    public const CHANNEL_BLING = 'bling';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'channel',
        'status',
        'seller_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
        'metadata',
    ];

    // Conectar/desconectar um canal de venda é uma ação sensível (credencial
    // de acesso a pedidos/dados de cliente reais) — passa a ser auditada
    // (audit_logs, mesma tabela que o KoraSync consulta) como qualquer outra
    // ação administrativa do sistema. Tokens nunca vão pro log em claro.
    public static array $auditExcept = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
