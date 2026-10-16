<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\AlertaColetaFinanceira;
use App\Modules\Marketplace\Support\RelatoriosTikTok;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Falha na coleta financeira vira WhatsApp da Manuela ao Lira (2026-10-16). */
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
            'services.whatsapp.alerta_coleta' => ['ativo' => true, 'para' => self::LIRA, 'template' => null, 'template_idioma' => 'pt_BR', 'intervalo_minutos' => 60],
            'services.tiktok_reports.token' => 'segredo-de-teste',
        ]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
    }

    private function liraFalouHa(int $horas): WhatsAppConversation
    {
        return WhatsAppConversation::create(['wa_id' => self::LIRA, 'phone' => self::LIRA, 'last_customer_message_at' => now()->subHours($horas)]);
    }

    public function test_dentro_da_janela_manda_texto_com_o_motivo(): void
    {
        $conversa = $this->liraFalouHa(2);

        $status = app(AlertaColetaFinanceira::class)->avisar('Amazon', 'Seller Central pediu login de novo.');

        $this->assertSame('sent', $status);
        Http::assertSent(fn (Request $r) => $r['to'] === self::LIRA && $r['type'] === 'text'
            && str_contains($r['text']['body'], 'Amazon') && str_contains($r['text']['body'], 'Seller Central pediu login'));
        $this->assertDatabaseHas('whatsapp_messages', ['conversation_id' => $conversa->id, 'sent_by' => 'manuela', 'status' => 'sent']);
    }

    public function test_mesmo_motivo_nao_repete_dentro_do_intervalo(): void
    {
        $this->liraFalouHa(1);
        $alerta = app(AlertaColetaFinanceira::class);

        $this->assertSame('sent', $alerta->avisar('TikTok', 'Sessão caiu'));
        $this->assertSame('repetido', $alerta->avisar('TikTok', 'Sessão caiu'));
        $this->assertSame('sent', $alerta->avisar('TikTok', 'Tela mudou'));
        Http::assertSentCount(2);
    }

    public function test_fora_da_janela_usa_template_com_canal_e_motivo(): void
    {
        $this->liraFalouHa(30);
        config(['services.whatsapp.alerta_coleta.template' => 'alerta_interno']);

        $status = app(AlertaColetaFinanceira::class)->avisar('TikTok', "Erro 500\nna importação");

        $this->assertSame('template', $status);
        Http::assertSent(fn (Request $r) => $r['type'] === 'template' && $r['template']['name'] === 'alerta_interno'
            && $r['template']['components'][0]['parameters'][1]['text'] === 'Erro 500 na importação');
    }

    public function test_fora_da_janela_sem_template_grava_e_tenta_de_novo_depois(): void
    {
        $this->liraFalouHa(30);
        $alerta = app(AlertaColetaFinanceira::class);

        $this->assertSame('fora_da_janela', $alerta->avisar('Amazon', 'Sem resposta'));
        $this->assertSame('fora_da_janela', $alerta->avisar('Amazon', 'Sem resposta'));
        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_messages', ['status' => 'fora_da_janela', 'sent_by' => 'manuela']);
    }

    public function test_desligado_nao_manda_nada(): void
    {
        $this->liraFalouHa(1);
        config(['services.whatsapp.alerta_coleta.ativo' => false]);

        $this->assertSame('desligado', app(AlertaColetaFinanceira::class)->avisar('Amazon', 'x'));
        Http::assertNothingSent();
    }

    public function test_falha_do_agente_do_tiktok_avisa_no_whatsapp(): void
    {
        Notification::fake();
        $this->liraFalouHa(1);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->postJson('/api/tiktok/reports/status', ['ok' => false, 'mensagem' => 'Sessão do TikTok caiu'])
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'], 'Sessão do TikTok caiu'));
    }

    public function test_sucesso_do_tiktok_nao_avisa(): void
    {
        Notification::fake();
        $this->liraFalouHa(1);

        app(RelatoriosTikTok::class)->avisar(true, 'Relatório importado');

        Http::assertNothingSent();
    }

    public function test_api_de_falha_exige_token_e_canal_conhecido(): void
    {
        $this->liraFalouHa(1);

        $this->postJson('/api/financeiro/coletas/falha', ['canal' => 'amazon', 'motivo' => 'x'])->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->postJson('/api/financeiro/coletas/falha', ['canal' => 'shopee', 'motivo' => 'x'])->assertStatus(422);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->postJson('/api/financeiro/coletas/falha', ['canal' => 'amazon', 'motivo' => 'Relatório de transações voltou vazio'])
            ->assertOk()
            ->assertJsonPath('whatsapp', 'sent');

        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'], 'Amazon') && str_contains($r['text']['body'], 'voltou vazio'));
    }

    public function test_vigia_avisa_tiktok_parado_e_fica_quieta_com_relatorio_recente(): void
    {
        $this->liraFalouHa(1);

        $this->artisan('financeiro:vigiar-coletas')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'], 'Nenhum relatório do TikTok chegou nunca'));

        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        cache()->flush();
        DB::table('tiktok_report_uploads')->insert([
            'nome_original' => 'Income.xlsx', 'sha256' => str_repeat('a', 64), 'caminho' => 'x', 'tamanho' => 1,
            'status' => 'importado', 'created_at' => now()->subHours(3), 'updated_at' => now()->subHours(3),
        ]);

        $this->artisan('financeiro:vigiar-coletas')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_vigia_avisa_venda_da_amazon_antiga_sem_taxa_real(): void
    {
        $this->liraFalouHa(1);
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

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['text']['body'], "1 venda(s) da Amazon com mais de 4 dias sem taxa real (#{$semTaxa->id})"));
    }
}
