<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Cadastro e edição de departamento com imagem (BUG REAL 2026-10-10: edição dava erro de validação). */
class DepartamentoAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_e_edita_com_imagem_e_nome_em_titulo(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/categorias', [
            'name' => 'ACESSÓRIOS PARA PETS',
            'image' => UploadedFile::fake()->create('pets.jpg', 200, 'image/jpeg'),
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/categorias');

        $categoria = Category::query()->firstOrFail();
        $this->assertSame('Acessórios para Pets', $categoria->name);
        Storage::disk('public')->assertExists($categoria->image_path);

        $this->post("/admin/categorias/{$categoria->id}", [
            '_method' => 'put',
            'name' => 'Acessórios para Pets',
            'description' => 'Tudo para o seu pet',
            'image' => UploadedFile::fake()->create('nova.jpg', 200, 'image/jpeg'),
        ])->assertSessionHasNoErrors()->assertRedirect('/admin/categorias');

        $categoria->refresh();
        $this->assertSame('Tudo para o seu pet', $categoria->description);
        $this->assertStringContainsString('categories/', $categoria->image_path);

        $this->post('/admin/categorias', ['name' => 'acessórios para pets'])->assertSessionHasErrors(['name' => 'Já existe um departamento com esse nome.']);
    }
}
