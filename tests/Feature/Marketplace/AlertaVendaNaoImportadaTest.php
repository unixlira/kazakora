<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\AlertaVendaNaoImportada;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Notifications\OrderImportFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * E-mail de "venda NÃO importada" (pedido do Lira 11/11/2026): falha
 * passageira da Shopee (conexão caída, venda recém-feita) mandava e-mail na
 * primeira rodada, e a seguinte importava. Agora só avisa a venda que
 * continua fora do sistema há 30 minutos, e repete no máximo de hora em hora.
 */
class AlertaVendaNaoImportadaTest extends TestCase
{
    use RefreshDatabase;

    private const FALHA = ['sn' => '2611112KCSNVRM', 'message' => 'cURL error 56: Connection reset by peer'];

    public function test_falha_passageira_nao_avisa_e_venda_presa_avisa_depois_de_30_min(): void
    {
        $this->travelTo('2026-11-11 11:00:00');
        $this->assertSame([], AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []));

        $this->travelTo('2026-11-11 11:20:00');
        $this->assertSame([], AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []));

        $this->travelTo('2026-11-11 11:31:00');
        $avisar = AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []);
        $this->assertCount(1, $avisar);
        $this->assertStringContainsString('fora do sistema há 31 min', $avisar[0]['message']);

        // Repete só depois de 1 hora.
        $this->travelTo('2026-11-11 11:50:00');
        $this->assertSame([], AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []));
        $this->travelTo('2026-11-11 12:32:00');
        $this->assertCount(1, AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []));
    }

    public function test_venda_que_entrou_sai_da_lista_e_o_relogio_recomeca(): void
    {
        $this->travelTo('2026-11-11 11:00:00');
        AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []);

        // Rodada sem falha nenhuma, com a venda importada: sai da lista.
        $this->travelTo('2026-11-11 11:10:00');
        AlertaVendaNaoImportada::filtrar('Shopee', [], [self::FALHA['sn']]);

        // Falha de novo bem depois: conta do zero, não avisa na hora.
        $this->travelTo('2026-11-11 15:00:00');
        $this->assertSame([], AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []));
    }

    public function test_canais_tem_listas_separadas(): void
    {
        $this->travelTo('2026-11-11 11:00:00');
        AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []);

        $this->travelTo('2026-11-11 11:40:00');
        $this->assertSame([], AlertaVendaNaoImportada::filtrar('Mercado Livre', [self::FALHA], []));
        $this->assertCount(1, AlertaVendaNaoImportada::filtrar('Shopee', [self::FALHA], []));
    }

    public function test_varredura_da_shopee_so_manda_email_da_venda_presa(): void
    {
        Notification::fake();
        config(['services.alerts.email' => 'alerta@example.com']);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'token_expires_at' => now()->addHour(),
        ]);

        $driver = $this->mock(ShopeeDriver::class);
        $driver->shouldReceive('listOrderSns')->andReturn(['OK1', self::FALHA['sn']]);
        $importer = $this->mock(OrderImportService::class);
        $importer->shouldReceive('import')->with(MarketplaceAccount::CHANNEL_SHOPEE, 'OK1', true);
        $importer->shouldReceive('import')->with(MarketplaceAccount::CHANNEL_SHOPEE, self::FALHA['sn'], true)
            ->andThrow(new RuntimeException(self::FALHA['message']));

        $this->travelTo('2026-11-11 11:00:00');
        $this->artisan('orders:sync-shopee')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo('2026-11-11 11:35:00');
        $this->artisan('orders:sync-shopee')->assertSuccessful();
        Notification::assertSentTo(new AnonymousNotifiable, OrderImportFailedNotification::class);
    }
}
