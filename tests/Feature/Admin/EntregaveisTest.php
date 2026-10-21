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

        // Produto com livros próprios mostra SÓ os próprios (variação vale o pai).
        $this->assertSame(['Só do pai'], LivroDigital::doProduto($variacao)->pluck('titulo')->all());
        $this->assertSame(['Só do pai'], LivroDigital::doProduto($this->produto)->pluck('titulo')->all());
        $this->assertSame(['Para todos'], LivroDigital::doProduto($this->outro)->pluck('titulo')->all());
    }

    public function test_livros_especificos_substituem_os_de_todos_e_seguem_ordem_por_id(): void
    {
        $todos = fn (string $t) => LivroDigital::query()->create(['titulo' => $t, 'arquivo' => 'a.pdf', 'nome_arquivo' => 'a.pdf', 'ativo' => true, 'todos_produtos' => true]);
        $proprio = function (string $t, bool $ativo = true) {
            $livro = LivroDigital::query()->create(['titulo' => $t, 'arquivo' => 'b.pdf', 'nome_arquivo' => 'b.pdf', 'ativo' => $ativo, 'todos_produtos' => false]);
            $livro->produtos()->sync([$this->produto->id]);

            return $livro;
        };

        $todos('Costas 1');
        $proprio('Ferramenta 1');
        $todos('Costas 2');
        $proprio('Ferramenta 2');
        $inativo = $proprio('Ferramenta inativa', ativo: false);

        $this->assertSame(['Ferramenta 1', 'Ferramenta 2'], LivroDigital::doProduto($this->produto)->pluck('titulo')->all());
        $this->assertSame(['Costas 1', 'Costas 2'], LivroDigital::doProduto($this->outro)->pluck('titulo')->all());

        // Só livro próprio INATIVO não conta: volta para os de todos.
        $outroComInativo = Product::factory()->create(['is_active' => true]);
        $inativo->produtos()->sync([$outroComInativo->id]);
        $this->assertSame(['Costas 1', 'Costas 2'], LivroDigital::doProduto($outroComInativo)->pluck('titulo')->all());

        $this->pagina($this->produto)->assertInertia(fn ($page) => $page
            ->where('livrosDigitais.0.titulo', 'Ferramenta 1')
            ->where('livrosDigitais.1.titulo', 'Ferramenta 2')
            ->missing('livrosDigitais.2'));
    }

    public function test_imagem_do_quadro_vem_do_primeiro_livro_que_tem_uma(): void
    {
        $png = fn (string $nome) => UploadedFile::fake()->createWithContent($nome, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        // Sem imagem em nenhum livro: null (a página usa a padrão dos tablets de costas).
        LivroDigital::query()->create(['titulo' => 'Costas', 'arquivo' => 'a.pdf', 'nome_arquivo' => 'a.pdf', 'ativo' => true, 'todos_produtos' => true]);
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('livrosQuadro', null));

        // Grupo do produto: o 1º sem imagem, o 2º e o 3º com; vale a do 2º.
        $base = ['ativo' => '1', 'todos_produtos' => '0', 'produtos' => [$this->produto->id]];
        $this->actingAs($this->admin);
        $this->post('/admin/entregaveis/livros-digitais', $base + ['titulo' => 'Ferramenta 1', 'pdf' => UploadedFile::fake()->create('f1.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $this->post('/admin/entregaveis/livros-digitais', $base + ['titulo' => 'Ferramenta 2', 'pdf' => UploadedFile::fake()->create('f2.pdf', 10, 'application/pdf'), 'imagem_quadro' => $png('quadro.png')])->assertSessionHasNoErrors();
        $this->post('/admin/entregaveis/livros-digitais', $base + ['titulo' => 'Ferramenta 3', 'pdf' => UploadedFile::fake()->create('f3.pdf', 10, 'application/pdf'), 'imagem_quadro' => $png('outro.png')])->assertSessionHasNoErrors();

        $segundo = LivroDigital::query()->where('titulo', 'Ferramenta 2')->sole();
        $this->assertStringStartsWith('livros-digitais/quadros/', $segundo->imagem_quadro);
        Storage::disk('public')->assertExists($segundo->imagem_quadro);

        $this->pagina($this->produto)->assertInertia(fn ($page) => $page
            ->where('livrosQuadro', $segundo->imagem_quadro_url)
            ->where('livrosDigitais.0.titulo', 'Ferramenta 1'));
        $this->pagina($this->outro)->assertInertia(fn ($page) => $page->where('livrosQuadro', null));

        // Troca da imagem: apaga a antiga e grava a nova.
        $antiga = $segundo->imagem_quadro;
        $this->post("/admin/entregaveis/livros-digitais/{$segundo->id}", $base + ['_method' => 'put', 'titulo' => 'Ferramenta 2', 'imagem_quadro' => $png('nova.png')])->assertSessionHasNoErrors();
        $segundo->refresh();
        $this->assertNotSame($antiga, $segundo->imagem_quadro);
        Storage::disk('public')->assertMissing($antiga);
        Storage::disk('public')->assertExists($segundo->imagem_quadro);

        // Remoção: passa a valer a do 3º livro.
        $this->post("/admin/entregaveis/livros-digitais/{$segundo->id}", $base + ['_method' => 'put', 'titulo' => 'Ferramenta 2', 'remover_imagem_quadro' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($segundo->fresh()->imagem_quadro);
        $terceiro = LivroDigital::query()->where('titulo', 'Ferramenta 3')->sole();
        $this->pagina($this->produto)->assertInertia(fn ($page) => $page->where('livrosQuadro', $terceiro->imagem_quadro_url));
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
