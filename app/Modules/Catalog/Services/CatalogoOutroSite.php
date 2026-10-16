<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Support\SiteAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo de um site irmão vindo do KazaKora (Beauty Kora 2026-10-12,
 * FerraKora 2026-10-13). O produto é cadastrado no KazaKora (pai +
 * variações), enviado em JSON para POST /api/{site}/produtos e o site lê
 * GET /api/{site}/produtos no MESMO formato da vitrine da Hostinger, para o
 * front usar os mesmos extratores. Ids: "kz-<pai>" no produto e
 * "kzv-<variação>" na variação. Preço é o de cadastro (sem o frete embutido
 * da loja KazaKora), a não ser no site que liga comFreteEmbutido() (FerraKora,
 * 2026-10-13; Beauty Kora, 2026-10-15): aí vale o mesmo final_price da página
 * do KazaKora para a região do visitante, mais o acréscimo do site (acrescimo()).
 *
 * Cada site tem a sua tabela (qual produto aparece, título, ordem, ativo) e
 * pode acrescentar campos próprios no item recebido e no produto devolvido.
 *
 * Oferta Relâmpago (pedido 2026-10-14): o produto sai montado com o site
 * dele em SiteAtual, então o final_price já traz a relâmpago só deste site;
 * "oferta_relampago" no produto diz qual é ({id, inicio, fim, desconto_pct})
 * ou null, e o preço sem ela vai riscado em prices[0].amount.
 */
abstract class CatalogoOutroSite
{
    public const PREFIXO_PRODUTO = 'kz-';

    public const PREFIXO_VARIACAO = 'kzv-';

    private const MOEDA = [
        'code' => 'brl',
        'symbol' => 'R$',
        'symbol_native' => 'R$',
        'name' => 'Brazilian Real',
        'decimal_digits' => 2,
        'template' => 'R$$1',
    ];

    /** @return class-string<Model> model da tabela do site (product_id, titulo, ordem, ativo) */
    abstract protected function modelo(): string;

    /** Site do catálogo em SiteAtual (ferrakora, beautykora): define a Oferta Relâmpago que vale. */
    abstract public function site(): string;

    /** Colunas próprias do site gravadas a partir do item do JSON (ex.: categoria). */
    protected function camposDoItem(array $item): array
    {
        return [];
    }

    /**
     * true = preço igual ao da loja KazaKora (final_price com o frete embutido
     * da região do visitante e a oferta do dia). Quem liga também tem que
     * ligar o FreteEmbutido na requisição (FerraKoraCatalogoController).
     */
    protected function comFreteEmbutido(): bool
    {
        return false;
    }

    /**
     * Acréscimo sobre o preço original do anúncio (0.05 = 5%), por produto do
     * site. Beauty Kora: produto clonado (2026-10-15). O frete embutido fica
     * fora da conta: o acréscimo é só sobre o anúncio.
     */
    protected function acrescimo(Model $registro): float
    {
        return 0.0;
    }

    /**
     * Preço com o acréscimo sobre a parte do anúncio (sem o frete embutido).
     * O checkout usa a mesma conta, para cobrar o que o card mostrou.
     */
    public static function comAcrescimo(Product $produto, float $valor, float $acrescimo): float
    {
        if ($acrescimo <= 0) {
            return $valor;
        }

        $frete = $produto->freteEmbutido();

        return round($frete + ($valor - $frete) * (1 + $acrescimo), 2);
    }

    /** Campos próprios do site acrescentados no fim do produto devolvido. */
    protected function camposExtras(Model $registro, Product $pai): array
    {
        return [];
    }

