<?php

namespace App\Services\NFe;

use App\Modules\Fiscal\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class NFeCertificateService
{
    public function isConfigured(): bool
    {
        $company = Company::query()->first();

        return $company
            && filled($company->certificate_path)
            && filled($company->certificate_password)
            && Storage::disk('local')->exists($company->certificate_path);
    }

    public function load(): Certificate
    {
        if (! $this->isConfigured()) {
            throw new NFeCertificateNotConfiguredException();
        }

        $company = Company::query()->first();

        $content = Storage::disk('local')->get($company->certificate_path);

        try {
            return Certificate::readPfx($content, $company->certificate_password);
        } catch (Throwable $exception) {
            // BUG REAL 2026-10-17 (pedido #3205): o pfx do A1 usa RC2/3DES,
            // que o OpenSSL 3 só lê com o provider "legacy". Fila e artisan
            // sobem com OPENSSL_CONF e leem; o php-fpm aplica o env[] do pool
            // depois que o OpenSSL já iniciou, então no painel (Cancelar
            // pedido, Cancelar nota) a leitura falhava com "unsupported".
            // Aqui o pfx é refeito em memória com a cifra padrão, sem tocar
            // no arquivo guardado.
            if (! str_contains($exception->getMessage(), 'unsupported')) {
                throw $exception;
            }

            Log::info('nfe.certificate.legacy_reexport');

            return Certificate::readPfx($this->reexportarSemLegacy($content, $company->certificate_password), $company->certificate_password);
        }
    }

    /** pfx legado → PEM (openssl -legacy, por stdin/stdout) → pfx novo com a mesma senha, em memória. */
    private function reexportarSemLegacy(string $pfx, string $senha): string
    {
        $pem = new Process(['openssl', 'pkcs12', '-legacy', '-nodes', '-passin', 'env:NFE_PFX_SENHA'], null, ['NFE_PFX_SENHA' => $senha], $pfx, 30);
        $pem->run();
        $saida = $pem->getOutput();

        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $saida, $certificados);
        $chave = openssl_pkey_get_private($saida);

        if (! $pem->isSuccessful() || ! $chave || $certificados[0] === []) {
            throw new RuntimeException('Impossivel ler o certificado nem convertendo do formato antigo: '.trim($pem->getErrorOutput()));
        }

        // O certificado da chave vai como principal; o resto é a cadeia.
        $principal = collect($certificados[0])->first(fn (string $cert) => openssl_x509_check_private_key($cert, $chave)) ?? $certificados[0][0];
        $cadeia = array_values(array_diff($certificados[0], [$principal]));

        if (! openssl_pkcs12_export($principal, $novo, $chave, $senha, $cadeia === [] ? [] : ['extracerts' => $cadeia])) {
            throw new RuntimeException('Impossivel converter o certificado: '.openssl_error_string());
        }

        return $novo;
    }
}
