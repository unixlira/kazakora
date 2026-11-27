<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Support\FaixaDoTopo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Faixa rolante do topo (pedido do Lira 2026-11-24): na página de produto,
 * frases curtas de benefício daquele produto; no resto da loja, as gerais;
 * na Black Friday a frase dela vem primeiro e as outras continuam.
 */
class FaixaDoTopoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['loja.blackfriday_ate' => '']);
    }

    private function conteudo(Product $product): ProductAdContent
    {
        return ProductAdContent::create([
            'product_id' => $product->id,
            'fonte' => ProductAdContent::FONTE_GEMINI,
            'destaques' => [
                '🔋 2ª BATERIA GRÁTIS: trabalhe o dobro sem parar para carregar',
                '🛠️ Fura e parafusa 48V sem fio, com maleta, brocas e pontas',
                '📦 Kit com 2 unidades',
            ],
            'beneficios' => [
                ['⚡ Força e Eficiência', 'Fura madeira e metal.'],
                ['Kit com 2 unidades', 'Repetido nos destaques.'],
                ['Torque de 35 Nm', 'Aperta sem esforço.'],
                ['Uma frase de benefício comprida demais para caber inteira na faixa', 'x'],
                ['Maleta rígida', 'Guarda tudo junto.'],
            ],
        ]);
    }

    private function textos(array $faixa): array
    {
        return array_column($faixa, 'texto');
    }

    public function test_frases_do_produto_curtas_sem_emoji_e_sem_repetir(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $frases = FaixaDoTopo::frasesDoProduto($this->conteudo($product));

        $this->assertSame([
            '2ª BATERIA GRÁTIS',
            'Fura e parafusa 48V sem fio',
            'Kit com 2 unidades',
            'Força e Eficiência',
            'Torque de 35 Nm',
            'Maleta rígida',
        ], $frases);

        foreach ($frases as $frase) {
            $this->assertLessThanOrEqual(FaixaDoTopo::MAX_CARACTERES, mb_strlen($frase));
        }
    }

    public function test_pagina_de_produto_com_conteudo_mostra_os_beneficios_dele(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $this->conteudo($product);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('faixa.0.texto', '2ª BATERIA GRÁTIS')
                ->where('faixa', fn ($faixa) => count($faixa) === 6 && ! in_array('Frete grátis', $this->textos(collect($faixa)->all()), true))
                ->where('blackfriday', null));
    }

    public function test_pagina_de_produto_sem_conteudo_cai_na_mensagem_geral(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('faixa', fn ($faixa) => $this->textos(collect($faixa)->all()) === $this->textos(FaixaDoTopo::geral())));
    }

    public function test_produto_com_poucas_frases_completa_com_as_gerais(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $conteudo = ProductAdContent::create([
            'product_id' => $product->id,
            'fonte' => ProductAdContent::FONTE_AUTOMATICO,
            'destaques' => ['Café moído na hora'],
            'beneficios' => [],
        ]);

        $this->assertSame(
            ['Café moído na hora', 'Frete grátis', 'Compra 100% segura', 'Pix com aprovação na hora'],
            $this->textos(FaixaDoTopo::paraProduto($conteudo)),
        );
    }

    public function test_home_mostra_a_mensagem_geral_sem_raio_e_sem_prometer_demais(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('faixa', fn ($faixa) => $this->textos(collect($faixa)->all()) === $this->textos(FaixaDoTopo::geral())));

        $tudo = json_encode(FaixaDoTopo::geral(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('fa-bolt', $tudo);
        $this->assertStringNotContainsString('⚡', $tudo);
        $this->assertStringNotContainsStringIgnoringCase('PDF', $tudo);
        $this->assertStringContainsString('12x', $tudo);
    }

    public function test_black_friday_vem_junto_e_nao_apaga_as_frases(): void
    {
        config(['loja.cupom_faixa' => 'BLACKFRIDAY', 'loja.blackfriday_ate' => '2026-11-30 23:59:59']);
        $this->travelTo(Carbon::parse('2026-11-25 10:00:00', 'America/Sao_Paulo'));

        $product = Product::factory()->create(['is_active' => true]);
        $this->conteudo($product);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('blackfriday', null)
                ->where('faixa.0.texto', '2ª BATERIA GRÁTIS'));

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('blackfriday', null)
                ->where('faixa.0.texto', 'Frete grátis'));
    }

    public function test_emoji_cortado_no_texto_nao_derruba_a_pagina(): void
    {
        // 27/11/2026: "⏱️ Total Praticidade" virava UTF-8 inválido (o trim cortava meio emoji) e a página dava 500.
        $conteudo = new ProductAdContent;
        $conteudo->setRawAttributes(['destaques' => '[]', 'beneficios' => '[["\\u23f1\\ufe0f Total Praticidade","Uso fácil."],["— Ajuste firme –","Fica no lugar."]]']);

        $frases = array_column(FaixaDoTopo::paraProduto($conteudo), 'texto');

        $this->assertContains('Total Praticidade', $frases);
        $this->assertContains('Ajuste firme', $frases);
        $this->assertNotFalse(json_encode($frases));
    }
}
