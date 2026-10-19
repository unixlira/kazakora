<?php

namespace Tests\Feature\Operacional;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\PrintJob;
use App\Modules\Operacional\Mail\NotaEEtiquetaPendentes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Pedido pago sem NF-e autorizada e sem etiqueta impressa (pedido 2026-10-19). */
class NotaEEtiquetaPendentesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        config(['loja.email_nota_etiqueta' => 'dono@exemplo.com']);
        Carbon::setTestNow('2026-10-19 15:10:00');
    }

    private function pedido(array $extra = [], string $criado = '2026-10-19 12:00:00'): Order
    {
        $produto = Product::factory()->create(['price' => 100, 'cost_price' => 40, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => 'shopee', 'external_order_id' => 'SHP'.uniqid(),
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 100,
            ...$extra,
        ]);
        $pedido->forceFill(['created_at' => $criado])->save();
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => 'Organizador', 'product_price' => 100, 'quantity' => 1, 'subtotal' => 100, 'item_type' => OrderItem::TYPE_PRODUCT]);

        return $pedido;
    }

    private function nota(Order $pedido, string $status): void
    {
        Invoice::create(['order_id' => $pedido->id, 'status' => $status, 'ambiente' => 'producao', 'serie' => 1, 'numero' => $pedido->id, 'valor_total' => 100, 'motivo_rejeicao' => $status === 'rejected' ? 'Rejeição 539' : null]);
    }

    public function test_avisa_so_pedido_pago_sem_nota_e_sem_etiqueta(): void
    {
        $rejeitada = $this->pedido();
        $this->nota($rejeitada, Invoice::STATUS_REJECTED);
        $semNota = $this->pedido();

        $this->nota($this->pedido(), Invoice::STATUS_AUTHORIZED);
        $this->nota($this->pedido(), Invoice::STATUS_EXTERNAL);
        $this->pedido(['packed_at' => now()]);
        PrintJob::create(['order_id' => $this->pedido()->id, 'label_path' => 'x.pdf', 'status' => PrintJob::STATUS_PRINTED, 'printed_at' => now()]);
        $this->pedido(['status' => Order::STATUS_SHIPPED]);
        $this->pedido([], '2026-10-19 14:55:00'); // criado há 15 min
        $this->pedido([], '2026-10-01 10:00:00'); // fora da janela de 7 dias

        $this->artisan('loja:nota-etiqueta-pendentes')->assertSuccessful();

        Mail::assertSent(NotaEEtiquetaPendentes::class, fn ($mail) => $mail->hasTo('dono@exemplo.com')
            && array_column($mail->pedidos, 'id') === [$rejeitada->id, $semNota->id]);
        $this->assertStringContainsString('rejeitada', (new NotaEEtiquetaPendentes(
            Mail::sent(NotaEEtiquetaPendentes::class)->first()->pedidos
        ))->render());
    }

    public function test_nao_repete_ate_aparecer_pedido_novo(): void
    {
        $this->pedido();

        $this->artisan('loja:nota-etiqueta-pendentes')->assertSuccessful();
        $this->artisan('loja:nota-etiqueta-pendentes')->assertSuccessful();
        Mail::assertSent(NotaEEtiquetaPendentes::class, 1);

        $this->pedido();
        $this->artisan('loja:nota-etiqueta-pendentes')->assertSuccessful();
        Mail::assertSent(NotaEEtiquetaPendentes::class, 2);
        Mail::assertSent(NotaEEtiquetaPendentes::class, fn ($mail) => count($mail->pedidos) === 2
            && array_column($mail->pedidos, 'novo') === [false, true]);
    }

    public function test_esta_na_agenda(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('loja:nota-etiqueta-pendentes');
    }
}
