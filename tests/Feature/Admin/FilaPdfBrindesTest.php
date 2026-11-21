<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\PdfBrindeFila;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\TemaDeBrinde;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fila de PDFs de brinde (pedido do Lira 20/11/2026): o tema sai do produto,
 * o Homer pega e entrega pela API, e o livro só aparece depois do Aprovar.
 */
class FilaPdfBrindesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function produto(string $nome, string $categoria): Product
    {
        $cat = Category::factory()->create(['name' => $categoria]);

        return Product::factory()->create(['name' => $nome, 'category_id' => $cat->id, 'is_active' => true]);
    }

    public function test_tema_combina_com_o_produto(): void
    {
        $brinquedo = TemaDeBrinde::sugerir($this->produto('Caminhão Dinossauro T-Rex Maleta Com 2 Carrinhos', 'Brinquedos e Games'));
        $this->assertSame(['historia', 'historia', 'colorir'], array_column($brinquedo, 'tipo'));
        $this->assertSame(30, $brinquedo[2]['paginas']);
        $this->assertStringContainsString('sem nenhuma palavra feia', $brinquedo[0]['briefing']);

        $travesseiro = TemaDeBrinde::sugerir($this->produto('Travesseiro Ortopédico Cervical', 'Saúde e Fitness'));
        $this->assertStringContainsString('costas', mb_strtolower($travesseiro[0]['titulo']));

        $ferramenta = TemaDeBrinde::sugerir($this->produto('Parafusadeira Furadeira 48V', 'Ferramentas'));
        $this->assertCount(3, $ferramenta);
        $this->assertStringContainsString('FerraKora', $ferramenta[0]['briefing']);

        $game = TemaDeBrinde::sugerir($this->produto('Video Game Portatil Retrô 10000 Jogos', 'Brinquedos e Games'));
        $this->assertSame('guia', $game[0]['tipo']);
    }

    public function test_montar_fila_homer_entrega_e_lira_aprova(): void
    {
        $produto = $this->produto('Caminhão Unicórnio Com Pista E 6 Carrinhos Infantil', 'Brinquedos e Games');

        $this->actingAs($this->admin)->post('/admin/entregaveis/fila-pdfs/montar')->assertRedirect();
        $this->assertSame(3, PdfBrindeFila::query()->where('product_id', $produto->id)->count());
        // Rodar de novo não duplica.
        $this->post('/admin/entregaveis/fila-pdfs/montar');
        $this->assertSame(3, PdfBrindeFila::query()->count());

        $this->get('/admin/entregaveis/fila-pdfs')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Entregaveis/FilaPdfs', false)->has('itens', 3));

        config(['services.vids.token' => 'chave', 'services.vids.usuario_id' => $this->admin->id]);
        $cab = ['Authorization' => 'Bearer chave', 'Accept' => 'application/json'];

        $this->postJson('/api/pdf-brindes/proxima')->assertStatus(401);
        $resposta = $this->postJson('/api/pdf-brindes/proxima', [], $cab)->assertOk()
            ->assertJsonPath('item.tipo', 'historia')
            ->assertJsonPath('item.produto.id', $produto->id)
            ->assertJsonCount(2, 'item.irmaos');
        $id = $resposta->json('item.id');
        $this->assertSame('produzindo', PdfBrindeFila::find($id)->status);

        $this->post("/api/pdf-brindes/{$id}/entregar", [
            'pdf' => UploadedFile::fake()->create('historia.pdf', 200, 'application/pdf'),
            'capa' => UploadedFile::fake()->image('capa.jpg', 600, 850),
        ], $cab)->assertOk()->assertJsonPath('item.status', 'revisao');

        $livro = LivroDigital::query()->sole();
        $this->assertFalse($livro->ativo);
        $this->assertSame([$produto->id], $livro->produtos()->pluck('products.id')->all());

        // Aba do produto mostra a fila e o livro desativado.
        $this->actingAs($this->admin)->getJson("/admin/produtos/{$produto->id}/brindes")->assertOk()
            ->assertJsonCount(3, 'fila')->assertJsonPath('ligados.0.ativo', false);

        // Devolver tira da página e volta para a fila com a observação.
        $this->postJson("/admin/entregaveis/fila-pdfs/{$id}/devolver", ['observacao' => 'Capa mais colorida'])->assertOk();
        $this->assertSame('fila', PdfBrindeFila::find($id)->status);

        // Homer refaz: troca o PDF do mesmo livro.
        $this->postJson('/api/pdf-brindes/proxima', [], $cab)->assertJsonPath('item.id', $id)->assertJsonPath('item.observacao', 'Capa mais colorida');
        $this->post("/api/pdf-brindes/{$id}/entregar", ['pdf' => UploadedFile::fake()->create('v2.pdf', 200, 'application/pdf')], $cab)->assertOk();
        $this->assertSame(1, LivroDigital::query()->count());

        $this->actingAs($this->admin)->postJson("/admin/entregaveis/fila-pdfs/{$id}/aprovar")->assertOk()->assertJsonPath('item.status', 'aprovado');
        $this->assertTrue($livro->fresh()->ativo);
        $this->get("/produtos/{$produto->slug}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('livrosDigitais.0.id', $livro->id));
    }

    public function test_ligar_e_desligar_livro_pelo_produto_e_editar_item(): void
    {
        $produto = $this->produto('Travesseiro Ortopédico', 'Saúde e Fitness');
        $livro = LivroDigital::query()->create(['titulo' => 'Costas', 'arquivo' => 'x.pdf', 'nome_arquivo' => 'x.pdf', 'tamanho' => 1, 'ativo' => true, 'todos_produtos' => true]);

        $this->actingAs($this->admin)->postJson("/admin/produtos/{$produto->id}/brindes/livros/{$livro->id}")->assertOk();
        $this->assertFalse($livro->fresh()->todos_produtos);
        $this->assertTrue($livro->produtos()->whereKey($produto->id)->exists());

        $this->deleteJson("/admin/produtos/{$produto->id}/brindes/livros/{$livro->id}")->assertOk();
        $this->assertFalse($livro->produtos()->whereKey($produto->id)->exists());

        $item = $this->postJson("/admin/produtos/{$produto->id}/brindes/fila", ['tipo' => 'guia', 'titulo' => 'Sono bom', 'paginas' => 12])
            ->assertCreated()->json('item');
        $this->putJson("/admin/entregaveis/fila-pdfs/{$item['id']}", ['tipo' => 'guia', 'titulo' => 'Durma melhor', 'paginas' => 14, 'prioridade' => 5])
            ->assertOk()->assertJsonPath('item.titulo', 'Durma melhor')->assertJsonPath('item.prioridade', 5);
        $this->postJson("/admin/entregaveis/fila-pdfs/{$item['id']}/aprovar")->assertStatus(422);
        $this->deleteJson("/admin/entregaveis/fila-pdfs/{$item['id']}")->assertOk();
        $this->assertSame(0, PdfBrindeFila::query()->count());
    }
}