    /**
     * Liga os produtos do JSON ao site. Cada item aponta um produto pelo SKU
     * (pai ou qualquer variação; vale sempre o pai). SKU que não existe não
     * grava nada: devolve a lista para quem enviou corrigir.
     *
     * @param  array<int, array{sku: string, titulo?: ?string, ordem?: ?int, ativo?: ?bool}>  $itens
     * @return array{gravados: array<int, array<string, mixed>>, nao_encontrados: array<int, string>}
     */
    public function sincronizar(array $itens): array
    {
        $skus = collect($itens)->pluck('sku')->map(fn ($sku) => trim((string) $sku))->unique()->values();
        $produtos = Product::query()->whereIn('sku', $skus)->get()->keyBy('sku');
        $faltando = $skus->reject(fn ($sku) => $produtos->has($sku))->values()->all();

        if ($faltando !== []) {
            return ['gravados' => [], 'nao_encontrados' => $faltando];
        }

        $gravados = DB::transaction(function () use ($itens, $produtos) {
            return collect($itens)->map(function (array $item, int $posicao) use ($produtos) {
                $produto = $produtos[trim((string) $item['sku'])];
                $paiId = $produto->parent_product_id ?? $produto->id;
                $proprios = $this->camposDoItem($item);

                $registro = $this->modelo()::query()->updateOrCreate(
                    ['product_id' => $paiId],
                    [
                        'titulo' => filled($item['titulo'] ?? null) ? trim((string) $item['titulo']) : null,
                        'ordem' => (int) ($item['ordem'] ?? $posicao + 1),
                        'ativo' => (bool) ($item['ativo'] ?? true),
                        ...$proprios,
                    ],
                );

                $vitrine = $this->formatar($registro->load('product'));

                return [
                    'id' => self::PREFIXO_PRODUTO.$paiId,
                    'sku' => (string) $registro->product->sku,
                    'titulo' => $vitrine['title'] ?? '',
                    'variacoes' => count($vitrine['variants'] ?? []),
                    'ativo' => $registro->ativo,
                    ...$proprios,
                ];
            })->values()->all();
        });

        return ['gravados' => $gravados, 'nao_encontrados' => []];
    }

    /** @return array<int, array<string, mixed>> produtos ativos, já no formato da vitrine da Hostinger */
    public function produtos(): array
    {
        return $this->publicados()->get()
            ->map(fn (Model $registro) => $this->formatar($registro))
            ->filter(fn (?array $produto) => $produto !== null && $produto['variants'] !== [])
            ->values()
            ->all();
    }

    /** Um produto por "kz-<id>" ou pelo slug. null = não está no site. */
    public function produto(string $idOuSlug): ?array
    {
        return collect($this->produtos())
            ->first(fn (array $produto) => $produto['id'] === $idOuSlug || $produto['slug'] === $idOuSlug);
    }

