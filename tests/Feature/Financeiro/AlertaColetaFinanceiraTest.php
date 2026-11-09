<?php

namespace Tests\Feature\Financeiro;

use App\Console\Commands\TaxasReaisAmazon;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\AlertaColetaFinanceira;
use App\Modules\Marketplace\Support\RelatoriosAmazon;
use App\Modules\Marketplace\Support\RelatoriosTikTok;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Falha na coleta financeira vira alerta do painel do admin. Desde 09/11/2026
 * (pedido do Lira, tarefa 3965) a Manuela não manda aviso de erro no WhatsApp.
 */
class AlertaColetaFinanceiraTest extends TestCase
{
    use RefreshDatabase;

    private const LIRA = '5511900000000';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.access_token' => 'token-teste',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.graph_url' => 'https://graph.facebook.com/v20.0',
            'services.tiktok_reports.token' => 'segredo-de-teste',
        ]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        // Lira com a janela de 24 h aberta: antes isso fazia a mensagem sair.
        WhatsAppConversation::create(['wa_id' => self::LIRA, 'phone' => self::LIRA, 'last_customer_message_at' => now()->subHour()]);
    }

    private function semWhatsApp(): void
    {
        Http::assertNothingSent();
        $this->assertDatabaseMissing('whatsapp_messages', ['sent_by' => 'manuela']);
    }

    public function test_falha_abre_alerta_no_painel_e_nao_manda_whatsapp(): void
    {
        $status = app(AlertaColetaFinanceira::class)->avisar('Amazon', 'Seller Central pediu login de novo.');

        $this->assertSame('painel', $status);
        $this->assertDatabaseHas('admin_alertas', [
            'tipo' => AlertasAdmin::COLETA_FINANCEIRA, 'chave' => 'amazon', 'mensagem' => 'Seller Central pediu login de novo.',
        ]);
        $this->semWhatsApp();
    }

    public function test_falha_do_agente_do_tiktok_nao_manda_whatsapp(): void
    {
        Notification::fake();

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->postJson('/api/tiktok/reports/status', ['ok' => false, 'mensagem' => 'Sessão do TikTok caiu'])
            ->assertOk();

        $this->assertDatabaseHas('admin_alertas', ['chave' => 'tiktok', 'mensagem' => 'Sessão do TikTok caiu']);
        $this->semWhatsApp();
    }

    public function test_falha_do_agente_da_amazon_nao_manda_whatsapp(): void
    {
        Notification::fake();

        app(RelatoriosAmazon::class)->avisar(false, 'Seller Central pediu login');

        $this->assertDatabaseHas('admin_alertas', ['chave' => 'amazon']);
        $this->semWhatsApp();
    }

    public function test_sucesso_do_tiktok_nao_avisa(): void
    {
        Notification::fake();

        app(RelatoriosTikTok::class)->avisar(true, 'Relatório importado');

        $this->assertDatabaseMissing('admin_alertas', ['chave' => 'tiktok', 'resolvido_em' => null]);
        $this->semWhatsApp();
    }

    public function test_api_de_falha_exige_token_e_canal_conhecido(): void
    {
        $this->postJson('/api/financeiro/coletas/falha', ['canal' => 'amazon', 'motivo' => 'x'])->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->postJson('/api/financeiro/coletas/falha', ['canal' => 'shopee', 'motivo' => 'x'])->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->postJson('/api/financeiro/coletas/falha', ['canal' => 'amazon', 'motivo' => 'Relatório de transações voltou vazio'])
            ->assertOk()
            ->assertJsonPath('whatsapp', 'painel');

        $this->assertDatabaseHas('admin_alertas', ['chave' => 'amazon', 'mensagem' => 'Relatório de transações voltou vazio']);
        $this->semWhatsApp();
    }

    public function test_vigia_abre_alerta_do_tiktok_parado_sem_whatsapp(): void
    {
        $this->artisan('financeiro:vigiar-coletas')->assertSuccessful();

        $this->assertDatabaseHas('admin_alertas', ['chave' => 'tiktok']);
        $this->assertStringContainsString('Nenhum relatório do TikTok chegou nunca', (string) DB::table('admin_alertas')->where('chave', 'tiktok')->value('mensagem'));
        $this->semWhatsApp();
    }

    public function test_vigia_abre_alerta_de_venda_da_amazon_antiga_sem_taxa_real(): void
    {
        DB::table('tiktok_report_uploads')->insert([
            'nome_original' => 'Income.xlsx', 'sha256' => str_repeat('b', 64), 'caminho' => 'x', 'tamanho' => 1,
            'status' => 'importado', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pedido = fn (int $dias, string $status = Order::STATUS_COMPLETED) => tap(Order::create([
            'status' => $status, 'origin' => Order::ORIGIN_AMAZON,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
        ]), fn (Order $o) => $o->forceFill(['created_at' => now()->subDays($dias)])->save());

        $semTaxa = $pedido(6);
        $pedido(6, Order::STATUS_CANCELLED);
        $pedido(1); // recente: a Amazon ainda não liquidou

        $this->artisan('financeiro:vigiar-coletas')->assertSuccessful();

        $mensagem = (string) DB::table('admin_alertas')->where('chave', 'amazon')->value('mensagem');
        $this->assertStringContainsString("1 venda(s) da Amazon com mais de 4 dias sem taxa real (#{$semTaxa->id})", $mensagem);
        $this->assertStringContainsString('API da Amazon', $mensagem);
        $this->semWhatsApp();
    }

    public function test_vigia_da_amazon_conta_a_falha_da_ultima_coleta_pela_api(): void
    {
        DB::table('tiktok_report_uploads')->insert([
            'nome_original' => 'Income.xlsx', 'sha256' => str_repeat('c', 64), 'caminho' => 'x', 'tamanho' => 1,
            'status' => 'importado', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
        ])->forceFill(['created_at' => now()->subDays(6)])->save();
        Cache::put(TaxasReaisAmazon::CACHE_ULTIMA, ['ok' => false, 'em' => now()->toIso8601String(), 'erro' => 'Access to requested resource is denied.', 'ultimo_ok' => null]);

        $this->artisan('financeiro:vigiar-coletas')->assertSuccessful();

        $mensagem = (string) DB::table('admin_alertas')->where('chave', 'amazon')->value('mensagem');
        $this->assertStringContainsString('falhou: Access to requested resource is denied.', $mensagem);
        $this->assertStringContainsString('Último sucesso: nunca', $mensagem);
        $this->semWhatsApp();
    }
}
