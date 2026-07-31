<?php

namespace App\Services\NFe;

use App\Modules\Fiscal\Models\Company;
use Illuminate\Support\Facades\Storage;
use NFePHP\DA\NFe\Danfe;

/**
 * Etapa 4: gera o DANFE (PDF) a partir do XML da NF-e (autorizada ou não —
 * a biblioteca renderiza de qualquer jeito; sem o protocolo de autorização
 * no XML, o PDF sai sem esse número, o que é esperado antes da autorização
 * real da SEFAZ).
 */
class NFeDanfeService
{
    public function generate(string $xml): string
    {
        $danfe = new Danfe($xml);

        $logo = $this->logoBase64();

        return $danfe->render($logo ?? '');
    }

    private function logoBase64(): ?string
    {
        $company = Company::query()->first();

        if (! $company?->logo_path || ! Storage::disk('public')->exists($company->logo_path)) {
            return null;
        }

        return base64_encode(Storage::disk('public')->get($company->logo_path));
    }
}
