<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Services\Fiscal\NcmValidatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Venda do site (KazaKora e FerraKora) não pode parar na nota (2026-11-15).
 * Pedido #4162: NCM inexistente no cadastro, nota rejeitada (778) e, com o
 * NCM já corrigido, ninguém tentou de novo: 10 horas sem etiqueta.
 */
class VendaDoSiteNaoTravaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Order, 1: ProductFiscalData} */
    private function vendaDoSiteComNotaRejeitada(): array
    {
        $produto = Product::factory()->create();
        $fiscal = ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '84629920']);

        $order = Order::create([
            'user_id' => User::factory()->create()->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'site_origem' => Order::SITE_FERRAKORA, 'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '01001000',
            'shipping_street' => 'R', 'shipping_number' => '1', 'shipping_neighborhood' => 'B', 'shipping_city' => 'Brasília',
            'shipping_state' => 'DF', 'subtotal' => 121.66, 'total' => 121.66,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Parafusadeira 48V', 'product_price' => 121.66, 'quantity' => 1, 'subtotal' => 121.66, 'item_type' => OrderItem::TYPE_PRODUCT]);

        $nota = Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_REJECTED, 'numero' => 3594, 'motivo_rejeicao' => '778: Informado NCM inexistente']);
        Invoice::whereKey($nota->id)->update(['updated_at' => now()->subHours(2)]);
        ProductFiscalData::whereKey($fiscal->id)->update(['updated_at' => now()->subHours(3)]);

        return [$order, $fiscal];
    }

    public function test_nota_rejeitada_da_loja_espera_a_correcao_do_cadastro(): void
    {
        Queue::fake();
        $this->vendaDoSiteComNotaRejeitada();

        $this->artisan('nfe:retry-stuck')->assertSuccessful();

        // Reenviar igual só repetiria a rejeição e gastaria número de nota.
        Queue::assertNotPushed(GenerateInvoiceJob::class);
    }

    public function test_nota_rejeitada_da_loja_sai_sozinha_depois_de_corrigir_o_ncm(): void
    {
        Queue::fake();
        [$order, $fiscal] = $this->vendaDoSiteComNotaRejeitada();

        $fiscal->update(['ncm' => '84672992']);

        $this->artisan('nfe:retry-stuck')->assertSuccessful();

        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $order->id);
    }

    public function test_venda_paga_da_loja_sem_nota_nenhuma_entra_na_retentativa(): void
    {
        Queue::fake();
        [$order] = $this->vendaDoSiteComNotaRejeitada();
        Invoice::where('order_id', $order->id)->delete();

        $this->artisan('nfe:retry-stuck')->assertSuccessful();

        Queue::assertPushed(GenerateInvoiceJob::class, fn (GenerateInvoiceJob $job) => $job->orderId === $order->id);
    }

    public function test_ncm_que_nao_existe_nao_grava_e_o_anterior_fica(): void
    {
        config(['fiscal.validar_ncm_ao_gravar' => true]);
        $validador = Mockery::mock(NcmValidatorService::class);
        $validador->shouldReceive('isValid')->andReturnUsing(fn (string $ncm) => $ncm === '84672992');
        $this->app->instance(NcmValidatorService::class, $validador);

        $produto = Product::factory()->create();
        $fiscal = ProductFiscalData::create(['product_id' => $produto->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '84672992']);

        // Escrita de qualquer origem (clonagem, importação da Shopee, tarefa).
        $fiscal->update(['ncm' => '84629920', 'peso_bruto' => 2.75]);

        $this->assertSame('84672992', $fiscal->fresh()->ncm);
        $this->assertEquals(2.75, (float) $fiscal->fresh()->peso_bruto);

        // Cadastro novo com NCM inexistente fica com o NCM vazio, à vista.
        $novo = ProductFiscalData::create(['product_id' => Product::factory()->create()->id, ...ProductFiscalData::defaultMeiAttributes(), 'ncm' => '84629920']);
        $this->assertNull($novo->fresh()->ncm);
    }
}
