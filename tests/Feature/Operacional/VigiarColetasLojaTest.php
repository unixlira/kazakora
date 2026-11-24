<?php

namespace Tests\Feature\Operacional;

use App\Console\Commands\TaxasReaisAmazon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Mail\ColetaParada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** loja:vigiar-coletas: e-mail quando uma coleta para e quando volta (24/11/2026). */
class VigiarColetasLojaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        Storage::fake('local');
        Cache::flush();
        config([
            'services.alerta_coleta_email' => [
                'ativo' => true, 'para' => 'alerta@teste.com',
                'horas' => ['tiktok_demonstrativo' => 6, 'pedidos' => 24, 'amazon_taxas' => 4],
            ],
            'services.tiktok_reports.token' => 'segredo-de-teste',
            'services.whatsapp.alerta_coleta.ativo' => false,
        ]);
        $this->travelTo(now()->setDate(2026, 11, 21)->setTime(7, 12));
    }

    private function enviarDemonstrativo(string $nome, string $conteudo)
    {
        return $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/tiktok/reports/import', ['file' => UploadedFile::fake()->createWithContent($nome, $conteudo)]);
    }

    private function pedido(string $origem): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $origem,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
        ]);
    }

    private function avisosDe(string $nome, bool $voltou): int
    {
        return Mail::sent(ColetaParada::class, fn (ColetaParada $m) => $m->nome === $nome && $m->voltou === $voltou && $m->hasTo('alerta@teste.com'))->count();
    }

    public function test_demonstrativo_parado_avisa_uma_vez_e_avisa_quando_volta(): void
    {
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-21-11-2026.csv', 'novembro-21')->assertOk();

        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        Mail::assertNothingSent();

        // 7 h depois, nada chegou: um e-mail só, mesmo rodando de novo.
        $this->travel(7)->hours();
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->travel(1)->hours();
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->assertSame(1, $this->avisosDe('Demonstrativo do TikTok', false));
        Mail::assertSent(ColetaParada::class, fn (ColetaParada $m) => $m->ultima === '21/11/2026 07:12' && $m->limiteHoras === 6);

        // O agente volta a mandar o mesmo arquivo de novembro: conta como chegada.
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-21-11-2026.csv', 'novembro-21')->assertOk()->assertJsonPath('arquivos.0.duplicado', true);
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->assertSame(1, $this->avisosDe('Demonstrativo do TikTok', true));
        $this->assertSame(1, $this->avisosDe('Demonstrativo do TikTok', false));
    }

    public function test_reenvio_de_mes_antigo_nao_conta_como_chegada(): void
    {
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-21-11-2026.csv', 'novembro')->assertOk();
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-07-2026-31-07-2026.csv', 'julho')->assertOk();

        // O modo "completo" reenvia julho de hora em hora; novembro não vem.
        $this->travel(8)->hours();
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-07-2026-31-07-2026.csv', 'julho')->assertOk();

        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->assertSame(1, $this->avisosDe('Demonstrativo do TikTok', false));
    }

    public function test_api_recusa_mes_atual_com_conteudo_de_outro_mes(): void
    {
        // Causa do 21/11: o agente baixou o relatório de outubro (pronto há
        // segundos no painel) e mandou com o nome de novembro.
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-10-2026-31-10-2026.csv', 'outubro')->assertOk();

        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-24-11-2026.csv', 'outubro')
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJson(fn ($json) => $json->where('erro', fn ($erro) => str_contains($erro, 'relatório errado'))->etc());

        // Mesmo mês com nome de outro dia (sem liquidação nova) continua aceito.
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-10-2026-30-10-2026.csv', 'outubro')->assertOk()->assertJsonPath('arquivos.0.duplicado', true);
        $this->assertDatabaseCount('tiktok_report_uploads', 1);
    }

    public function test_pedidos_de_cada_marketplace_param_e_voltam(): void
    {
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-21-11-2026.csv', 'x')->assertOk();
        foreach ([Order::ORIGIN_TIKTOK_SHOP, Order::ORIGIN_SHOPEE, Order::ORIGIN_MERCADO_LIVRE] as $origem) {
            $this->pedido($origem);
        }

        // 20 h sem pedido é normal (madrugada): fica quieto.
        $this->travel(20)->hours();
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-22-11-2026.csv', 'y')->assertOk();
        $this->pedido(Order::ORIGIN_SHOPEE);
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        Mail::assertNothingSent();

        // 25 h: TikTok Shop e Mercado Livre parados; Shopee teve pedido há 5 h.
        $this->travel(5)->hours();
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-22-11-2026.csv', 'z')->assertOk();
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->assertSame(1, $this->avisosDe('Pedidos do TikTok Shop', false));
        $this->assertSame(1, $this->avisosDe('Pedidos do Mercado Livre', false));
        $this->assertSame(0, $this->avisosDe('Pedidos do Shopee', false));

        $this->pedido(Order::ORIGIN_TIKTOK_SHOP);
        $this->artisan('loja:vigiar-coletas')->assertSuccessful();
        $this->assertSame(1, $this->avisosDe('Pedidos do TikTok Shop', true));
        $this->assertSame(0, $this->avisosDe('Pedidos do Mercado Livre', true));
    }

    public function test_taxas_da_amazon_paradas_mostram_o_erro(): void
    {
        $this->enviarDemonstrativo('tiktok_demonstrativo_01-11-2026-21-11-2026.csv', 'x')->assertOk();
        Cache::put(TaxasReaisAmazon::CACHE_ULTIMA, [
            'ok' => false, 'em' => now()->toIso8601String(), 'erro' => 'Access to requested resource is denied.',
            'ultimo_ok' => now()->subHours(5)->toIso8601String(),
        ]);

        $this->artisan('loja:vigiar-coletas')->assertSuccessful();

        Mail::assertSent(ColetaParada::class, fn (ColetaParada $m) => $m->nome === 'Taxas reais da Amazon' && ! $m->voltou
            && str_contains((string) $m->detalhe, 'Access to requested resource is denied.'));
        $this->assertSame(0, $this->avisosDe('Demonstrativo do TikTok', false));
    }

    public function test_desligado_nao_manda_nada(): void
    {
        config(['services.alerta_coleta_email.ativo' => false]);

        $this->artisan('loja:vigiar-coletas')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertNull(Cache::get('loja:vigiar-coletas:estado:tiktok_demonstrativo'));
    }

    public function test_email_renderiza(): void
    {
        $html = (new ColetaParada('Demonstrativo do TikTok', false, '21/11/2026 07:12', 6, null, 'Veja o agente.'))->render();

        $this->assertStringContainsString('parou de chegar', $html);
        $this->assertStringContainsString('21/11/2026 07:12', $html);
        $this->assertSame(0, DB::table('tiktok_report_uploads')->count());
    }
}
