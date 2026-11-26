<?php

namespace App\Modules\Clientes\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um acontecimento na conta do cliente (pedido do Lira 26/11/2026). Gravado
 * só por App\Modules\Clientes\Support\HistoricoDoCliente; lido pela linha do
 * tempo do admin. Nunca guarda senha, token, conteúdo digitado ou endereço
 * completo: só o tipo e o mínimo para entender o que aconteceu.
 */
class ClienteEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cliente_eventos';

    protected $fillable = ['site', 'user_id', 'ferrakora_cliente_id', 'tipo', 'detalhe', 'ip', 'dispositivo', 'created_at'];

    protected function casts(): array
    {
        return ['detalhe' => 'array', 'created_at' => 'datetime'];
    }
}
