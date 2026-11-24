<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trava do deploy (24/11/2026): a página de produto deu 500 em produção por
 * uma regex que o PCRE do servidor não conhece, e o /up não percebeu.
 */
class PaginasParaConferirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_lista_home_carrinho_e_paginas_de_produto_que_abrem(): void
    {
        $comConteudo = Product::factory()->create(['is_active' => true, 'so_ferrakora' => false]);
        ProductAdContent::create([
            'product_id' => $comConteudo->id,
            'fonte' => ProductAdContent::FONTE_GEMINI,
            'destaques' => ['Fura e parafusa sem fio'],
            'beneficios' => [['Torque de 35 Nm', 'x']],
        ]);
        $recente = Product::factory()->create(['is_active' => true, 'so_ferrakora' => false]);
        Product::factory()->create(['is_active' => false]);

        $this->artisan('loja:paginas-para-conferir')
            ->expectsOutput('/')
            ->expectsOutput('/carrinho')
            ->expectsOutput('/produtos/'.$comConteudo->slug)
            ->expectsOutput('/produtos/'.$recente->slug)
            ->assertSuccessful();

        foreach (['/', '/carrinho', '/produtos/'.$comConteudo->slug, '/produtos/'.$recente->slug] as $caminho) {
            $this->get($caminho)->assertOk();
        }
    }

    public function test_codigo_nao_usa_propriedade_unicode_que_o_pcre_do_servidor_nao_conhece(): void
    {
        // PCRE 10.39 em produção: \p{Extended_Pictographic} quebra o preg_* (erro 500).
        $proibidas = ['Extended_Pictographic', 'Emoji_Presentation', 'Emoji_Modifier', 'Emoji_Component'];
        $achados = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($it as $arquivo) {
            if (! $arquivo->isFile() || $arquivo->getExtension() !== 'php') {
                continue;
            }
            // Só o código: comentários que explicam o problema não contam.
            $codigo = collect(token_get_all(file_get_contents($arquivo->getPathname())))
                ->reject(fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn ($t) => is_array($t) ? $t[1] : $t)
                ->implode('');
            foreach ($proibidas as $p) {
                if (str_contains($codigo, '\\p{'.$p) || str_contains($codigo, '\\P{'.$p)) {
                    $achados[] = $arquivo->getPathname().': '.$p;
                }
            }
        }

        $this->assertSame([], $achados, 'Use faixas \\x{...} explícitas: o PCRE do servidor não conhece essas propriedades.');
    }
}
