<?php

namespace App\Services\NFe;

use App\Modules\Fiscal\Models\Company;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Certificate;

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

        return Certificate::readPfx($content, $company->certificate_password);
    }
}
