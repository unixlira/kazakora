<?php

namespace App\Modules\Catalog\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Vender › Promoções (pedido 2026-10-21): regras da Promoção Airfryer e da
 * Promoção PDFs, definidas pelo Lira no admin. `regras` = chaves livres
 * (chave => valor). Por ora só guarda a configuração; quem aplica a regra
 * (checkout, página do produto) lê daqui.
 *
 * @property int $id
 * @property string $slug
 * @property string $nome
 * @property bool $ativa
 * @property ?\Illuminate\Support\Carbon $inicio
 * @property ?\Illuminate\Support\Carbon $fim
 * @property ?string $valor_minimo
 * @property ?array<string, string> $regras
 * @property ?string $observacoes
 */
class Promocao extends Model
{
    use Auditable;

    protected $table = 'promocoes';

    protected $fillable = ['slug', 'nome', 'ativa', 'inicio', 'fim', 'valor_minimo', 'regras', 'observacoes'];

    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'valor_minimo' => 'decimal:2',
            'regras' => 'array',
        ];
    }
}
