<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Modal "Avalie sua compra" depois do login (pedido 2026-10-23, todo login até avaliar em 2026-10-29). */
class ModalAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function pedido(User $user, Product $product, string $status = Order::STATUS_COMPLETED, int $diasAtras = 2, ?string $site = null): Order
    {
        $order = Order::create([
            'user_id' => $user->id, 'status' => $status, 'origin' => Order::ORIGIN_STORE, 'site_origem' => $site, 'shipping_name' => 'X', 'shipping_phone' => '1', 'shipping_zip' => '1',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C', 'shipping_state' => 'SP',
            'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10, 'item_type' => OrderItem::TYPE_PRODUCT]);
        DB::table('orders')->where('id', $order->id)->update(['updated_at' => now()->subDays($diasAtras)]);

        return $order;
    }

    private function cliente(): User
    {
        return User::factory()->create(['password' => bcrypt('segredo-123')]);
    }

    private function entrar(User $user): void
    {
        $this->post('/entrar', ['email' => $user->email, 'password' => 'segredo-123'])->assertRedirect();
    }

    private function modal(): ?array
    {
        $r = $this->get('/')->assertOk();

        return $r->viewData('page')['props']['avaliacaoModal'] ?? null;
    }

    public function test_abre_depois_do_login_com_o_item_entregue_mais_recente_primeiro(): void
    {
        $user = $this->cliente();
        $velho = Product::factory()->create(['is_active' => true, 'name' => 'Trena']);
        $novo = Product::factory()->create(['is_active' => true, 'name' => 'Lixeira']);
        $this->pedido($user, $velho, diasAtras: 10);
        $this->pedido($user, $novo, diasAtras: 1);

        $this->entrar($user);

        $this->assertSame($novo->id, $this->modal()['produto_id']);
    }

    public function test_sem_login_novo_nao_abre(): void
    {
        $user = $this->cliente();
        $this->pedido($user, Product::factory()->create(['is_active' => true]));

        $this->actingAs($user);

        $this->assertNull($this->modal());
    }

    public function test_ignora_nao_entregue_ja_avaliado_e_outro_site(): void
    {
        $user = $this->cliente();
        $this->pedido($user, Product::factory()->create(['is_active' => true]), Order::STATUS_SHIPPED);
        $this->pedido($user, Product::factory()->create(['is_active' => true]), site: Order::SITE_FERRAKORA);
        $avaliado = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $avaliado);
        Review::create(['user_id' => $user->id, 'product_id' => $avaliado->id, 'rating' => 5]);

        $this->entrar($user);

        $this->assertNull($this->modal());
    }

    public function test_pedido_de_outro_cliente_nao_aparece(): void
    {
        $outro = $this->cliente();
        $this->pedido($outro, Product::factory()->create(['is_active' => true]));
        $user = $this->cliente();

        $this->entrar($user);

        $this->assertNull($this->modal());
    }

    public function test_entregue_ha_muito_tempo_tambem_abre(): void
    {
        $user = $this->cliente();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $produto, diasAtras: 120);

        $this->entrar($user);

        $this->assertSame($produto->id, $this->modal()['produto_id']);
    }

    public function test_nao_avaliar_agora_registra_e_volta_no_proximo_login(): void
    {
        $user = $this->cliente();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $produto);

        $this->entrar($user);
        $this->assertNotNull($this->modal());

        $this->post('/avaliar/dispensar', ['produto_id' => $produto->id])->assertRedirect();
        // Mesmo login: não abre de novo.
        $this->assertNull($this->modal());

        $lembrete = DB::table('avaliacao_lembretes')->where('user_id', $user->id)->where('product_id', $produto->id)->first();
        $this->assertSame(1, (int) $lembrete->vezes_nao_avaliar);
        $this->assertNotNull($lembrete->nao_avaliar_em);

        // Login seguinte, no mesmo dia: abre de novo, sem esperar.
        $this->post('/sair');
        $this->entrar($user);
        $this->assertSame($produto->id, $this->modal()['produto_id']);
    }

    public function test_abre_em_todo_login_ate_avaliar(): void
    {
        $user = $this->cliente();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $produto);

        foreach (range(1, 5) as $vez) {
            $this->entrar($user);
            $this->assertSame($produto->id, $this->modal()['produto_id'] ?? null, "login {$vez}");
            // Navegar de novo no mesmo login não conta outra aparição.
            $this->get('/')->assertOk();
            $this->post('/avaliar/dispensar', ['produto_id' => $produto->id]);
            $this->post('/sair');
        }

        $lembrete = DB::table('avaliacao_lembretes')->where('user_id', $user->id)->first();
        $this->assertSame(5, (int) $lembrete->aparicoes);
        $this->assertSame(5, (int) $lembrete->vezes_nao_avaliar);

        $this->entrar($user);
        $this->post("/produtos/{$produto->id}/avaliacoes", ['rating' => 5])->assertSessionHasNoErrors();
        $this->post('/sair');
        $this->entrar($user);
        $this->assertNull($this->modal());
    }

    public function test_nao_avaliar_agora_de_produto_que_nao_e_dele_nao_grava(): void
    {
        $user = $this->cliente();
        $outro = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, Product::factory()->create(['is_active' => true]));

        $this->entrar($user);
        $this->post('/avaliar/dispensar', ['produto_id' => $outro->id])->assertRedirect();

        $this->assertFalse(DB::table('avaliacao_lembretes')->where('product_id', $outro->id)->exists());
        $this->assertNull($this->modal());
    }

    public function test_nao_avaliar_agora_fecha_e_no_proximo_login_volta_o_mais_recente(): void
    {
        $user = $this->cliente();
        $a = Product::factory()->create(['is_active' => true]);
        $b = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $a, diasAtras: 1);
        $this->pedido($user, $b, diasAtras: 3);

        $this->entrar($user);
        $this->assertSame($a->id, $this->modal()['produto_id']);
        $this->post('/avaliar/dispensar', ['produto_id' => $a->id]);
        $this->assertNull($this->modal());

        $this->post('/sair');
        $this->entrar($user);
        $this->assertSame($a->id, $this->modal()['produto_id']);
    }

    public function test_avaliar_mostra_o_proximo_item_e_depois_nada(): void
    {
        $user = $this->cliente();
        $a = Product::factory()->create(['is_active' => true]);
        $b = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $a, diasAtras: 1);
        $this->pedido($user, $b, diasAtras: 3);

        $this->entrar($user);
        $this->assertSame($a->id, $this->modal()['produto_id']);

        $this->post("/produtos/{$a->id}/avaliacoes", ['rating' => 5])->assertSessionHasNoErrors();
        $this->assertSame($b->id, $this->modal()['produto_id']);

        $this->post("/produtos/{$b->id}/avaliacoes", ['rating' => 4])->assertSessionHasNoErrors();
        $this->assertNull($this->modal());
    }

    public function test_envia_avaliacao_com_fotos_e_grava_no_mesmo_fluxo(): void
    {
        Storage::fake('public');
        $user = $this->cliente();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $produto);

        $this->actingAs($user)->post("/produtos/{$produto->id}/avaliacoes", [
            'rating' => 5,
            'comment' => 'Chegou certinho',
            'fotos' => [UploadedFile::fake()->image('a.jpg', 200, 200), UploadedFile::fake()->image('b.png', 200, 200)],
        ])->assertSessionHasNoErrors();

        $review = Review::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(5, $review->rating);
        $this->assertCount(2, $review->images);
        $this->assertStringContainsString("/storage/avaliacoes/{$review->id}/", $review->images[0]->image_url);
        $this->assertCount(2, Storage::disk('public')->allFiles("avaliacoes/{$review->id}"));
    }

    public function test_rejeita_foto_invalida_grande_ou_demais(): void
    {
        Storage::fake('public');
        $user = $this->cliente();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $produto);
        $url = "/produtos/{$produto->id}/avaliacoes";

        $this->actingAs($user)->post($url, ['rating' => 5, 'fotos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('fotos.0');
        $this->post($url, ['rating' => 5, 'fotos' => [UploadedFile::fake()->create('x.php', 10, 'text/x-php')]])->assertSessionHasErrors('fotos.0');
        $this->post($url, ['rating' => 5, 'fotos' => [UploadedFile::fake()->image('g.jpg')->size(6000)]])->assertSessionHasErrors('fotos.0');
        $cinco = array_map(fn ($i) => UploadedFile::fake()->image("f{$i}.jpg"), range(1, 5));
        $this->post($url, ['rating' => 5, 'fotos' => $cinco])->assertSessionHasErrors('fotos');
        $this->post($url, ['rating' => 0])->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::count());
    }

    public function test_nao_avalia_produto_que_nao_comprou_nem_pedido_nao_entregue(): void
    {
        $user = $this->cliente();
        $naoComprou = Product::factory()->create(['is_active' => true]);
        $aCaminho = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $aCaminho, Order::STATUS_SHIPPED);

        $this->actingAs($user)->post("/produtos/{$naoComprou->id}/avaliacoes", ['rating' => 5])->assertSessionHasErrors('review');
        $this->post("/produtos/{$aCaminho->id}/avaliacoes", ['rating' => 5])->assertSessionHasErrors('review');
        $this->assertSame(0, Review::count());
    }

    public function test_uma_avaliacao_por_produto_do_cliente(): void
    {
        $user = $this->cliente();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido($user, $produto);
        $this->pedido($user, $produto, diasAtras: 5);

        $this->actingAs($user)->post("/produtos/{$produto->id}/avaliacoes", ['rating' => 5]);
        $this->post("/produtos/{$produto->id}/avaliacoes", ['rating' => 3]);

        $this->assertSame(1, Review::query()->where('user_id', $user->id)->count());
    }

    public function test_admin_nao_recebe_o_modal(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'password' => bcrypt('segredo-123')]);
        $this->pedido($admin, Product::factory()->create(['is_active' => true]));

        $this->post('/entrar', ['email' => $admin->email, 'password' => 'segredo-123']);

        $this->assertNull($this->modal());
    }
}
