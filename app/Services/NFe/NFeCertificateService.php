<?php

namespace App\Services\NFe;

use Illuminate\Support\Facades\File;
use NFePHP\Common\Certificate;

class NFeCertificateService
{
    public function isConfigured(): bool
    {
        $path = config('nfe.certificado_path');

        return filled(config('nfe.certificado_senha')) && $path && File::exists($path);
    }

    public function load(): Certificate
    {
        if (! $this->isConfigured()) {
            throw new NFeCertificateNotConfiguredException();
        }

        $content = File::get(config('nfe.certificado_path'));

        return Certificate::readPfx($content, config('nfe.certificado_senha'));
    }
}
