<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\NotaDoBrinde;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Fiscal\Services\BonificacaoDoPedido;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Brinde físico da oferta (pedido do Lira em 21/11/2026): Livro Falante + Lousa
 * LED. A venda sai com a nota do livro e, autorizada, uma segunda nota de
 * bonificação da lousa a R$ 0,00 (CFOP 5910/6910, CSOSN 102, sem pagamento);
 * o cliente recebe as duas por e-mail.
 */
class NotaBonificacaoBrindeTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35261165604590000107550020000036741000000010';

    private function empresa(): void
    {
        config(['nfe.ambiente' => 'producao']);
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']], 200)]);
        Company::firstOrCreate(['cnpj' => '12.345.678/0001-99'], [
            'razao_social' => 'Empresa Teste', 'inscricao_estadual' => '158.571.233.113',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'city' => 'São Paulo', 'state' => 'SP',
            'street' => 'Rua Teste', 'number' => '100', 'neighborhood' => 'Centro', 'zip' => '01000-000',
        ]);
    }

    private function livro(?array $brinde = ['nome' => 'Lousa Mágica LED com canetinhas', 'quantidade' => 1, 'ncm' => '95030099']): Product
    {
        $livro = Product::factory()->create(['price' => 147.99, 'brinde_fisico' => $brinde]);
        ProductFiscalData::create([
            'product_id' => $livro->id, 'ncm' => '95030099', 'cfop' => '5102', 'cfop_outros_estados' => '6102', 'origem' => 2,
            'unidade_tributavel' => 'UN', 'icms_situacao_tributaria' => '102', 'pis_situacao_tributaria' => '08', 'cofins_situacao_tributaria' => '08',
        ]);

        return $livro;
    }

    private function venda(Product $produto, string $uf = 'SP', int $quantidade = 1): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE, 'buyer_document' => '52998224725',
            'shipping_name' => 'Célia', 'shipping_phone' => '11999999999', 'shipping_email' => 'celia@exemplo.com',
            'shipping_zip' => '02018-012', 'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Santana',
            'shipping_city' => 'São Paulo', 'shipping_state' => $uf, 'subtotal' => 147.99, 'shipping_cost' => 0, 'total' => 147.99,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => 147.99, 'quantity' => $quantidade, 'subtotal' => 147.99 * $quantidade]);
        Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_AUTHORIZED, 'ambiente' => 'producao', 'serie' => 2, 'numero' => 3674 + $order->id, 'chave_acesso' => self::CHAVE, 'valor_total' => 147.99]);

        return $order->fresh(['invoice']);
    }

    public function test_venda_autorizada_abre_a_nota_de_bonificacao_do_brinde_uma_vez_so(): void
    {
        Queue::fake();
        $venda = $this->venda($this->livro(), quantidade: 2);
        $servico = app(BonificacaoDoPedido::class);

        $servico->depoisDaNota($venda, $venda->invoice);
        $servico->depoisDaNota($venda, $venda->invoice);

        $bonus = Order::query()->where('origin', Order::ORIGIN_BONUS_INVOICE)->sole();
        $this->assertSame(self::CHAVE, $bonus->fiscal_referenced_nfe_key);
        $this->assertSame('Remessa em bonificação', $bonus->fiscal_nature_operation);
        $this->assertSame(0.0, (float) $bonus->total);
        $item = $bonus->items()->sole();
        $this->assertSame('Lousa Mágica LED com canetinhas', $item->product_name);
        $this->assertSame(2, (int) $item->quantity);
        $this->assertSame(['5910', '6910', '102', 0.0], [$item->cfop, $item->cfop_outros_estados, $item->icms_situacao_tributaria, (float) $item->product_price]);
        Queue::assertPushed(GenerateInvoiceJob::class, fn ($job) => $job->orderId === $bonus->id);
    }

    public function test_produto_sem_brinde_nao_gera_nota_e_variacao_usa_o_brinde_do_pai(): void
    {
        Queue::fake();
        $semBrinde = $this->venda($this->livro(null));
        app(BonificacaoDoPedido::class)->depoisDaNota($semBrinde, $semBrinde->invoice);
        $this->assertSame(0, Order::query()->where('origin', Order::ORIGIN_BONUS_INVOICE)->count());

        $pai = $this->livro();
        $variacao = Product::factory()->create(['parent_product_id' => $pai->id]);
        ProductFiscalData::create(['product_id' => $variacao->id, 'ncm' => '95030099', 'cfop' => '5102', 'cfop_outros_estados' => '6102', 'origem' => 2, 'unidade_tributavel' => 'UN', 'icms_situacao_tributaria' => '102', 'pis_situacao_tributaria' => '08', 'cofins_situacao_tributaria' => '08']);
        Invoice::query()->update(['chave_acesso' => null]);
        $venda = $this->venda($variacao);
        $this->assertNotNull(app(BonificacaoDoPedido::class)->criarPara($venda));
    }

    public function test_xml_da_bonificacao_sai_com_cfop_5910_6910_sem_pagamento_e_valor_zero(): void
    {
        Queue::fake();
        $this->empresa();
        foreach (['SP' => '5910', 'PE' => '6910'] as $uf => $cfop) {
            Invoice::query()->delete();
            Order::query()->delete();
            $venda = $this->venda($this->livro(), $uf);
            $bonus = app(BonificacaoDoPedido::class)->criarPara($venda);

            $xml = app(NFeXmlBuilderService::class)->build($bonus->fresh(), 10)['xml'];

            $this->assertStringContainsString('<natOp>Remessa em bonificação</natOp>', $xml);
            $this->assertStringContainsString("<CFOP>{$cfop}</CFOP>", $xml);
            $this->assertStringContainsString('<CSOSN>102</CSOSN>', $xml);
            $this->assertStringContainsString('<tPag>90</tPag>', $xml);
            $this->assertStringContainsString('<vProd>0.00</vProd>', $xml);
            $this->assertStringContainsString('<vNF>0.00</vNF>', $xml);
            $this->assertStringContainsString(self::CHAVE, $xml);
        }
    }

    public function test_recusada_com_zero_sai_de_novo_com_um_centavo(): void
    {
        Queue::fake();
        $venda = $this->venda($this->livro());
        $bonus = app(BonificacaoDoPedido::class)->criarPara($venda);
        $recusa = Invoice::create(['order_id' => $bonus->id, 'status' => Invoice::STATUS_REJECTED, 'ambiente' => 'producao', 'serie' => 2, 'numero' => 3700, 'motivo_rejeicao' => 'valor zero']);

        app(BonificacaoDoPedido::class)->depoisDaNota($bonus->fresh(), $recusa);

        $this->assertSame(0.01, (float) $bonus->fresh()->total);
        $this->assertSame(0.01, (float) $bonus->items()->sole()->product_price);
        // Já com 0,01: não mexe de novo.
        $this->assertFalse(app(BonificacaoDoPedido::class)->tentarComValorSimbolico($bonus->fresh()));
    }

    public function test_bonificacao_autorizada_manda_as_duas_notas_ao_cliente_uma_vez(): void
    {
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        $venda = $this->venda($this->livro());
        Storage::disk('local')->put('nfe/venda.pdf', '%PDF-venda');
        $venda->invoice->update(['danfe_path' => 'nfe/venda.pdf']);
        $bonus = app(BonificacaoDoPedido::class)->criarPara($venda);
        Storage::disk('local')->put('nfe/brinde.pdf', '%PDF-brinde');
        $autorizada = Invoice::create(['order_id' => $bonus->id, 'status' => Invoice::STATUS_AUTHORIZED, 'ambiente' => 'producao', 'serie' => 2, 'numero' => 3701, 'chave_acesso' => '35261165604590000107550020000037011000000019', 'danfe_path' => 'nfe/brinde.pdf']);

        app(BonificacaoDoPedido::class)->depoisDaNota($bonus->fresh(), $autorizada);
        app(BonificacaoDoPedido::class)->depoisDaNota($bonus->fresh(), $autorizada);

        Mail::assertSent(NotaDoBrinde::class, 1);
        Mail::assertSent(NotaDoBrinde::class, function (NotaDoBrinde $mail) {
            $html = $mail->render();

            return $mail->hasTo('celia@exemplo.com')
                && count($mail->attachments()) === 2
                && str_contains($html, 'Segue a nota fiscal do seu brinde')
                && ! str_contains(mb_strtolower($html), 'desculp');
        });
    }

    public function test_bonificacao_nao_conta_como_pedido_nem_dispara_recibo(): void
    {
        Queue::fake();
        $venda = $this->venda($this->livro());
        app(BonificacaoDoPedido::class)->criarPara($venda);

        $this->assertSame(1, Order::query()->nonPurchaseReturn()->count());
        $this->assertSame(0, Order::filtrarCanal(Order::query(), Order::ORIGIN_STORE)->where('origin', Order::ORIGIN_BONUS_INVOICE)->count());
    }

    public function test_admin_configura_e_desliga_o_brinde_fisico(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $produto = $this->livro(null);

        $this->actingAs($admin)->put("/admin/produtos/{$produto->id}/brinde-fisico", ['nome' => 'Lousa LED', 'quantidade' => 1, 'ncm' => '9503.00.99'])->assertRedirect();
        $this->assertSame(['nome' => 'Lousa LED', 'quantidade' => 1, 'ncm' => '95030099', 'origem' => null], $produto->fresh()->brinde_fisico);

        $this->actingAs($admin)->put("/admin/produtos/{$produto->id}/brinde-fisico", ['nome' => ''])->assertRedirect();
        $this->assertNull($produto->fresh()->brinde_fisico);
    }
}
