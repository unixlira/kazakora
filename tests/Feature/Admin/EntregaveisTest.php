<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sorteio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Entregáveis (pedido 2026-10-20): livros digitais (PDF) e sorteios
 * cadastrados no admin aparecem só nos produtos escolhidos e só se ativos.
 */
class EntregaveisTest extends TestCase
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
        // Começa sem o sorteio da AirFryer que a migration cadastra.
        Sorteio::query()->delete();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->produto = Product::factory()->create(['is_active' => true]);
        $this->outro = Product::factory()->create(['is_active' => true]);
    }

    private function pagina(Product $produto)
    {
        return $this->get("/produtos/{$produto->slug}")->assertOk();
    }

    public function test_cadastra_livro_so_no_produto_escolhido_e_desativa(): void
    {
        $this->actingAs($this->admin)->post('/admin/entregaveis/livros-digitais', [
            'titulo' => 'Dores nas costas',
            'pdf' => UploadedFile::fake()->create('costas.pdf', 300, 'application/pdf'),
            'ativo' => '1',
            'todos_produtos' => '0',
            'produtos' => [$this->produto->id],
        ])->assertRedirect('/admin/entregaveis/livros-digitais')->assertSessionHasNoErrors();

        $livro = LivroDigital::query()->sole();
        Storage::disk('local')->assertExists($livro->arquivo);
        $this->assertSame('costas.pdf', $livro->nome_arquivo);

        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
            ->where('livrosDigitais.0.titulo', 'Dores nas costas')
            ->missing('livrosDigitais.0.arquivo'));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('livrosDigitais', []));

        $this->patch("/admin/entregaveis/livros-digitais/{$livro->id}/ativo")->assertRedirect();
        $this->assertFalse($livro->fresh()->ativo);
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('livrosDigitais', []));

        $this->get("/admin/entregaveis/livros-digitais/{$livro->id}/pdf")->assertOk()->assertDownload('costas.pdf');
    }

    public function test_livro_exige_pdf_e_produto_quando_nao_e_para_todos(): void
    {
        $this->actingAs($this->admin)->post('/admin/entregaveis/livros-digitais', [
            'titulo' => 'Sem arquivo',
            'pdf' => UploadedFile::fake()->create('foto.png', 10, 'image/png'),
            'todos_produtos' => '0',
        ])->assertSessionHasErrors(['pdf', 'produtos']);

        $this->assertSame(0, LivroDigital::query()->count());
    }

    public function test_livro_para_todos_aparece_na_variacao(): void
    {
        $variacao = Product::factory()->create(['is_active' => true, 'parent_product_id' => $this->produto->id]);
        LivroDigital::query()->create(['titulo' => 'Para todos', 'arquivo' => 'x.pdf', 'nome_arquivo' => 'x.pdf', 'ativo' => true, 'todos_produtos' => true]);
        $so = LivroDigital::query()->create(['titulo' => 'Só do pai', 'arquivo' => 'y.pdf', 'nome_arquivo' => 'y.pdf', 'ativo' => true, 'todos_produtos' => false]);
        $so->produtos()->sync([$this->produto->id]);

        $this->assertSame(['Para todos', 'Só do pai'], LivroDigital::doProduto($variacao)->pluck('titulo')->all());
        $this->assertSame(['Para todos'], LivroDigital::doProduto($this->outro)->pluck('titulo')->all());
    }

    public function test_sorteio_no_produto_escolhido_com_textos_e_imagem(): void
    {
        $this->actingAs($this->admin)->post('/admin/entregaveis/sorteios', [
            'product_id' => $this->produto->id,
            'titulo' => 'Concorra a uma AirFryer!',
            'paragrafo_1' => 'Primeiro parágrafo.',
            'paragrafo_2' => 'Segundo parágrafo.',
            // PNG 1×1 de verdade (sem depender da extensão GD).
            'imagem' => UploadedFile::fake()->createWithContent('premio.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')),
            'ativo' => '1',
        ])->assertRedirect('/admin/entregaveis/sorteios')->assertSessionHasNoErrors();

        $sorteio = Sorteio::query()->sole();
        Storage::disk('public')->assertExists($sorteio->imagem);

        $this->pagina($this->produto)->assertInertia(fn ($page) => $page
            ->where('sorteios.0.titulo', 'Concorra a uma AirFryer!')
            ->where('sorteios.0.paragrafo_2', 'Segundo parágrafo.'));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('sorteios', []));

        // Edição sem trocar a imagem, desativando.
        $this->post("/admin/entregaveis/sorteios/{$sorteio->id}", [
            '_method' => 'put',
            'product_id' => '',
            'titulo' => 'Novo título',
            'paragrafo_1' => 'Texto.',
            'paragrafo_2' => '',
            'ativo' => '0',
        ])->assertRedirect('/admin/entregaveis/sorteios')->assertSessionHasNoErrors();

        $sorteio->refresh();
        $this->assertNull($sorteio->product_id);
        $this->assertNull($sorteio->paragrafo_2);
        $this->assertFalse($sorteio->ativo);
        Storage::disk('public')->assertExists($sorteio->imagem);
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('sorteios', []));
    }

    public function test_telas_do_admin_abrem(): void
    {
        $this->actingAs($this->admin)->get('/admin/entregaveis/livros-digitais')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Entregaveis/LivrosDigitais', false));
        $this->get('/admin/entregaveis/sorteios')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Entregaveis/Sorteios', false));
        $this->getJson('/admin/entregaveis/produtos?q='.urlencode($this->produto->name))->assertOk()
            ->assertJsonPath('produtos.0.id', $this->produto->id);
    }
}
