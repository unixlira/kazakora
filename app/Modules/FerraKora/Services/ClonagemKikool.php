<?php

namespace App\Modules\FerraKora\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Clonagem do catálogo do fornecedor Kikool (Shopee + Mercado Livre) para o
 * KazaKora, ligada ao FerraKora (pedido do Lira, 2026-10-13). Cada item vem
 * do levantamento da loja da Kikool (nome, preço, fotos, descrição quando
 * houver); o Gemini completa o resto a partir do nome e da foto: título de
 * venda, descrição, categoria, NCM, peso e medidas da embalagem.
 *
 * Regras do pedido: preço = custo (preço da Kikool) + 35%, estoque 10, e não
 * clonar o que já existe (mesmo SKU KIC-... ou mesmo nome no banco).
 */
class ClonagemKikool
{
    public const MARKUP = 1.35;

    public const ESTOQUE = 10;

    public const CATEGORIAS_LOJA = ['Casa e Cozinha', 'Eletrônicos e Acessórios', 'Ferramentas e Automotivo', 'Beleza e Bem-Estar', 'Brinquedos e Games'];

    /** Mesmas categorias do menu do site (ferrakora/src/lib/produto.js). */
    public const CATEGORIAS_FERRAKORA = ['Ferramentas Elétricas', 'Ferramentas Manuais', 'Níveis Laser', 'Trenas', 'Esquadros', 'Organizadores'];

    private const MAX_FOTOS = 6;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  array{sku: string, nome: string, custo: float|int|string, fotos?: array<int, string>, descricao?: ?string, origem?: ?string}  $item
     * @return array{situacao: string, product_id?: int, nome?: string, preco?: float, erro?: string}
     */
    public function clonar(array $item): array
    {
        $sku = trim((string) $item['sku']);
        $nomeOriginal = trim((string) $item['nome']);
        $custo = round((float) $item['custo'], 2);

        $existente = Product::query()->where('sku', $sku)->orWhere('name', $nomeOriginal)->first();
        if ($existente) {
            return ['situacao' => 'ja_existe', 'product_id' => $existente->id, 'nome' => $existente->name];
        }

        if ($custo <= 0) {
            return ['situacao' => 'erro', 'erro' => 'Sem preço da Kikool.'];
        }

        $fotos = collect($item['fotos'] ?? [])->filter()->take(self::MAX_FOTOS)
            ->map(fn (string $url) => $this->baixar($url))->filter()->values();

        try {
            $dados = $this->completarComGemini($nomeOriginal, (string) ($item['descricao'] ?? ''), $fotos->first()['bytes'] ?? null);
        } catch (Throwable $e) {
            return ['situacao' => 'erro', 'erro' => 'Gemini: '.Str::limit($e->getMessage(), 160)];
        }

        $preco = round($custo * self::MARKUP, 2);

        $produto = DB::transaction(function () use ($sku, $custo, $preco, $dados, $fotos) {
            $produto = Product::query()->create([
                'category_id' => Category::query()->where('name', $dados['categoria_loja'])->value('id'),
                'sku' => $sku,
                'name' => $dados['nome'],
                'slug' => $this->slugUnico($dados['nome']),
                'description' => $dados['descricao'],
                'price' => $preco,
                'cost_price' => $custo,
                'stock' => self::ESTOQUE,
                'is_active' => true,
            ]);

            foreach ($fotos as $posicao => $foto) {
                $caminho = "products/{$produto->id}/".Str::random(24).".{$foto['extensao']}";
                Storage::disk('public')->put($caminho, $foto['bytes']);
                ProductImage::query()->create([
                    'product_id' => $produto->id,
                    'path' => ProductImageOptimizer::optimize($caminho) ?? $caminho,
                    'position' => $posicao,
                    'is_primary' => $posicao === 0,
                ]);
            }

            ProductFiscalData::query()->create([
                'product_id' => $produto->id,
                'ncm' => $dados['ncm'],
                'cfop' => '5102',
                'cfop_outros_estados' => '6102',
                // Mesmo padrão da maioria do catálogo (importado comprado no
                // Brasil, Simples Nacional CSOSN 102, PIS/COFINS 08).
                'origem' => 2,
                'unidade_tributavel' => 'UN',
                'icms_situacao_tributaria' => '102',
                'pis_situacao_tributaria' => '08',
                'cofins_situacao_tributaria' => '08',
                'peso_bruto' => $dados['peso_kg'],
                'peso_liquido' => $dados['peso_kg'],
                'altura_cm' => $dados['altura_cm'],
                'largura_cm' => $dados['largura_cm'],
                'profundidade_cm' => $dados['profundidade_cm'],
                'formato_embalagem' => $dados['formato_embalagem'],
            ]);

            FerraKoraProduto::query()->create([
                'product_id' => $produto->id,
                'categoria' => $dados['categoria_ferrakora'],
                'ordem' => (int) FerraKoraProduto::query()->max('ordem') + 1,
                'ativo' => true,
            ]);

            return $produto;
        });

        return ['situacao' => 'clonado', 'product_id' => $produto->id, 'nome' => $produto->name, 'preco' => $preco, 'fotos' => $fotos->count()];
    }