    private function publicados(): Builder
    {
        return $this->modelo()::query()
            ->where('ativo', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with(['product.images', 'product.children' => fn ($query) => $query->where('is_active', true)->orderBy('id')->with('images')])
            ->orderBy('ordem')
            ->orderBy('id');
    }

    /** Pai + variações no formato de GET /store/{id}/products da Hostinger, com os preços deste site. */
    private function formatar(Model $registro): ?array
    {
        return app(SiteAtual::class)->durante($this->site(), fn () => $this->montar($registro));
    }

    private function montar(Model $registro): ?array
    {
        /** @var Product|null $pai */
        $pai = $registro->product;

        if ($pai === null) {
            return null;
        }

        /** @var Collection<int, Product> $grupo */
        $grupo = collect([$pai])->merge($pai->children ?? collect())->filter(fn (Product $produto) => $produto->is_active)->values();
        $temOpcao = $grupo->count() > 1;
        $opcaoId = 'kzo-'.$pai->id;
        $acrescimo = $this->acrescimo($registro);

        $variantes = $grupo->map(function (Product $produto) use ($pai, $temOpcao, $opcaoId, $acrescimo) {
            [$preco, $final] = $this->precos($produto, $acrescimo);
            $id = self::PREFIXO_VARIACAO.$produto->id;

            return [
                'id' => $id,
                'title' => $this->rotulo($produto, $pai),
                'image_url' => $produto->images->first()?->url,
                'sku' => (string) $produto->sku,
                'manage_inventory' => true,
                'inventory_quantity' => max(0, (int) $produto->stock),
                'is_available' => (int) $produto->stock > 0,
                'prices' => [[
                    'amount' => $preco,
                    'sale_amount' => $final < $preco ? $final : null,
                    'currency_code' => 'brl',
                    'currency' => self::MOEDA,
                ]],
                'options' => $temOpcao ? [[
                    'id' => 'kzov-'.$produto->id,
                    'option_id' => $opcaoId,
                    'variant_id' => $id,
                    'value' => $this->rotulo($produto, $pai),
                ]] : [],
            ];
        })->values();

        // thumb_url (WebP 512 px) e media_url (WebP média) vão junto da
        // original (2026-10-15): o card e a miniatura não precisam baixar a
        // foto cheia. Sem a versão leve gerada, as duas caem na original.
        $imagens = collect($pai->images->all())
            ->merge($grupo->skip(1)->map(fn (Product $produto) => $produto->images->first()))
            ->filter()
            ->unique(fn ($imagem) => $imagem->url)
            ->values()
            ->map(fn ($imagem, int $ordem) => [
                'url' => $imagem->url,
                'thumb_url' => $imagem->thumb_url,
                'media_url' => $imagem->media_url,
                'order' => $ordem,
                'type' => 'image',
            ])
            ->all();

        $titulo = $registro->titulo ?: $this->tituloDoGrupo($pai, $temOpcao);

        return [
            'id' => self::PREFIXO_PRODUTO.$pai->id,
            'title' => $titulo,
            'subtitle' => null,
            'ribbon_text' => null,
            'description' => $this->descricaoHtml((string) $pai->description),
            'thumbnail' => $imagens[0]['url'] ?? null,
            'thumbnail_leve' => $imagens[0]['thumb_url'] ?? null,
            'slug' => Str::slug($titulo),
            'order' => $registro->ordem,
            'site_product_selection' => null,
            'purchasable' => true,
            'is_available' => $variantes->contains('is_available', true),
            'images' => $imagens,
            'media' => $imagens,
            'options' => $temOpcao ? [[
                'id' => $opcaoId,
                'title' => $this->nomeDaOpcao($pai),
                'values' => $variantes->map(fn (array $variante) => $variante['options'][0])->all(),
            ]] : [],
            'variants' => $variantes->all(),
            'product_collections' => [],
            'additional_info' => [],
            'custom_fields' => [],
            'related_products' => [],
            'type' => ['value' => 'physical'],
            'seo_settings' => null,
            'origem' => 'kazakora',
            'updated_at' => $grupo->max('updated_at')?->toIso8601String(),
            'oferta_relampago' => $pai->relampagoNoAr()?->paraApi(),
            ...$this->camposExtras($registro, $pai),
        ];
    }

    /**
     * Preço cheio e preço de venda em centavos.
     *
     * Sem frete embutido: cadastro e preço sem a oferta do dia (Beauty Kora),
     * com a Oferta Relâmpago do site quando houver (2026-10-14).
     * Com frete embutido: os mesmos números do card da loja KazaKora, o
     * final_price (frete da região + oferta do dia) e, riscado, o preço sem a
     * oferta ou o preço "de" do desconto, ambos com o mesmo frete. Oferta
     * Relâmpago (2026-10-14) entra no final_price e conta como oferta: riscado
     * fica o preço sem ela.
     * Os dois levam o acréscimo do site (acrescimo()), só sobre o anúncio.
     *
     * @return array{0: int, 1: int}
     */
    private function precos(Product $produto, float $acrescimo = 0.0): array
    {
        if (! $this->comFreteEmbutido()) {
            $cheio = self::comAcrescimo($produto, (float) $produto->price, $acrescimo);
            $final = self::comAcrescimo($produto, min($produto->precoSemOferta(), $produto->precoRelampago() ?? INF), $acrescimo);

            return [(int) round($cheio * 100), (int) round($final * 100)];
        }

        $final = (float) $produto->final_price;
        $cheio = $produto->preco_sem_oferta ?? $produto->preco_de ?? $final;
        $final = self::comAcrescimo($produto, $final, $acrescimo);
        $cheio = self::comAcrescimo($produto, (float) $cheio, $acrescimo);

        return [(int) round(max($cheio, $final) * 100), (int) round($final * 100)];
    }

    /** "Tom 01", "Cor 340"; sem variação cadastrada, o nome do produto. */
    private function rotulo(Product $produto, Product $pai): string
    {
        return trim((string) ($produto->variation ?: $produto->color ?: $produto->name ?: $pai->name));
    }

    /** O pai carrega a cor dele no nome ("... Cor 340"): o título do grupo sai sem ela. */
    private function tituloDoGrupo(Product $pai, bool $temOpcao): string
    {
        $nome = trim((string) $pai->name);
        $variacao = trim((string) $pai->variation);

        if ($temOpcao && $variacao !== '' && Str::endsWith(Str::lower($nome), Str::lower($variacao))) {
            return rtrim(Str::substr($nome, 0, Str::length($nome) - Str::length($variacao)), ' -–');
        }

        return $nome;
    }

    private function nomeDaOpcao(Product $pai): string
    {
        $primeira = Str::lower(Str::before(trim((string) $pai->variation), ' '));

        return match ($primeira) {
            'tom' => 'Tom',
            'cor' => 'Cor',
            default => 'Opção',
        };
    }

    /** Descrição do KazaKora é texto; o site espera HTML (um parágrafo por linha). */
    private function descricaoHtml(string $texto): string
    {
        if ($texto !== strip_tags($texto)) {
            return $texto;
        }

        return collect(preg_split('/\R/', $texto))
            ->map(fn ($linha) => trim($linha))
            ->filter()
            ->map(fn ($linha) => '<p>'.e($linha).'</p>')
            ->implode('');
    }
}
