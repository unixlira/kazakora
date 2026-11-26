<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Analytics\Support\VisitaIgnorada;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contagem de visitas sem robôs e sem o dono/equipe (pedido do Lira em
 * 26/11/2026: "limpa isso"). Regras em VisitaIgnorada.
 */
class VisitasSemRoboEDonoTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const IP_CLIENTE = '189.40.12.7';

    private function abrir(string $userAgent = self::NAVEGADOR, string $ip = self::IP_CLIENTE, string $pagina = '/politica-de-entrega')
    {
        return $this->withHeader('User-Agent', $userAgent)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get($pagina);
    }

    private function todas()
    {
        return SiteVisit::query()->withoutGlobalScope(SiteVisit::SO_QUE_CONTA);
    }

    public function test_visitante_normal_conta(): void
    {
        $this->abrir()->assertOk();

        $visita = SiteVisit::query()->sole();
        $this->assertNull($visita->ignorada);
        $this->assertSame(self::IP_CLIENTE, $visita->ip);
    }

    /** @return array<string, array{0: string}> */
    public static function robos(): array
    {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'AhrefsBot' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'SemrushBot' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'facebookexternalhit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'meta-externalagent' => ['Mozilla/5.0 (compatible; meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler))'],
            'WhatsApp' => ['WhatsApp/2.23.20.0'],
            'curl' => ['curl/8.5.0'],
            'python-requests' => ['python-requests/2.31.0'],
            'HeadlessChrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36'],
            'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'],
            'UptimeRobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
            'auditoria do Pixel' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36 KazaKoraPixelAudit'],
            'sem navegador' => [''],
        ];
    }

    #[DataProvider('robos')]
    public function test_robo_nao_conta(string $userAgent): void
    {
        $this->abrir($userAgent)->assertOk();

        $this->assertSame(0, $this->todas()->count());
    }

    public function test_robo_do_google_com_cara_de_navegador_nao_conta(): void
    {
        $this->abrir(self::NAVEGADOR, '66.249.73.99')->assertOk();

        $this->assertSame(0, $this->todas()->count());
    }

    public function test_admin_logado_nao_conta(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin);
        $this->abrir()->assertOk();

        $this->assertSame(0, SiteVisit::query()->count());
        $visita = $this->todas()->sole();
        $this->assertSame(VisitaIgnorada::EQUIPE, $visita->ignorada);
        $this->assertSame($admin->id, $visita->user_id);
    }

    public function test_cliente_logado_conta(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($cliente);
        $this->abrir()->assertOk();

        $this->assertNull(SiteVisit::query()->sole()->ignorada);
    }

    public function test_ip_ignorado_nao_conta(): void
    {
        config(['visitas.ips_ignorados' => ['200.10.0.0/16']]);

        $this->abrir(self::NAVEGADOR, '200.10.5.6')->assertOk();

        $this->assertSame(0, SiteVisit::query()->count());
        $this->assertSame(VisitaIgnorada::IP_IGNORADO, $this->todas()->sole()->ignorada);
    }

    public function test_nossa_vps_nao_conta(): void
    {
        $this->abrir(self::NAVEGADOR, '187.127.60.51')->assertOk();

        $this->assertSame(VisitaIgnorada::IP_IGNORADO, $this->todas()->sole()->ignorada);
    }

    public function test_navegador_da_equipe_deslogado_nao_conta(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        DB::table('site_visits')->insert(['site' => 'kazakora', 'visitor_id' => 'navegador-do-dono', 'user_id' => $admin->id,
            'ip' => '2804:1b3:af40:de1d::1', 'path' => '/', 'created_at' => now()->subDays(20)]);

        $this->withCookie(Cookies::COOKIE_VISITANTE, 'navegador-do-dono');
        $this->abrir()->assertOk();

        $this->assertSame(0, SiteVisit::query()->count());
    }

    public function test_celular_na_mesma_rede_da_equipe_nao_conta_e_outra_rede_conta(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        DB::table('site_visits')->insert(['site' => 'kazakora', 'visitor_id' => 'navegador-do-dono', 'user_id' => $admin->id,
            'ip' => '2804:1b3:af40:de1d:10da:48a9:ebc9:415e', 'path' => '/', 'created_at' => now()->subHours(5)]);

        // Celular de casa, deslogado, outro endereço no mesmo /64.
        $this->abrir(self::NAVEGADOR, '2804:1b3:af40:de1d:cbd:5add:cc4c:dabf')->assertOk();
        $this->assertSame(VisitaIgnorada::CASA_EQUIPE, $this->todas()->latest('id')->first()->ignorada);

        // Vizinho do mesmo provedor, outro /64: conta.
        $this->abrir(self::NAVEGADOR, '2804:1b3:af40:9999::1')->assertOk();
        $this->assertSame(1, SiteVisit::query()->where('ip', '2804:1b3:af40:9999::1')->count());
    }

    public function test_rede_da_equipe_vence_depois_de_alguns_dias(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        DB::table('site_visits')->insert(['site' => 'kazakora', 'visitor_id' => 'navegador-do-dono', 'user_id' => $admin->id,
            'ip' => '177.68.21.79', 'path' => '/', 'created_at' => now()->subDays(10)]);

        $this->abrir(self::NAVEGADOR, '177.68.21.79')->assertOk();

        $this->assertSame(1, SiteVisit::query()->count());
    }

    public function test_outro_site_robo_e_ip_ignorado(): void
    {
        $headers = ['Origin' => 'https://ferrakora.devlira.com.br'];

        $this->withHeaders([...$headers, 'User-Agent' => 'curl/8.5.0'])->withServerVariables(['REMOTE_ADDR' => self::IP_CLIENTE])
            ->post('/api/ferrakora/visita?p=/')->assertNoContent();
        $this->assertSame(0, $this->todas()->count());

        $this->withHeaders([...$headers, 'User-Agent' => self::NAVEGADOR])->withServerVariables(['REMOTE_ADDR' => '187.127.60.51'])
            ->post('/api/ferrakora/visita?p=/')->assertNoContent();
        $this->assertSame(VisitaIgnorada::IP_IGNORADO, $this->todas()->sole()->ignorada);

        $this->withHeaders([...$headers, 'User-Agent' => self::NAVEGADOR])->withServerVariables(['REMOTE_ADDR' => self::IP_CLIENTE])
            ->post('/api/ferrakora/visita?p=/')->assertNoContent();
        $this->assertSame(1, SiteVisit::query()->count());
    }

    public function test_rede_normaliza_o_ipv6(): void
    {
        $this->assertSame('2804:1b3:af40:de1d::/64', VisitaIgnorada::rede('2804:01b3:af40:de1d:0:0:0:1'));
        $this->assertSame('177.68.21.79', VisitaIgnorada::rede('177.68.21.79'));
        $this->assertNull(VisitaIgnorada::rede('lixo'));
    }

    private function linha(array $campos): int
    {
        return DB::table('site_visits')->insertGetId([
            'site' => 'kazakora', 'visitor_id' => 'v-'.uniqid(), 'path' => '/', 'created_at' => '2026-11-20 10:00:00', ...$campos,
        ]);
    }

    public function test_limpar_historico_marca_por_motivo_e_dry_run_nao_muda_nada(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        config(['visitas.ips_ignorados' => ['200.10.5.6']]);

        $cliente = $this->linha(['ip' => self::IP_CLIENTE, 'user_agent' => self::NAVEGADOR]);
        $semOk = $this->linha(['ip' => '201.1.2.3']);
        $robo = $this->linha(['ip' => self::IP_CLIENTE, 'user_agent' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0)']);
        $google = $this->linha(['ip' => '66.249.73.100']);
        $equipe = $this->linha(['ip' => '2804:1b3:af40:de1d::10', 'user_id' => $admin->id, 'visitor_id' => 'nav-dono']);
        $mesmoNavegador = $this->linha(['ip' => '177.68.21.79', 'visitor_id' => 'nav-dono', 'created_at' => '2026-10-01 10:00:00']);
        $casa = $this->linha(['ip' => '2804:1b3:af40:de1d::99', 'created_at' => '2026-11-21 08:00:00']);
        $casaOutraSemana = $this->linha(['ip' => '2804:1b3:af40:de1d::99', 'created_at' => '2026-11-25 08:00:00']);
        $lista = $this->linha(['ip' => '200.10.5.6']);
        $vps = $this->linha(['ip' => '2a02:4780:6e:1825::1', 'site' => 'ferrakora']);
        $volume = [];
        foreach (range(1, SiteVisit::VISITAS_DIA_ROBO + 1) as $i) {
            $volume[] = $this->linha(['ip' => '45.160.39.179', 'visitor_id' => 'aba-presa', 'created_at' => '2026-11-03 10:00:00']);
        }

        $this->artisan('visitas:limpar', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, $this->todas()->whereNotNull('ignorada')->count());

        $this->artisan('visitas:limpar')->assertSuccessful();

        $motivo = fn (int $id) => $this->todas()->find($id)->ignorada;
        $this->assertNull($motivo($cliente));
        $this->assertNull($motivo($semOk));
        $this->assertNull($motivo($casaOutraSemana));
        $this->assertSame(VisitaIgnorada::ROBO, $motivo($robo));
        $this->assertSame(VisitaIgnorada::ROBO, $motivo($google));
        $this->assertSame(VisitaIgnorada::EQUIPE, $motivo($equipe));
        $this->assertSame(VisitaIgnorada::EQUIPE, $motivo($mesmoNavegador));
        $this->assertSame(VisitaIgnorada::CASA_EQUIPE, $motivo($casa));
        $this->assertSame(VisitaIgnorada::IP_IGNORADO, $motivo($lista));
        $this->assertSame(VisitaIgnorada::IP_IGNORADO, $motivo($vps));
        $this->assertSame(VisitaIgnorada::ROBO_VOLUME, $motivo($volume[0]));
        $this->assertSame(3, SiteVisit::query()->count());

        // Desfaz pelo arquivo que a limpeza deixou.
        $arquivos = glob(storage_path('app/private/visitas-limpar/*.json'));
        $this->assertNotEmpty($arquivos);
        $this->artisan('visitas:limpar', ['--desfazer' => end($arquivos)])->assertSuccessful();
        $this->assertSame($this->todas()->count(), SiteVisit::query()->count());
        array_map('unlink', $arquivos);
    }
}
