<?php

namespace App\Modules\Catalog\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Vender › Promoções (pedido 2026-10-21): regras da Promoção Airfryer e da
 * Promoção PDFs, definidas pelo Lira no admin. `regras` = chaves livres
 * (chave => valor). Por ora só guarda a configuração; quem aplica a regra
 * (checkout, página do produto) lê daqui. `titulo` e `descricao` aparecem na
 * página pública /promocoes/{slug}; a descrição é texto puro, linha em branco
 * separa parágrafos e quebra simples continua quebra.
 *
 * @property int $id
 * @property string $slug
 * @property string $nome
 * @property ?string $titulo
 * @property ?string $descricao
 * @property bool $ativa
 * @property ?Carbon $inicio
 * @property ?Carbon $fim
 * @property ?string $valor_minimo
 * @property ?array<string, string> $regras
 * @property ?string $observacoes
 */
class Promocao extends Model
{
    use Auditable;

    protected $table = 'promocoes';

    protected $fillable = ['slug', 'nome', 'titulo', 'descricao', 'ativa', 'inicio', 'fim', 'valor_minimo', 'regras', 'observacoes'];

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

    /** No ar na página pública: ativa e dentro do período (sem data = sem limite). */
    public function noAr(): bool
    {
        return $this->ativa
            && ($this->inicio === null || $this->inicio->isPast())
            && ($this->fim === null || $this->fim->isFuture());
    }

    /**
     * Parágrafos da descrição (separados por linha em branco). Cada um mantém
     * as quebras simples, que a página mostra com white-space: pre-line.
     *
     * @return list<string>
     */
    public function paragrafos(): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", (string) $this->descricao);

        return array_values(array_filter(
            array_map(fn (string $p) => trim($p, "\n "), preg_split('/\n[ \t]*\n/', $texto)),
            fn (string $p) => $p !== '',
        ));
    }
}
