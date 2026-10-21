<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Vender › Promoções (pedido 2026-10-21): regras da Promoção Airfryer e da
 * Promoção PDFs, definidas pelo Lira no admin. `regras` = chaves livres
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
 * Texto e regras (2026-10-21): regras de negócio com campo próprio
 * (`itens_minimos`, `limite_por_cliente`, `primeira_compra`, `acumula_cupom`),
 * mostradas ao cliente na página pública e checadas por `pendencias()`. A
 * página pública lê de `doSlug()`, que fica em cache até a promoção mudar.
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
 * @property ?int $itens_minimos
 * @property ?int $limite_por_cliente
 * @property bool $primeira_compra
 * @property bool $acumula_cupom
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

    private const CACHE_TTL = 21600; // 6 h; salvar a promoção é que limpa de verdade.

    protected $fillable = ['slug', 'nome', 'titulo', 'descricao', 'ativa', 'na_pagina_produto', 'todos_produtos', 'imagem', 'inicio', 'fim', 'valor_minimo', 'itens_minimos', 'limite_por_cliente', 'primeira_compra', 'acumula_cupom', 'regras', 'observacoes'];

    protected function casts(): array
    {
        return [
            'ativa' => 'boolean',
            'na_pagina_produto' => 'boolean',
            'todos_produtos' => 'boolean',
            'inicio' => 'datetime',
            'fim' => 'datetime',
            'valor_minimo' => 'decimal:2',
            'itens_minimos' => 'integer',
            'limite_por_cliente' => 'integer',
            'primeira_compra' => 'boolean',
            'acumula_cupom' => 'boolean',
            'regras' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Promocao $promocao) {
            PromocoesDoProduto::limparCache();
            Cache::forget(self::chaveCache($promocao->slug));
        });
        static::deleted(function (Promocao $promocao) {
            PromocoesDoProduto::limparCache();
            Cache::forget(self::chaveCache($promocao->slug));
        });
    }

    /**
     * Promoção pelo endereço, em cache (página pública abre sem ir ao banco).
     * Guarda só as colunas: o cache não desserializa objetos
     * (config/cache.php serializable_classes = false).
     */
    public static function doSlug(string $slug): ?self
    {
        $colunas = Cache::remember(self::chaveCache($slug), self::CACHE_TTL, fn () => self::query()->where('slug', $slug)->first()?->getAttributes());

        return is_array($colunas) ? (new self)->newFromBuilder($colunas) : null;
    }

    private static function chaveCache(string $slug): string
    {
        return 'promocao:v2:'.$slug;
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

    /**
     * Regras em frases para o cliente (página pública). Só as preenchidas.
     *
     * @return list<string>
     */
    public function regrasParaCliente(): array
    {
        $regras = [];

        if ($this->inicio !== null && $this->fim !== null) {
            $regras[] = "Válida de {$this->inicio->format('d/m/Y H:i')} até {$this->fim->format('d/m/Y H:i')}.";
        } elseif ($this->fim !== null) {
            $regras[] = "Válida até {$this->fim->format('d/m/Y H:i')}.";
        } elseif ($this->inicio !== null) {
            $regras[] = "Válida a partir de {$this->inicio->format('d/m/Y H:i')}.";
        }
        if ((float) $this->valor_minimo > 0) {
            $regras[] = 'Pedido mínimo de R$ '.number_format((float) $this->valor_minimo, 2, ',', '.').'.';
        }
        if ($this->itens_minimos > 1) {
            $regras[] = "Mínimo de {$this->itens_minimos} itens no pedido.";
        }
        if ($this->limite_por_cliente > 0) {
            $regras[] = $this->limite_por_cliente === 1 ? 'Uma participação por cliente.' : "Até {$this->limite_por_cliente} participações por cliente.";
        }
        if ($this->primeira_compra) {
            $regras[] = 'Só na primeira compra na loja.';
        }
        if (! $this->acumula_cupom) {
            $regras[] = 'Não vale junto com cupom de desconto.';
        }

        return $regras;
    }

    /**
     * O que falta para um pedido entrar na promoção (vazio = entra). Para quem
     * aplica a regra (checkout, sorteio): passa o que já sabe do pedido.
     *
     * @return list<string>
     */
    public function pendencias(float $subtotal, int $itens, int $participacoesDoCliente = 0, bool $primeiraCompra = true, bool $comCupom = false): array
    {
        $faltas = [];

        if (! $this->noAr()) {
            $faltas[] = 'Promoção fora do ar.';
        }
        if ((float) $this->valor_minimo > 0 && $subtotal < (float) $this->valor_minimo) {
            $faltas[] = 'Pedido abaixo do mínimo de R$ '.number_format((float) $this->valor_minimo, 2, ',', '.').'.';
        }
        if ($this->itens_minimos > 1 && $itens < $this->itens_minimos) {
            $faltas[] = "Pedido com menos de {$this->itens_minimos} itens.";
        }
        if ($this->limite_por_cliente > 0 && $participacoesDoCliente >= $this->limite_por_cliente) {
            $faltas[] = 'Cliente já atingiu o limite de participações.';
        }
        if ($this->primeira_compra && ! $primeiraCompra) {
            $faltas[] = 'Só vale na primeira compra.';
        }
        if (! $this->acumula_cupom && $comCupom) {
            $faltas[] = 'Não vale junto com cupom.';
        }

        return $faltas;
    }
}
