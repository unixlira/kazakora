<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Support\FreteEmbutido;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Feed de produtos do Google Merchant Center (pedido 2026-11-23, suspensão
 * por deturpação): RSS 2.0 com o namespace g:, os mesmos produtos do
 * sitemap (ativos na loja KazaKora, sem as variações, que são a página do
 * pai) com preço > 0 e foto.
 *
 * Preço: o mesmo final_price da página do produto (oferta do dia e
 * relâmpago incluídas, com o "de" riscado em g:price e o preço da tela em
 * g:sale_price). O frete embutido é o do visitante SEM região (sem CEP e
 * sem estado pelo IP), que é o que o robô do Google vê de fora do Brasil
 * na página e no JSON-LD: o feed nunca pode sair com o preço da região de
 * quem abriu o link primeiro. Frete sempre 0.00 BRL (frete grátis).
 *
 * Cache de 1 hora.
 */
class GoogleMerchantFeedController
{
    public const CACHE = 'feeds.google.xml';

    private const MAX_FOTOS_EXTRAS = 10;

    public function __invoke(): Response
    {
        $xml = Cache::remember(self::CACHE, now()->addHour(), fn () => $this->montar());

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function montar(): string
    {
        // Preço de quem não tem região: serviço novo, de uma requisição vazia.
        $frete = new FreteEmbutido(Request::create('/'));
        $frete->ligar();
        app()->instance(FreteEmbutido::class, $frete);

        $base = rtrim((string) config('app.url'), '/');

        $linhas = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">',
            '<channel>',
            '<title>KazaKora</title>',
            '<link>'.self::esc($base.'/').'</link>',
            '<description>Produtos da loja KazaKora</description>',
        ];

        Product::query()
            ->naKazaKora()
            ->whereNull('parent_product_id')
            ->whereNotNull('slug')
            ->where('price', '>', 0)
            ->with([
                'images' => fn ($images) => $images->reorder()->orderByDesc('is_primary')->orderBy('position')->orderBy('id'),
                'fiscalData:id,product_id,gtin',
            ])
            ->orderBy('id')
            ->chunk(200, function ($produtos) use (&$linhas, $base) {
                foreach ($produtos as $produto) {
                    if ($item = $this->item($produto, $base)) {
                        $linhas[] = $item;
                    }
                }
            });

        $linhas[] = '</channel>';
        $linhas[] = '</rss>';

        return implode("\n", $linhas)."\n";
    }

    private function item(Product $produto, string $base): ?string
    {
        $fotos = $produto->images
            ->map(fn ($foto) => $base.'/storage/'.ltrim((string) preg_replace('#^storage/#', '', (string) $foto->path), '/'))
            ->filter(fn ($url) => ! str_ends_with($url, '/storage/'))
            ->unique()
            ->values();

        $preco = round((float) $produto->final_price, 2);
        if ($preco <= 0 || $fotos->isEmpty()) {
            return null;
        }

        // Mesmo "de" riscado da página (ProductDetailV2: precoRiscado).
        $riscado = $produto->emOferta() ? $produto->preco_sem_oferta : $produto->preco_de;
        $riscado = $riscado !== null ? round((float) $riscado, 2) : null;
        $emPromocao = $riscado !== null && $riscado > $preco;

        $descricao = self::textoPuro((string) $produto->description)
            ?: self::textoPuro((string) $produto->seo_description)
            ?: (string) $produto->name;

        $gtin = self::gtinValido($produto->fiscalData?->gtin);

        $campos = [
            'g:id' => (string) $produto->id,
            'g:title' => mb_substr(trim((string) $produto->name), 0, 150),
            'g:description' => mb_substr($descricao, 0, 5000),
            'g:link' => $base.'/produtos/'.$produto->slug,
            'g:image_link' => $fotos->first(),
        ];

        $xml = ['<item>'];
        foreach ($campos as $tag => $valor) {
            $xml[] = "<{$tag}>".self::esc($valor)."</{$tag}>";
        }
        foreach ($fotos->slice(1, self::MAX_FOTOS_EXTRAS) as $foto) {
            $xml[] = '<g:additional_image_link>'.self::esc($foto).'</g:additional_image_link>';
        }
        $xml[] = '<g:availability>'.((int) $produto->stock > 0 ? 'in_stock' : 'out_of_stock').'</g:availability>';
        $xml[] = '<g:price>'.self::valor($emPromocao ? $riscado : $preco).'</g:price>';
        if ($emPromocao) {
            $xml[] = '<g:sale_price>'.self::valor($preco).'</g:sale_price>';
        }
        $xml[] = '<g:brand>'.self::esc(filled($produto->brand) ? trim((string) $produto->brand) : 'KazaKora').'</g:brand>';
        $xml[] = $gtin ? '<g:gtin>'.$gtin.'</g:gtin>' : '<g:identifier_exists>no</g:identifier_exists>';
        $xml[] = '<g:condition>new</g:condition>';
        $xml[] = '<g:shipping><g:country>BR</g:country><g:price>0.00 BRL</g:price></g:shipping>';
        $xml[] = '</item>';

        return implode('', $xml);
    }

    private static function valor(float $valor): string
    {
        return number_format($valor, 2, '.', '').' BRL';
    }

    /** HTML da descrição em texto corrido, sem tags, entidades nem caracteres proibidos no XML. */
    public static function textoPuro(string $html): string
    {
        $texto = preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $html) ?? $html;
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $texto) ?? '';

        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }

    /** GTIN/EAN com 8, 12, 13 ou 14 dígitos e dígito verificador certo; null fora disso. */
    public static function gtinValido(?string $gtin): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $gtin) ?? '';
        if (! in_array(strlen($digitos), [8, 12, 13, 14], true) || ltrim($digitos, '0') === '') {
            return null;
        }

        $corpo = substr($digitos, 0, -1);
        $soma = 0;
        foreach (array_reverse(str_split($corpo)) as $i => $d) {
            $soma += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $soma % 10) % 10 === (int) substr($digitos, -1) ? $digitos : null;
    }

    private static function esc(string $valor): string
    {
        $valor = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $valor) ?? '';

        return htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
