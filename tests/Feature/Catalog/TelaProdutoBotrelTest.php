<?php

namespace Tests\Feature\Catalog;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductSlugAntigo;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Checkout\Support\ParcelasCartao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelaProdutoBotrelTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_antigo_mantem_utm_e_fbclid_no_301(): void
    {
        $produto = Product::factory()->create(['slug' => 'nome-novo']);
        ProductSlugAntigo::query()->create(['product_id' => $produto->id, 'slug' => 'nome-velho']);

        $this->get('/produtos/nome-velho?fbclid=abc&utm_source=meta')
            ->assertStatus(301)
            ->assertRedirect(route('produtos.ver', 'nome-novo').'?fbclid=abc&utm_source=meta');

        $this->get('/produtos/nome-velho')->assertStatus(301)->assertRedirect(route('produtos.ver', 'nome-novo'));
    }

    public function test_tabela_de_parcelas_vem_do_mercado_pago_ate_12x(): void
    {
        config(['services.mercadopago.access_token' => 'token-de-teste']);
        Cache::flush();
        Http::fake(['api.mercadopago.com/*' => Http::response([['payer_costs' => [
            ['installments' => 1, 'installment_rate' => 0, 'min_allowed_amount' => 0.5],
            ['installments' => 12, 'installment_rate' => 22.11, 'min_allowed_amount' => 60],
            ['installments' => 13, 'installment_rate' => 23.04, 'min_allowed_amount' => 65],
        ]]])]);

        $this->assertSame([
            ['parcelas' => 1, 'taxa' => 0.0, 'minimo' => 0.5],
            ['parcelas' => 12, 'taxa' => 22.11, 'minimo' => 60.0],
        ], ParcelasCartao::tabela());
    }

    public function test_sem_resposta_do_mercado_pago_a_tabela_fica_vazia(): void
    {
        config(['services.mercadopago.access_token' => 'token-de-teste']);
        Cache::flush();
        Http::fake(['api.mercadopago.com/*' => Http::response([], 500)]);

        $this->assertSame([], ParcelasCartao::tabela());
    }

    public function test_guia_de_costas_so_na_categoria_do_tema(): void
    {
        LivroDigital::query()->create(['titulo' => 'Costas', 'arquivo' => 'a.pdf', 'nome_arquivo' => 'a.pdf', 'tamanho' => 1, 'ativo' => true, 'todos_produtos' => true]);
        $fitness = Category::factory()->create(['name' => 'Saúde e Fitness']);
        $natal = Category::factory()->create(['name' => 'Eletroportáteis e Casa']);

        $this->assertTrue(PromocoesDoProduto::mostraQuadroDosLivros(Product::factory()->create(['category_id' => $fitness->id]), null));
        $casa = Product::factory()->create(['category_id' => $natal->id]);
        $this->assertFalse(PromocoesDoProduto::mostraQuadroDosLivros($casa, null));
        // Grupo com imagem própria escolhida no admin continua aparecendo.
        $this->assertTrue(PromocoesDoProduto::mostraQuadroDosLivros($casa, 'http://x/quadro.webp'));
        // O brinde do pedido não muda: só o quadro da página.
        $this->assertCount(1, LivroDigital::doProduto($casa));
    }

    public function test_comprar_agora_fica_registrado(): void
    {
        $produto = Product::factory()->create(['stock' => 5, 'is_active' => true]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) Safari/604.1')
            ->post('/comprar-agora', ['product_id' => $produto->id, 'quantity' => 1])
            ->assertRedirect();

        $this->assertSame((string) $produto->id, CheckoutEvento::query()->where('evento', CheckoutEvento::PRODUTO_COMPRAR)->value('campo'));
    }

    public function test_rolagem_do_produto_aceita_so_o_marco_previsto(): void
    {
        $envio = fn (array $evento) => $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone) Safari/604.1')
            ->call('POST', '/finalizacao/eventos', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode(['pagina' => 'abc12345', 'eventos' => [$evento]]));

        $envio(['evento' => 'produto_rolagem', 'campo' => '206', 'valor' => 50])->assertNoContent();
        $envio(['evento' => 'produto_rolagem', 'campo' => '206', 'valor' => 33])->assertStatus(422);
        $envio(['evento' => 'produto_rolagem', 'campo' => 'texto', 'valor' => 50])->assertStatus(422);
    }
}
