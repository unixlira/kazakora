<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Promoções (pedido 2026-10-21): regras da Promoção Airfryer, da Promoção
 * PDFs e das que o Lira cria em "Adicionar Promoção", definidas no admin. `regras` = chaves livres
 * (chave => valor). Por ora só guarda a configuração; quem aplica a regra
 * (checkout, página do produto) lê daqui. `titulo` e `descricao` aparecem na
 * página pública /promocoes/{slug}; a descrição é texto puro, linha em branco
 * separa parágrafos e quebra simples continua quebra.
 *
 * Na página do produto (2026-10-21): Airfryer = quadro do sorteio, PDFs =
 * quadro dos livros. `na_pagina_produto` liga/desliga o quadro, `imagem`
 * troca a imagem dele e `produtos` diz em quais produtos aparece
 * (ver PromocoesDoProduto).
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
 * @property bool $na_pagina_produto
 * @property bool $todos_produtos
 * @property ?string $imagem
 */
class Promocao extends Model
{
    use Auditable;

    protected $table = 'promocoes';

    public const AIRFRYER = 'airfryer';

    public const PDFS = 'pdfs';

    protected $fillable = ['slug', 'nome', 'titulo', 'descricao', 'ativa', 'na_pagina_produto', 'todos_produtos', 'imagem', 'inicio', 'fim', 'valor_minimo', 'regras', 'observacoes'];

    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'na_pagina_produto' => 'boolean',
            'todos_produtos' => 'boolean',
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'valor_minimo' => 'decimal:2',
            'regras' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PromocoesDoProduto::limparCache());
        static::deleted(fn () => PromocoesDoProduto::limparCache());
    }

    public function produtos(): HasMany
    {
        return $this->hasMany(PromocaoProduto::class);
    }

    /** Imagem que veio do repositório (public/images), não de upload. */
    public function imagemDoRepositorio(): bool
    {
        return $this->imagem !== null && str_starts_with($this->imagem, 'images/');
    }

    /** Imagem própria do quadro na página do produto; null = a de Entregáveis. */
    public function getImagemUrlAttribute(): ?string
    {
        if ($this->imagem === null) {
            return null;
        }

        return $this->imagemDoRepositorio() ? asset($this->imagem) : asset('storage/'.$this->imagem);
    }

    /**
     * O quadro aparece na página deste produto (pai)? A linha do produto vale
     * como exceção em "todos os produtos" e como escolha em "só os escolhidos".
     */
    public function exibeNoProduto(?PromocaoProduto $ligacao): bool
    {
        if (! $this->na_pagina_produto) {
            return false;
        }

        return $this->todos_produtos ? ($ligacao?->ativo ?? true) : ($ligacao?->ativo === true);
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
