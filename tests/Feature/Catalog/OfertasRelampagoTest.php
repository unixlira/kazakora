<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\OfertaDoDia;
use App\Modules\Catalog\Models\OfertaRelampago;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductQuantityDiscount;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Support\PaymentGateway;
use App\Support\SiteAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ofertas Relâmpago por empresa (pedido 2026-10-14): desconto % num produto
 * pai por um horário, só no site escolhido, no card e no checkout; nunca nos
 * marketplaces; sem sobreposição do mesmo produto no mesmo site.
 */
class OfertasRelampagoTest extends TestCase
{
    use RefreshDatabase;

    private Product $pai;

    private Product $variacao;

    protected function setUp(): void
    {
        parent::setUp();
        // Preço sem frete embutido: a conta fica só no anúncio.
        config(['frete_embutido.ativo' => false, 'loja.desconto_pix' => 5]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->travelTo(now()->setTime(12, 0));

        $this->pai = Product::factory()->create([
            'sku' => 'FER-TRE-5M', 'name' => 'Trena 5m', 'price' => 100.00, 'stock' => 10, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null, 'cost_price' => 30,
        ]);
        $this->variacao = Product::factory()->create([
            'sku' => 'FER-TRE-8M', 'name' => 'Trena 8m', 'variation' => '8m', 'price' => 120.00, 'stock' => 10, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null, 'parent_product_id' => $this->pai->id,
        ]);
        FerraKoraProduto::create(['product_id' => $this->pai->id, 'categoria' => 'Trenas', 'ordem' => 1, 'ativo' => true]);
        BeautyKoraProduto::create(['product_id' => $this->pai->id, 'ordem' => 1, 'ativo' => true, 'clonado' => false]);
    }

    private function oferta(string $site, float $pct = 20, int $deHoras = -1, int $ateHoras = 3, array $extra = []): OfertaRelampago
    {
        return OfertaRelampago::create([
            'site' => $site, 'product_id' => $this->pai->id, 'desconto_pct' => $pct,
            'inicio_em' => now()->addHours($deHoras), 'fim_em' => now()->addHours($ateHoras), ...$extra,
        ]);
    }

    private function precoNoSite(?string $site, Product $produto): float
    {
        return app(SiteAtual::class)->durante($site, fn () => $produto->fresh()->final_price);
    }

    public function test_preco_com_relampago_so_no_site_certo_e_na_api_do_site(): void
    {
        $oferta = $this->oferta(SiteAtual::FERRAKORA);

        $this->assertSame(80.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->pai));
        $this->assertSame(96.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->variacao)); // vale para a variação
        $this->assertSame(100.0, $this->precoNoSite(SiteAtual::BEAUTYKORA, $this->pai));
        $this->assertSame(100.0, $this->precoNoSite(SiteAtual::KAZAKORA, $this->pai));
        $this->assertSame(100.0, $this->precoNoSite(null, $this->pai)); // admin, filas, comandos

        $produto = $this->getJson('/api/ferrakora/produtos')->assertOk()->json('products.0');
        $this->assertSame(['id' => $oferta->id, 'inicio' => $oferta->inicio_em->toIso8601String(), 'fim' => $oferta->fim_em->toIso8601String(), 'desconto_pct' => 20], $produto['oferta_relampago']);
        $this->assertSame(10000, $produto['variants'][0]['prices'][0]['amount']);
        $this->assertSame(8000, $produto['variants'][0]['prices'][0]['sale_amount']);
        $this->assertSame(12000, $produto['variants'][1]['prices'][0]['amount']);
        $this->assertSame(9600, $produto['variants'][1]['prices'][0]['sale_amount']);