    /** @return array{bytes: string, extensao: string}|null */
    private function baixar(string $url): ?array
    {
        try {
            $resposta = Http::timeout(30)->retry(2, 500, throw: false)->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $resposta->successful() || strlen($resposta->body()) < 1000) {
            return null;
        }

        $bytes = $resposta->body();
        $extensao = match (true) {
            str_starts_with($bytes, "\x89PNG") => 'png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            default => 'jpg',
        };

        return ['bytes' => $bytes, 'extensao' => $extensao];
    }

    /** @return array<string, mixed> */
    private function completarComGemini(string $nome, string $descricao, ?string $foto): array
    {
        $partes = [];
        if ($foto !== null) {
            $mime = str_starts_with($foto, "\x89PNG") ? 'image/png' : (str_starts_with($foto, 'RIFF') ? 'image/webp' : 'image/jpeg');
            $partes[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($foto)]];
        }
        $partes[] = ['text' => $this->prompt($nome, $descricao, $foto !== null)];

        $texto = $this->gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => $partes]],
            'Você cadastra produtos numa loja virtual brasileira. Responde SOMENTE com um JSON válido, sem markdown.',
            4000,
            GeminiUsageLog::PURPOSE_CATALOGO,
        );

        $inicio = strpos($texto, '{');
        $fim = strrpos($texto, '}');
        $dados = $inicio === false || $fim === false ? null : json_decode(substr($texto, $inicio, $fim - $inicio + 1), true);

        if (! is_array($dados) || blank($dados['nome'] ?? null) || blank($dados['descricao'] ?? null)) {
            throw new RuntimeException('resposta sem JSON válido.');
        }

        $ncm = preg_replace('/\D/', '', (string) ($dados['ncm'] ?? ''));
        $numero = fn ($valor, float $minimo, float $maximo) => is_numeric($valor) && $valor >= $minimo && $valor <= $maximo ? round((float) $valor, 3) : null;

        return [
            'nome' => Str::limit(trim(preg_replace('/\s*kicool( comercial)?\s*/i', ' ', (string) $dados['nome'])), 150, ''),
            'descricao' => trim((string) $dados['descricao']),
            'categoria_loja' => in_array($dados['categoria_loja'] ?? null, self::CATEGORIAS_LOJA, true) ? $dados['categoria_loja'] : null,
            'categoria_ferrakora' => in_array($dados['categoria_ferrakora'] ?? null, self::CATEGORIAS_FERRAKORA, true) ? $dados['categoria_ferrakora'] : null,
            'ncm' => strlen($ncm) === 8 ? $ncm : null,
            'peso_kg' => $numero($dados['peso_kg'] ?? null, 0.01, 60),
            'altura_cm' => $numero($dados['altura_cm'] ?? null, 0.5, 150),
            'largura_cm' => $numero($dados['largura_cm'] ?? null, 0.5, 150),
            'profundidade_cm' => $numero($dados['profundidade_cm'] ?? null, 0.5, 150),
            'formato_embalagem' => ($dados['formato_embalagem'] ?? null) === ProductFiscalData::EMBALAGEM_ENVELOPE
                ? ProductFiscalData::EMBALAGEM_ENVELOPE : ProductFiscalData::EMBALAGEM_CAIXA,
        ];
    }

    private function prompt(string $nome, string $descricao, bool $temFoto): string
    {
        $loja = implode(' | ', self::CATEGORIAS_LOJA);
        $ferra = implode(' | ', self::CATEGORIAS_FERRAKORA);
        $foto = $temFoto ? 'A imagem anexada é a foto principal do anúncio.' : 'Não há foto.';
        $descricao = filled($descricao) ? "Descrição do fornecedor:\n".Str::limit($descricao, 3000) : 'Sem descrição do fornecedor.';

        return <<<TXT
        Cadastre este produto, copiado do anúncio de um fornecedor.

        Título do fornecedor: {$nome}
        {$descricao}
        {$foto}

        Regras:
        - Português do Brasil. Sem HTML, sem emojis, sem markdown.
        - "nome": título de venda claro, até 100 caracteres, sem a marca do fornecedor (Kicool), sem "envio imediato", "promoção", "oferta" nem preço.
        - "descricao": 3 a 6 parágrafos curtos (pode usar linhas começando com "- " para características). Use SÓ o que está no título, na descrição e na foto. Não invente medidas, potência, garantia, certificação, brindes nem números.
        - "categoria_loja": exatamente uma destas: {$loja}.
        - "categoria_ferrakora": exatamente uma destas, ou null se o produto não for ferramenta, medição ou organizador de ferramentas: {$ferra}.
        - "ncm": código NCM de 8 dígitos mais provável para o produto (só números).
        - Peso e medidas: estimativa realista do produto JÁ EMBALADO para envio (kg e cm).
        - "formato_embalagem": "envelope" só para itens finos e leves que vão em envelope; senão "caixa".

        Formato (JSON):
        {"nome": "...", "descricao": "...", "categoria_loja": "...", "categoria_ferrakora": null, "ncm": "00000000", "peso_kg": 0.5, "altura_cm": 10, "largura_cm": 15, "profundidade_cm": 20, "formato_embalagem": "caixa"}
        TXT;
    }

    private function slugUnico(string $nome): string
    {
        $base = Str::slug($nome);
        $slug = $base;
        $sufixo = 1;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$sufixo;
        }

        return $slug;
    }
}
