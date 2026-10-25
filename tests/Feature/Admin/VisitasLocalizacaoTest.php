<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Support\LocalDoIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Card de localização no olho das Visitas (pedido 2026-10-24): latitude,
 * longitude e CEP aproximados que a Cloudflare tira do IP, gravados em
 * cada visita da loja e dos outros sites.
 */
class VisitasLocalizacaoTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://kazakora.com']);
    }

    private function visita(string $quando, string $ip, ?float $lat = null, ?float $lng = null, ?string $cep = null, string $site = 'kazakora'): void
    {
        DB::table('site_visits')->insert([
            'site' => $site, 'visitor_id' => md5($ip), 'path' => '/', 'ip' => $ip, 'consentiu' => false,
            'created_at' => $quando, 'latitude' => $lat, 'longitude' => $lng, 'cep' => $cep,
        ]);
    }

    /** Base de IP de mentira: o teste não depende do arquivo de 120 MB. */
    private function localDoIp(?array $lugar): void
    {
        $this->app->instance(LocalDoIp::class, new class($lugar) extends LocalDoIp
        {
            public function __construct(private readonly ?array $lugar) {}

            public function disponivel(): bool
            {
                return $this->lugar !== null;
            }

            public function buscar(string $ip): ?array
            {
                return $this->lugar;
            }
        });
    }

    public function test_visita_da_loja_grava_latitude_longitude_e_cep_da_cloudflare(): void
    {
        $this->withHeaders([
            'User-Agent' => self::NAVEGADOR,
            'CF-IPLatitude' => '-23.5475',
            'CF-IPLongitude' => '-46.63611',
            'CF-Postal-Code' => '01000-000',
        ])->get('https://kazakora.com/politica-de-entrega');

        $visita = SiteVisit::sole();
        $this->assertSame(-23.5475, (float) $visita->latitude);
        $this->assertSame(-46.63611, (float) $visita->longitude);
        $this->assertSame('01000000', $visita->cep);
    }

    public function test_sem_cloudflare_ou_com_valor_estranho_fica_nulo(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)->get('https://kazakora.com/politica-de-entrega');
        $this->withHeaders(['User-Agent' => self::NAVEGADOR, 'CF-IPLatitude' => '123', 'CF-IPLongitude' => 'abc', 'CF-Postal-Code' => '123456789'])
            ->get('https://kazakora.com/politica-de-entrega');

        foreach (SiteVisit::query()->get() as $visita) {
            $this->assertNull($visita->latitude);
            $this->assertNull($visita->longitude);
            $this->assertNull($visita->cep);
        }
        $this->assertSame(2, SiteVisit::count());
    }

    public function test_aviso_do_ferrakora_tambem_grava_a_localizacao(): void
    {
        $this->withHeaders([
            'Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR,
            'CF-IPLatitude' => '-22.9064', 'CF-IPLongitude' => '-47.0616', 'CF-Postal-Code' => '13010',
        ])->post('/api/ferrakora/visita?p=/')->assertNoContent();

        $visita = SiteVisit::sole();
        $this->assertSame('ferrakora', $visita->site);
        $this->assertSame(-22.9064, (float) $visita->latitude);
        $this->assertSame('13010', $visita->cep);
    }

    public function test_olho_mostra_a_localizacao_mais_recente_e_as_outras_do_periodo(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->localDoIp(null);
        $this->visita('2026-10-24 09:00:00', '189.40.12.7', -23.5475, -46.63611, '01000');
        $this->visita('2026-10-24 10:00:00', '189.40.12.7', -23.5475, -46.63611, '01000');
        $this->visita('2026-10-24 11:00:00', '189.40.12.7', -22.9064, -47.0616, '13010000');
        $this->visita('2026-10-24 12:00:00', '189.40.12.7');
        $this->visita('2026-10-24 12:00:00', '8.8.8.8', 37.4, -122.0, '94043');

        $this->actingAs($admin)->getJson('/admin/visitas-site/visitante?de=2026-10-24&ate=2026-10-24&ip=189.40.12.7&todos=1')
            ->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('localizacao.com_localizacao', 3)
            ->assertJsonCount(2, 'localizacao.capturas')
            ->assertJsonPath('localizacao.capturas.0.cep', '13010000')
            ->assertJsonPath('localizacao.capturas.0.visitas', 1)
            ->assertJsonPath('localizacao.capturas.0.ultima', '24/10/2026 11:00:00')
            ->assertJsonPath('localizacao.capturas.1.latitude', -23.5475)
            ->assertJsonPath('localizacao.capturas.1.longitude', -46.63611)
            ->assertJsonPath('localizacao.capturas.1.visitas', 2)
            ->assertJsonPath('localizacao.base', null);
    }

    public function test_sem_captura_o_olho_mostra_a_estimativa_da_base_do_servidor(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->localDoIp(['pais' => 'BR', 'uf' => 'SP', 'estado' => 'São Paulo', 'cidade' => 'Campinas', 'latitude' => -22.9, 'longitude' => -47.06]);
        $this->visita('2026-10-20 09:00:00', '189.40.12.7');

        $this->actingAs($admin)->getJson('/admin/visitas-site/visitante?de=2026-10-20&ate=2026-10-20&ip=189.40.12.7&todos=1')
            ->assertOk()
            ->assertJsonCount(0, 'localizacao.capturas')
            ->assertJsonPath('localizacao.com_localizacao', 0)
            ->assertJsonPath('localizacao.base.cidade', 'Campinas')
            ->assertJsonPath('localizacao.base.latitude', -22.9);
    }

    public function test_olho_dos_outros_sites_tambem_traz_a_localizacao(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->localDoIp(null);
        $this->visita('2026-10-24 09:00:00', '189.40.12.7', -23.5475, -46.63611, '01000', 'ferrakora');
        $this->visita('2026-10-24 09:00:00', '189.40.12.7', -10.0, -50.0, '77000', 'kazakora');

        $this->actingAs($admin)->getJson('/admin/visitas-ferrakora/visitante?de=2026-10-24&ate=2026-10-24&ip=189.40.12.7')
            ->assertOk()
            ->assertJsonCount(1, 'localizacao.capturas')
            ->assertJsonPath('localizacao.capturas.0.cep', '01000');
    }

    public function test_privacidade_apaga_a_localizacao_junto_com_o_ip(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $this->visita('2026-04-01 09:00:00', '189.40.12.7', -23.5475, -46.63611, '01000');
        $this->visita('2026-10-20 09:00:00', '189.40.12.8', -23.5475, -46.63611, '01000');

        Artisan::call('privacidade:limpar');

        $antiga = SiteVisit::query()->orderBy('created_at')->first();
        $this->assertNull($antiga->ip);
        $this->assertNull($antiga->latitude);
        $this->assertNull($antiga->longitude);
        $this->assertNull($antiga->cep);
        $this->assertSame('01000', SiteVisit::query()->where('ip', '189.40.12.8')->value('cep'));
    }
}