        $beauty = $this->getJson('/api/beautykora/produtos')->assertOk()->json('products.0');
        $this->assertNull($beauty['oferta_relampago']);
        $this->assertNull($beauty['variants'][0]['prices'][0]['sale_amount']);
    }

    public function test_agendada_ainda_nao_vale_e_encerrada_nao_vale_mais(): void
    {
        $this->oferta(SiteAtual::FERRAKORA, 20, 1, 3);
        $this->assertSame(100.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->pai));
        $this->assertNull($this->getJson('/api/ferrakora/produtos')->json('products.0.oferta_relampago'));

        $this->travel(90)->minutes();
        $this->assertSame(80.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->pai));

        $this->travel(3)->hours();
        $this->assertSame(100.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->pai));
        $this->assertNull($this->getJson('/api/ferrakora/produtos')->json('products.0.oferta_relampago'));
    }

    public function test_pausada_nao_vale(): void
    {
        $this->oferta(SiteAtual::BEAUTYKORA, 20, -1, 3, ['ativo' => false]);

        $this->assertSame(100.0, $this->precoNoSite(SiteAtual::BEAUTYKORA, $this->pai));
    }

    public function test_checkout_de_outro_site_cobra_o_preco_com_relampago_sem_cupom_nem_quantidade(): void
    {
        $this->oferta(SiteAtual::FERRAKORA);
        ProductQuantityDiscount::create(['product_id' => $this->pai->id, 'min_quantity' => 2, 'discount_percentage' => 10]);

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$this->pai->id, 'quantidade' => 2]]])
            ->assertOk()
            ->assertJsonPath('itens.0.preco_unitario', 8000)
            ->assertJsonPath('subtotal', 16000);

        // O mesmo produto no Beauty Kora, sem oferta lá, sai pelo preço cheio (com o desconto por quantidade).
        app(SiteAtual::class)->definir(null);
        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$this->pai->id, 'quantidade' => 2]]])
            ->assertOk()
            ->assertJsonPath('itens.0.preco_unitario', 9000);
    }

    public function test_loja_kazakora_e_oferta_do_dia_vale_o_menor(): void
    {
        $this->oferta(SiteAtual::KAZAKORA, 10);
        $this->assertSame(90.0, $this->precoNoSite(SiteAtual::KAZAKORA, $this->pai));

        OfertaDoDia::query()->create(['data' => now()->toDateString(), 'product_id' => $this->pai->id, 'desconto_extra' => 15, 'preco_antes' => 100, 'preco_oferta' => 85, 'lucro_estimado' => 20]);
        OfertaDoDia::esquecer();
        $this->assertSame(85.0, $this->precoNoSite(SiteAtual::KAZAKORA, $this->pai));
    }

    public function test_marketplace_sem_relampago(): void
    {
        $this->oferta(SiteAtual::FERRAKORA, 30);
        app(SiteAtual::class)->definir(SiteAtual::FERRAKORA);

        $listing = new ProductChannelListing(['product_id' => $this->pai->id, 'channel' => 'mercado_livre']);
        $this->assertSame(100.0, $listing->precoDeVenda($this->pai->fresh()));
    }

    public function test_admin_cria_oferta_e_recusa_sobreposicao(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $inicio = now()->format('Y-m-d\TH:i');
        $fim = now()->addHours(3)->format('Y-m-d\TH:i');

        $this->actingAs($admin)->get('/admin/ofertas-relampago?site=ferrakora')->assertOk();
        $this->getJson('/admin/ofertas-relampago/produtos?site=ferrakora&q=Trena')->assertOk()->assertJsonPath('produtos.0.id', $this->pai->id);
        $this->getJson('/admin/ofertas-relampago/previa?product_id='.$this->pai->id.'&desconto_pct=10')->assertOk()->assertJsonPath('produto.preco_oferta', 90);

        $this->post('/admin/ofertas-relampago', ['site' => 'ferrakora', 'product_id' => $this->variacao->id, 'desconto_pct' => 10, 'inicio_em' => $inicio, 'fim_em' => $fim])
            ->assertSessionHasNoErrors();
        $oferta = OfertaRelampago::query()->sole();
        $this->assertSame($this->pai->id, $oferta->product_id); // variação vira o pai
        $this->assertSame($admin->id, $oferta->user_id);

        // Mesmo produto e site com horário cruzando: recusado. Outro site: pode.
        $this->post('/admin/ofertas-relampago', ['site' => 'ferrakora', 'product_id' => $this->pai->id, 'desconto_pct' => 15, 'inicio_em' => now()->addHour()->format('Y-m-d\TH:i'), 'fim_em' => now()->addHours(5)->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('inicio_em');
        $this->post('/admin/ofertas-relampago', ['site' => 'beautykora', 'product_id' => $this->pai->id, 'desconto_pct' => 15, 'inicio_em' => $inicio, 'fim_em' => $fim])
            ->assertSessionHasNoErrors();
        // Fim antes do início e produto fora da empresa: recusados.
        $this->post('/admin/ofertas-relampago', ['site' => 'ferrakora', 'product_id' => $this->pai->id, 'desconto_pct' => 15, 'inicio_em' => $fim, 'fim_em' => $inicio])
            ->assertSessionHasErrors('fim_em');
        $fora = Product::factory()->create(['is_active' => true]);
        $this->post('/admin/ofertas-relampago', ['site' => 'ferrakora', 'product_id' => $fora->id, 'desconto_pct' => 15, 'inicio_em' => $inicio, 'fim_em' => $fim])
            ->assertSessionHasErrors('product_id');
        $this->assertSame(2, OfertaRelampago::query()->count());

        // Editar a própria oferta não conta como sobreposição; pausar e excluir.
        $this->put("/admin/ofertas-relampago/{$oferta->id}", ['product_id' => $this->pai->id, 'desconto_pct' => 25, 'inicio_em' => $inicio, 'fim_em' => $fim])
            ->assertSessionHasNoErrors();
        $this->assertSame(25.0, $oferta->fresh()->desconto_pct);
        $this->assertSame(75.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->pai));

        $this->patch("/admin/ofertas-relampago/{$oferta->id}/ativo")->assertRedirect();
        $this->assertFalse($oferta->fresh()->ativo);
        $this->assertSame(100.0, $this->precoNoSite(SiteAtual::FERRAKORA, $this->pai));

        $this->delete("/admin/ofertas-relampago/{$oferta->id}")->assertRedirect();
        $this->assertSame(1, OfertaRelampago::query()->count());
    }

    public function test_ofertas_do_dia_da_home_so_tem_relampago_no_ar_da_kazakora(): void
    {
        $doDia = Product::factory()->create(['price' => 200, 'discount_percentage' => 30, 'cost_price' => 40, 'stock' => 10, 'is_active' => true]);
        OfertaDoDia::query()->create(['data' => now()->toDateString(), 'product_id' => $doDia->id, 'desconto_extra' => 5, 'preco_antes' => 140, 'preco_oferta' => 130, 'lucro_estimado' => 50]);
        $agendado = Product::factory()->create(['price' => 50, 'stock' => 10, 'is_active' => true]);
        $outroSite = Product::factory()->create(['price' => 50, 'stock' => 10, 'is_active' => true]);
        $semEstoque = Product::factory()->create(['price' => 50, 'stock' => 0, 'is_active' => true]);
        $this->oferta(SiteAtual::KAZAKORA, 20, -1, 3);
        OfertaRelampago::create(['site' => SiteAtual::KAZAKORA, 'product_id' => $agendado->id, 'desconto_pct' => 10, 'inicio_em' => now()->addHour(), 'fim_em' => now()->addHours(2)]);
        OfertaRelampago::create(['site' => SiteAtual::FERRAKORA, 'product_id' => $outroSite->id, 'desconto_pct' => 10, 'inicio_em' => now()->subHour(), 'fim_em' => now()->addHours(2)]);
        OfertaRelampago::create(['site' => SiteAtual::KAZAKORA, 'product_id' => $semEstoque->id, 'desconto_pct' => 10, 'inicio_em' => now()->subHour(), 'fim_em' => now()->addHours(2)]);

        $this->get('/')->assertInertia(fn ($page) => $page
            ->has('vitrine.ofertas', 1)
            ->where('vitrine.ofertas.0.id', $this->pai->id)
            ->where('vitrine.ofertasTerminamEm', now()->addHours(3)->toIso8601String()));

        // A agendada entra sozinha quando começa.
        $this->travel(90)->minutes();
        OfertaRelampago::esquecer();
        $this->get('/')->assertInertia(fn ($page) => $page
            ->where('vitrine.ofertas.0.id', $agendado->id)
            ->where('vitrine.ofertas.1.id', $this->pai->id));
    }
}
