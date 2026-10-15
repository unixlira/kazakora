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

    /** Resolvedor sem o arquivo local, para exercitar as fontes de rede. */
    private function semListaLocal(): IbgeMunicipioResolver
    {
        return new IbgeMunicipioResolver(storage_path('framework/testing/nao-existe-ibge.json'));
    }

    /**
     * BUG REAL 2026-10-15 (pedidos #3164 e #3165): IBGE com certificado
     * quebrado e BrasilAPI em timeout ao mesmo tempo. A lista do repositório
     * resolve sem nenhuma chamada de rede.
     */
    public function test_lista_local_resolve_sem_rede(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response('erro', 504),
        ]);

        $resolver = app(IbgeMunicipioResolver::class);

        $this->assertSame('3550308', $resolver->resolve('São Paulo', 'SP'));
        $this->assertSame('2905701', $resolver->resolve('Camacari', 'ba'));
        $this->assertSame('4106902', $resolver->resolve('Curitiba', 'PR'));
        Http::assertNothingSent();
    }

    public function test_lista_local_tem_todas_as_ufs(): void
    {
        $lista = json_decode(file_get_contents(resource_path('data/ibge-municipios.json')), true);

        $this->assertCount(27, $lista);
        $this->assertSame(5571, array_sum(array_map('count', $lista)));
    }

    public function test_cidade_que_nao_existe_continua_dando_erro_claro(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível encontrar o código IBGE do município "Canaã/AL".');

        app(IbgeMunicipioResolver::class)->resolve('Canaã', 'AL');
    }

    public function test_usa_o_ibge_quando_responde(): void
    {
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']])]);

        $this->assertSame('3550308', $this->semListaLocal()->resolve('Sao Paulo', 'SP'));
    }

    public function test_ibge_fora_do_ar_cai_na_brasilapi(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response([['nome' => 'SÃO PAULO', 'codigo_ibge' => '3550308']]),
        ]);

        $this->assertSame('3550308', $this->semListaLocal()->resolve('São Paulo', 'SP'));
    }

    public function test_as_duas_fora_do_ar_usa_a_ultima_lista_boa(): void
    {
        Cache::forever('ibge.municipios.SP.ultimo', [['id' => 3550308, 'nome' => 'São Paulo']]);

        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response('erro', 500),
        ]);

        $this->assertSame('3550308', $this->semListaLocal()->resolve('São Paulo', 'SP'));
    }

    public function test_sem_nenhuma_fonte_continua_dando_erro_claro(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => $this->ibgeForaDoAr(),
            'brasilapi.com.br/*' => Http::response('erro', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Falha ao consultar a API do IBGE para o estado SP.');

        $this->semListaLocal()->resolve('São Paulo', 'SP');
    }
}
