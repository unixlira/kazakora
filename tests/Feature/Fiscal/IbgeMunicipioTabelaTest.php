<?php

namespace Tests\Feature\Fiscal;

use App\Models\IbgeEstado;
use App\Models\IbgeMunicipio;
use App\Services\NFe\IbgeMunicipioResolver;
use Database\Seeders\IbgeLocalidadesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * BUG REAL 2026-10-15: o deploy limpou o cache com a "última lista boa" e,
 * com IBGE e BrasilAPI fora, todas as notas pararam. A tabela
 * ibge_municipios (populada pela migration) resolve sem rede.
 */
class IbgeMunicipioTabelaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(IbgeLocalidadesSeeder::class);
    }

    private function redeFora(): void
    {
        Http::fake(fn () => throw new RuntimeException('rede não deveria ser chamada'));
    }

    public function test_migration_popula_a_tabela_vazia_com_o_arquivo(): void
    {
        IbgeMunicipio::query()->delete();
        IbgeEstado::query()->delete();

        $migration = require database_path('migrations/2026_10_15_180000_create_ibge_estados_e_municipios_tables.php');
        $migration->popularSeVazia();

        $this->assertSame(27, IbgeEstado::query()->count());
        $this->assertGreaterThanOrEqual(5570, IbgeMunicipio::query()->count());
        $this->assertSame('SP', IbgeMunicipio::query()->find(3550308)->uf);
    }

    public function test_resolve_pela_tabela_sem_chamar_http(): void
    {
        $this->redeFora();
        $resolver = app(IbgeMunicipioResolver::class);

        $this->assertSame('3550308', $resolver->resolve('São Paulo', 'SP'));
        $this->assertSame('3550308', $resolver->resolve('  SAO PAULO ', 'sp'));
        $this->assertSame('5300108', $resolver->resolve('Brasilia', 'DF'));
        $this->assertSame('1100015', $resolver->resolve("Alta Floresta D'Oeste", 'RO'));
        Http::assertNothingSent();
    }

    public function test_cidade_inexistente_na_tabela_da_erro_sem_ir_na_api(): void
    {
        $this->redeFora();

        try {
            app(IbgeMunicipioResolver::class)->resolve('Canaã', 'AL');
            $this->fail('Deveria ter estourado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Canaã/AL', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_uf_sem_dados_na_tabela_cai_na_api(): void
    {
        IbgeMunicipio::query()->where('uf', 'SP')->delete();
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']])]);

        $this->assertSame('3550308', app(IbgeMunicipioResolver::class)->resolve('Sao Paulo', 'SP'));
        Http::assertSentCount(1);
    }

    public function test_sincronizar_com_api_fora_nao_apaga_nada(): void
    {
        $antes = IbgeMunicipio::query()->count();
        Http::fake(['*' => Http::response('erro', 504)]);

        $this->artisan('ibge:sincronizar')->assertExitCode(1);

        $this->assertSame($antes, IbgeMunicipio::query()->count());
        $this->assertSame(27, IbgeEstado::query()->count());
    }

    public function test_sincronizar_com_tabela_vazia_e_api_fora_carrega_o_arquivo(): void
    {
        IbgeMunicipio::query()->delete();
        Http::fake(['*' => Http::response('erro', 504)]);

        $this->artisan('ibge:sincronizar')->assertExitCode(0);

        $this->assertGreaterThanOrEqual(5570, IbgeMunicipio::query()->count());
    }

    public function test_sincronizar_pelo_ibge_atualiza_nome_e_acrescenta_novo(): void
    {
        $estados = IbgeEstado::query()->get(['id', 'sigla', 'nome'])->map(fn ($e) => ['id' => $e->id, 'sigla' => $e->sigla, 'nome' => $e->nome])->all();
        $municipios = IbgeMunicipio::query()->get(['id', 'nome', 'estado_id'])
            ->map(fn ($m) => ['municipio-id' => $m->id, 'municipio-nome' => $m->id === 3550308 ? 'São Paulo (novo nome)' : $m->nome, 'UF-id' => $m->estado_id])
            ->push(['municipio-id' => 3599999, 'municipio-nome' => 'Cidade Nova', 'UF-id' => 35])
            ->push(['municipio-id' => 1234, 'municipio-nome' => 'Código inválido', 'UF-id' => 35])
            ->all();

        Http::fake([
            'servicodados.ibge.gov.br/api/v1/localidades/estados' => Http::response($estados),
            'servicodados.ibge.gov.br/api/v1/localidades/municipios*' => Http::response($municipios),
        ]);

        $this->artisan('ibge:sincronizar')->assertExitCode(0);

        $this->assertSame('São Paulo (novo nome)', IbgeMunicipio::query()->find(3550308)->nome);
        $this->assertSame('cidade nova', IbgeMunicipio::query()->find(3599999)->nome_normalizado);
        $this->assertNull(IbgeMunicipio::query()->find(1234));
    }
}
