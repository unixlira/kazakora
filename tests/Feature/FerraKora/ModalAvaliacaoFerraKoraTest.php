<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Modal "Avalie sua compra" no site FerraKora (pedido do Lira 2026-11-21). */
class ModalAvaliacaoFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $email = 'joao@exemplo.com'): string
    {
        return $this->postJson('/api/ferrakora/cadastro', ['name' => 'João Silva', 'email' => $email, 'password' => 'segredo123'])
            ->assertCreated()->json('token');
    }

    private function pedido(string $email, Product $product, string $status = Order::STATUS_COMPLETED, int $diasAtras = 2, ?string $site = Order::SITE_FERRAKORA): Order
    {
        $cliente = FerraKoraCliente::query()->where('email', $email)->firstOrFail();
        $order = Order::create([
            'status' => $status, 'origin' => Order::ORIGIN_STORE, 'site_origem' => $site, 'shipping_name' => 'X', 'shipping_phone' => '1', 'shipping_zip' => '1',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'C', 'shipping_state' => 'PE',
            'subtotal' => 10, 'shipping_cost' => 0, 'total' => 10,
        ]);
        DB::table('orders')->where('id', $order->id)->update(['ferrakora_cliente_id' => $cliente->id, 'updated_at' => now()->subDays($diasAtras)]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $order;
    }

    public function test_sem_login_nao_entra(): void
    {
        $this->getJson('/api/ferrakora/avaliacao/pendente')->assertUnauthorized();
        $this->postJson('/api/ferrakora/avaliacao/kz-1', ['rating' => 5])->assertUnauthorized();
    }

    public function test_mostra_o_entregue_mais_recente_e_respeita_os_pulados(): void
    {
        $token = $this->token();
        $velho = Product::factory()->create(['is_active' => true, 'name' => 'Trena']);
        $novo = Product::factory()->create(['is_active' => true, 'name' => 'Serra']);
        $naoEntregue = Product::factory()->create(['is_active' => true]);
        $daKazaKora = Product::factory()->create(['is_active' => true]);
        $this->pedido('joao@exemplo.com', $velho, diasAtras: 10);
        $this->pedido('joao@exemplo.com', $novo, diasAtras: 1);
        $this->pedido('joao@exemplo.com', $naoEntregue, Order::STATUS_SHIPPED, 0);
        $this->pedido('joao@exemplo.com', $daKazaKora, diasAtras: 0, site: null);

        $this->withToken($token)->getJson('/api/ferrakora/avaliacao/pendente')->assertOk()->assertJsonPath('item.produto', 'kz-'.$novo->id);
        $this->withToken($token)->getJson('/api/ferrakora/avaliacao/pendente?pular[]=kz-'.$novo->id)->assertOk()->assertJsonPath('item.produto', 'kz-'.$velho->id);
        $this->withToken($token)->getJson('/api/ferrakora/avaliacao/pendente?pular[]=kz-'.$novo->id.'&pular[]=kz-'.$velho->id)->assertOk()->assertJsonPath('item', null);
    }

    public function test_avalia_com_foto_e_sai_da_lista(): void
    {
        Storage::fake('public');
        $token = $this->token();
        $produto = Product::factory()->create(['is_active' => true]);
        $this->pedido('joao@exemplo.com', $produto);

        $this->withToken($token)->post('/api/ferrakora/avaliacao/kz-'.$produto->id, [
            'rating' => 5, 'comment' => 'Muito boa', 'fotos' => [UploadedFile::fake()->image('a.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $review = Review::query()->where('product_id', $produto->id)->firstOrFail();
        $this->assertSame('João Silva', $review->reviewer_display_name);
        $this->assertSame('PE', $review->uf);
        $this->assertSame(1, $review->images()->count());
        $this->withToken($token)->getJson('/api/ferrakora/avaliacao/pendente')->assertJsonPath('item', null);

        // Enviar de novo atualiza a mesma avaliação.
        $this->withToken($token)->postJson('/api/ferrakora/avaliacao/kz-'.$produto->id, ['rating' => 4])->assertCreated();
        $this->assertSame(1, Review::query()->where('product_id', $produto->id)->count());
    }

    public function test_nao_avalia_o_que_nao_recebeu_nem_pedido_de_outro_cliente(): void
    {
        $token = $this->token();
        $this->token('maria@exemplo.com');
        $daMaria = Product::factory()->create(['is_active' => true]);
        $aCaminho = Product::factory()->create(['is_active' => true]);
        $this->pedido('maria@exemplo.com', $daMaria);
        $this->pedido('joao@exemplo.com', $aCaminho, Order::STATUS_SHIPPED);

        $this->withToken($token)->postJson('/api/ferrakora/avaliacao/kz-'.$daMaria->id, ['rating' => 5])->assertStatus(422);
        $this->withToken($token)->postJson('/api/ferrakora/avaliacao/kz-'.$aCaminho->id, ['rating' => 5])->assertStatus(422);
        $this->withToken($token)->getJson('/api/ferrakora/avaliacao/pendente')->assertJsonPath('item', null);
        $this->assertSame(0, Review::query()->count());
    }
}
