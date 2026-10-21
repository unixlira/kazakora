<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promocao;
use App\Modules\Catalog\Models\PromocaoProduto;
use App\Modules\Catalog\Models\Sorteio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Vender › Promoções na página do produto (pedido 2026-10-21): chave geral,
 * imagem do quadro e produtos ligados (promocao_produto). A página do produto
 * lê do cache e muda na hora.
 */
class PromocoesNaPaginaDoProdutoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $produto;

    private Product $outro;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->produto = Product::factory()->create(['is_active' => true]);
        $this->outro = Product::factory()->create(['is_active' => true]);
        // A migration já cadastra o sorteio da AirFryer para todos.
        LivroDigital::query()->create([
            'titulo' => 'Dores nas costas', 'arquivo' => 'livros-digitais/a.pdf', 'nome_arquivo' => 'a.pdf',
            'tamanho' => 10, 'ativo' => true, 'todos_produtos' => true,
        ]);
    }

    private function pagina(Product $produto)
    {
        return $this->get("/produtos/{$produto->slug}")->assertOk();
    }

    private function promocao(string $slug): Promocao
    {
        return Promocao::query()->where('slug', $slug)->sole();
    }

    public function test_tela_mostra_os_dois_cards_com_imagem_tamanho_e_produtos(): void
    {
        $this->actingAs($this->admin)->get('/admin/promocoes')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Admin/Promocoes/Index', false)
            ->where('promocoes.0.slug', 'airfryer')
            ->where('promocoes.0.na_pagina_produto', true)
            ->where('promocoes.0.todos_produtos', true)
            ->where('promocoes.0.recomendado.largura', 380)
            ->where('promocoes.0.entregaveis.itens.0', 'Compre e Concorra a uma AirFryer de Última Geração!')
            ->where('promocoes.1.slug', 'pdfs')
            ->where('promocoes.1.recomendado.largura', 1200)
            ->has('promocoes.1.prontas', 5)
            ->where('promocoes.1.entregaveis.itens.0', 'Dores nas costas')
            ->where('formatos', ['jpg', 'jpeg', 'png', 'webp']));
    }

    public function test_chave_geral_tira_o_quadro_de_todos_mesmo_com_a_pagina_em_cache(): void
    {
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->has('sorteios', 1)->has('livrosDigitais', 1));

        $this->actingAs($this->admin)->patch('/admin/promocoes/airfryer/pagina-produto', ['na_pagina_produto' => false])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($this->promocao('airfryer')->na_pagina_produto);
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('sorteios', [])->has('livrosDigitais', 1));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('sorteios', []));
    }

    public function test_produto_desligado_sai_so_dele_e_so_os_escolhidos_mostra_so_neles(): void
    {
        $this->actingAs($this->admin)->post('/admin/promocoes/pdfs/produtos', ['product_id' => $this->produto->id])
            ->assertSessionHasNoErrors();
        $ligacao = PromocaoProduto::query()->sole();

        // Todos os produtos + ligado: continua aparecendo nos dois.
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->has('livrosDigitais', 1));

        $this->patch("/admin/promocoes/pdfs/produtos/{$ligacao->id}")->assertRedirect();
        $this->assertFalse($ligacao->fresh()->ativo);
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('livrosDigitais', [])->where('livrosQuadro', null));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->has('livrosDigitais', 1));

        // Só os escolhidos: aparece só no produto ligado.
        $this->patch("/admin/promocoes/pdfs/produtos/{$ligacao->id}");
        $this->patch('/admin/promocoes/pdfs/pagina-produto', ['todos_produtos' => false])->assertSessionHasNoErrors();
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->has('livrosDigitais', 1));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('livrosDigitais', []));

        $this->delete("/admin/promocoes/pdfs/produtos/{$ligacao->id}")->assertRedirect();
        $this->assertSame(0, PromocaoProduto::query()->count());
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('livrosDigitais', []));
    }

    public function test_variacao_liga_pelo_pai(): void
    {
        $filho = Product::factory()->create(['is_active' => true, 'parent_product_id' => $this->produto->id]);

        $this->actingAs($this->admin)->post('/admin/promocoes/airfryer/produtos', ['product_id' => $filho->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->produto->id, PromocaoProduto::query()->sole()->product_id);

        $this->patch('/admin/promocoes/airfryer/pagina-produto', ['todos_produtos' => false]);
        $this->pagina($filho)->assertInertia(fn ($page) => $page->has('sorteios', 1));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('sorteios', []));
    }

    public function test_imagem_pronta_e_enviada_trocam_o_quadro_e_a_enviada_antiga_sai_do_disco(): void
    {
        $this->actingAs($this->admin)->post('/admin/promocoes/pdfs/imagem', ['imagem_pronta' => 'images/promocoes/livros-tablets.webp'])
            ->assertSessionHasNoErrors();
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('livrosQuadro', asset('images/promocoes/livros-tablets.webp')));

        $this->post('/admin/promocoes/airfryer/imagem', [
            'imagem' => UploadedFile::fake()->createWithContent('premio.webp', file_get_contents(public_path('images/promocoes/livros-tablets.webp'))),
        ])->assertSessionHasNoErrors();
        $enviada = $this->promocao('airfryer')->imagem;
        Storage::disk('public')->assertExists($enviada);
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('sorteios.0.imagem_url', asset('storage/'.$enviada)));

        // Volta para a de Entregáveis: a enviada sai do disco.
        $this->post('/admin/promocoes/airfryer/imagem', ['imagem_pronta' => ''])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($enviada);
        $this->assertNull($this->promocao('airfryer')->imagem);
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('sorteios.0.imagem_url', asset('images/promocoes/airfryer.jpg')));
    }

    public function test_mudanca_em_entregaveis_limpa_o_cache_da_pagina(): void
    {
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->has('sorteios', 1));

        Sorteio::query()->sole()->update(['ativo' => false]);

        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('sorteios', []));
    }

    public function test_recusa_imagem_fora_da_lista_formato_errado_e_ligacao_de_outra_promocao(): void
    {
        $this->actingAs($this->admin)->post('/admin/promocoes/pdfs/imagem', ['imagem_pronta' => 'images/promocoes/airfryer.jpg'])
            ->assertSessionHasErrors('imagem_pronta');
        $this->post('/admin/promocoes/airfryer/imagem', ['imagem' => UploadedFile::fake()->create('premio.gif', 10, 'image/gif')])
            ->assertSessionHasErrors('imagem');
        $this->assertNull($this->promocao('airfryer')->imagem);

        $this->post('/admin/promocoes/airfryer/produtos', ['product_id' => $this->produto->id]);
        $ligacao = PromocaoProduto::query()->sole();
        $this->patch("/admin/promocoes/pdfs/produtos/{$ligacao->id}")->assertNotFound();
        $this->delete("/admin/promocoes/pdfs/produtos/{$ligacao->id}")->assertNotFound();
        $this->assertTrue($ligacao->fresh()->ativo);
    }
}
