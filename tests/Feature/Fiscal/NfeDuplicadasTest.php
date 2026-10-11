<?php

namespace Tests\Feature\Fiscal;

use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use Tests\TestCase;

/** nfe:duplicadas (2026-10-11): cancela só a nota mais nova, dentro de 24h. */
class NfeDuplicadasTest extends TestCase
{
    use RefreshDatabase;

    private const ANTIGA = '35260965604590000107550020000021701030900286';

    private const NOVA = '35261065604590000107550030000000921994348415';

    private function xml(string $chave, string $quando): string
    {
        return "<nfeProc><NFe/><protNFe><infProt><chNFe>{$chave}</chNFe><dhRecbto>{$quando}</dhRecbto><nProt>1352600000{$chave[30]}</nProt><cStat>100</cStat></infProt></protNFe></nfeProc>";
    }

    private function cenario(string $quandoNova): NFeWebserviceService
    {
        Storage::fake('local');
        Storage::disk('local')->put('invoices/1536/nfe-'.self::ANTIGA.'.xml', $this->xml(self::ANTIGA, '2026-09-08T12:16:16-03:00'));
        Storage::disk('local')->put('invoices/1536/nfe-'.self::NOVA.'.xml', $this->xml(self::NOVA, $quandoNova));

        $cert = Mockery::mock(NFeCertificateService::class);
        $cert->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $cert);

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('consultarChave')->andReturn('<retConsSitNFe><cStat>100</cStat></retConsSitNFe>');
        $this->app->instance(NFeWebserviceService::class, $sefaz);

        return $sefaz;
    }

    public function test_sem_cancelar_so_lista(): void
    {
        $sefaz = $this->cenario(now()->subHour()->toIso8601String());
        $sefaz->shouldNotReceive('cancelarComEvento');

        $this->artisan('nfe:duplicadas')->expectsOutputToContain('cancelaria')->assertSuccessful();
    }

    public function test_cancela_so_a_mais_nova(): void
    {
        $sefaz = $this->cenario(now()->subHour()->toIso8601String());
        $sefaz->shouldReceive('cancelarComEvento')->once()
            ->with(self::NOVA, Mockery::pattern('/duplicidade/'), Mockery::any(), Mockery::any())
            ->andReturn(['request' => '<envEvento/>', 'response' => '<retEnvEvento><cStat>128</cStat><retEvento><infEvento><cStat>135</cStat></infEvento></retEvento></retEnvEvento>']);

        $this->artisan('nfe:duplicadas', ['--cancelar' => true])->expectsOutputToContain('CANCELADA')->assertSuccessful();

        Storage::disk('local')->assertExists('invoices/1536/cancelamento-'.self::NOVA.'.xml');
    }

    public function test_fora_das_24h_nao_cancela(): void
    {
        $sefaz = $this->cenario(now()->subHours(30)->toIso8601String());
        $sefaz->shouldNotReceive('cancelarComEvento');

        $this->artisan('nfe:duplicadas', ['--cancelar' => true])->expectsOutputToContain('FORA das 24h')->assertSuccessful();
    }
}
