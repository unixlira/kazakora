<?php

namespace Tests\Feature\Fiscal;

use App\Services\NFe\IbgeMunicipioResolver;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * BUG REAL 2026-10-14: certificado do IBGE sem cadeia intermediária (cURL
 * erro 60) travou toda emissão de NF-e quando o cache de 30 dias venceu.
 */
class IbgeMunicipioResolverFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function ibgeForaDoAr(): \Closure
    {
        return fn ($request) => throw new ConnectException('cURL error 60: SSL certificate problem', new Request('GET', (string) $request->url()));
    }

    public function test_usa_o_ibge_quando_responde(): void
    {
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']])]);

        $this->assertSame('3550308', app(IbgeMunicipioResolver::class)->resolve('Sao Paulo', 'SP'));
    }

    public function test_ibge_fora_do_ar_cai_na_brasilapi(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response([['nome' => 'SÃO PAULO', 'codigo_ibge' => '3550308']]),
        ]);

        $this->assertSame('3550308', app(IbgeMunicipioResolver::class)->resolve('São Paulo', 'SP'));
    }

    public function test_as_duas_fora_do_ar_usa_a_ultima_lista_boa(): void
    {
        Cache::forever('ibge.municipios.SP.ultimo', [['id' => 3550308, 'nome' => 'São Paulo']]);

        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response('erro', 500),
        ]);

        $this->assertSame('3550308', app(IbgeMunicipioResolver::class)->resolve('São Paulo', 'SP'));
    }

    public function test_sem_nenhuma_fonte_continua_dando_erro_claro(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response('erro', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha ao consultar a API do IBGE para o estado SP.');

        app(IbgeMunicipioResolver::class)->resolve('São Paulo', 'SP');
    }
}
