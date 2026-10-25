<?php

namespace Tests\Feature\Loja;

use App\Modules\Analytics\Models\VisitanteGeo;
use App\Modules\Analytics\Support\GeoDoVisitante;
use App\Modules\Analytics\Support\LocalDoIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GeoDoVisitanteTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36';

    /** Cabeçalhos da regra "Add visitor location headers" da Cloudflare. */
    private const CLOUDFLARE = [
        'X-Forwarded-For' => '189.40.1.2',
        'CF-IPLatitude' => '-23.5475',
        'CF-IPLongitude' => '-46.63611',
        'CF-Postal-Code' => '01310100',
        'CF-IPCity' => 'São Paulo',
        'CF-Region-Code' => 'SP',
        'CF-IPCountry' => 'BR',
        'CF-Timezone' => 'America/Sao_Paulo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_teste-geo', fn () => 'ok');
        $this->localDoIp(null);
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

    private function viaCloudflare(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '104.22.17.76'])
            ->withHeaders(['User-Agent' => self::NAVEGADOR, ...self::CLOUDFLARE]);
    }

    /** Manda de volta o cookie da sessão da resposta anterior. */
    private function naMesmaSessao(TestResponse $resposta): static
    {
        $nome = config('session.cookie');

        return $this->withCookie($nome, $resposta->getCookie($nome)->getValue());
    }

    public function test_grava_lugar_da_cloudflare_uma_vez_por_sessao(): void
    {
        $primeira = $this->viaCloudflare()->get('/_teste-geo')->assertOk()
            ->assertSessionHas(GeoDoVisitante::SESSAO.'.cep', '01310-100');

        $geo = VisitanteGeo::query()->sole();
        $this->assertSame('kazakora', $geo->site);
        $this->assertSame(VisitanteGeo::FONTE_CLOUDFLARE, $geo->fonte);
        $this->assertSame('189.40.1.2', $geo->ip);
        $this->assertSame('BR', $geo->pais);
        $this->assertSame('SP', $geo->uf);
        $this->assertSame('São Paulo', $geo->cidade);
        $this->assertSame('01310-100', $geo->cep);
        $this->assertSame('-23.5475000', $geo->latitude);
        $this->assertSame('-46.6361100', $geo->longitude);
        $this->assertSame('America/Sao_Paulo', $geo->fuso);
        $this->assertStringStartsWith('s-', $geo->sessao);

        // Mesma sessão, mesmo IP: não grava de novo.
        $this->naMesmaSessao($primeira)->viaCloudflare()->get('/_teste-geo')->assertOk();
        $this->assertSame(1, VisitanteGeo::query()->count());
        $this->assertTrue($geo->fresh()->updated_at->equalTo($geo->updated_at));
    }

    public function test_ip_novo_na_mesma_sessao_atualiza_a_linha(): void
    {
        $primeira = $this->viaCloudflare()->get('/_teste-geo');

        $this->naMesmaSessao($primeira)->viaCloudflare()->withHeaders(['X-Forwarded-For' => '177.67.80.1', 'CF-IPCity' => 'Campinas', 'CF-Postal-Code' => '13010'])
            ->get('/_teste-geo')->assertOk();

        $geo = VisitanteGeo::query()->sole();
        $this->assertSame('177.67.80.1', $geo->ip);
        $this->assertSame('Campinas', $geo->cidade);
        $this->assertSame('13010', $geo->cep);
    }

    public function test_cabecalho_forjado_fora_da_cloudflare_e_ignorado_e_usa_a_base_local(): void
    {
        $this->localDoIp(['pais' => 'BR', 'uf' => 'RJ', 'estado' => 'Rio de Janeiro', 'cidade' => 'Niterói', 'latitude' => -22.8833, 'longitude' => -43.1036]);

        $this->withServerVariables(['REMOTE_ADDR' => '200.10.10.10'])
            ->withHeaders(['User-Agent' => self::NAVEGADOR, ...self::CLOUDFLARE])
            ->get('/_teste-geo')->assertOk();

        $geo = VisitanteGeo::query()->sole();
        $this->assertSame(VisitanteGeo::FONTE_DBIP, $geo->fonte);
        $this->assertSame('200.10.10.10', $geo->ip);
        $this->assertSame('RJ', $geo->uf);
        $this->assertSame('Niterói', $geo->cidade);
        $this->assertNull($geo->cep);
        $this->assertSame('-22.8833000', $geo->latitude);
    }

    public function test_sem_lugar_conhecido_nao_grava_e_nao_quebra_a_pagina(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '200.10.10.10'])
            ->withHeaders(['User-Agent' => self::NAVEGADOR])
            ->get('/_teste-geo')->assertOk()
            ->assertSessionHas(GeoDoVisitante::SESSAO.'.sessao');

        $this->assertSame(0, VisitanteGeo::query()->count());
    }

    public function test_robo_nao_e_registrado(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '104.22.17.76'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)', ...self::CLOUDFLARE])
            ->get('/_teste-geo')->assertOk();

        $this->assertSame(0, VisitanteGeo::query()->count());
    }

    public function test_cep_normalizado(): void
    {
        $this->assertSame('01310-100', GeoDoVisitante::cep('01310-100'));
        $this->assertSame('01310-100', GeoDoVisitante::cep('01310100'));
        $this->assertSame('01310', GeoDoVisitante::cep('01310'));
        $this->assertNull(GeoDoVisitante::cep('1234'));
        $this->assertNull(GeoDoVisitante::cep(null));
    }

    public function test_visita_da_ferrakora_grava_lugar_do_visitante(): void
    {
        config(['services.ferrakora.origens' => 'ferrakora.com.br']);

        $this->withServerVariables(['REMOTE_ADDR' => '104.22.17.76'])
            ->withHeaders(['User-Agent' => self::NAVEGADOR, 'Origin' => 'https://ferrakora.com.br', ...self::CLOUDFLARE])
            ->post('/api/ferrakora/visita?p=/produto/x')->assertNoContent();

        $geo = VisitanteGeo::query()->sole();
        $this->assertSame('ferrakora', $geo->site);
        $this->assertSame(substr(md5('189.40.1.2'), 0, 32), $geo->sessao);
        $this->assertSame('01310-100', $geo->cep);
    }

    public function test_privacidade_limpar_apaga_lugares_com_mais_de_6_meses(): void
    {
        $antigo = VisitanteGeo::query()->create(['site' => 'kazakora', 'sessao' => 's-antigo', 'fonte' => 'cloudflare', 'ip' => '1.2.3.4']);
        $antigo->forceFill(['updated_at' => now()->subMonths(7)])->saveQuietly();
        VisitanteGeo::query()->create(['site' => 'kazakora', 'sessao' => 's-novo', 'fonte' => 'cloudflare', 'ip' => '1.2.3.5']);

        $this->artisan('privacidade:limpar')->assertSuccessful();

        $this->assertSame(['s-novo'], VisitanteGeo::query()->pluck('sessao')->all());
    }
}
