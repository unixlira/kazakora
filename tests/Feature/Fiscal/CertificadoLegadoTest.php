<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Models\Company;
use App\Services\NFe\NFeCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pedido #3205 (2026-10-17): no painel (php-fpm) o pfx do A1 com RC2/3DES
 * não abria ("digital envelope routines::unsupported") e a NF-e não era
 * cancelada junto com o pedido. O serviço refaz o pfx em memória.
 */
class CertificadoLegadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_pfx_no_formato_antigo_sem_o_provider_legacy(): void
    {
        Storage::fake('local');
        $dir = sys_get_temp_dir().'/cert-legado-'.uniqid();
        mkdir($dir);

        $gerar = fn (array $cmd) => (new Process($cmd, $dir, null, ''))->mustRun();
        $gerar(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', 'k.pem', '-out', 'c.pem', '-days', '2', '-subj', '/CN=KAZAKORA TESTE:65604590000107']);
        $gerar(['openssl', 'pkcs12', '-export', '-legacy', '-inkey', 'k.pem', '-in', 'c.pem', '-out', 'a1.pfx', '-passout', 'pass:senha123']);

        Storage::disk('local')->put('certificados/a1.pfx', file_get_contents("{$dir}/a1.pfx"));
        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        Company::create([
            'razao_social' => 'KazaKora Comércio Ltda',
            'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'certificate_path' => 'certificados/a1.pfx',
            'certificate_password' => 'senha123',
        ]);

        $certificado = app(NFeCertificateService::class)->load();

        $this->assertStringContainsString('KAZAKORA TESTE', $certificado->getCompanyName());
    }
}
