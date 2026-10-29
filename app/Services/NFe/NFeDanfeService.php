<?php

namespace App\Services\NFe;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Support\Facades\Storage;

/**
 * Etapa 4: gera o DANFE (PDF) a partir do XML da NF-e (autorizada ou não —
 * a biblioteca renderiza de qualquer jeito; sem o protocolo de autorização
 * no XML, o PDF sai sem esse número, o que é esperado antes da autorização
 * real da SEFAZ).
 */
class NFeDanfeService
{
    /** Logo colorido (laranja e preto) usado quando a empresa não tem logo cadastrado. */
    private const LOGO_PADRAO = 'images/marca/logo-nav.png';

    /**
     * Pedido do site FerraKora sai com o logo dela (2026-10-28, pedido do
     * Lira: a nota da FerraKora chegava com a KazaKora). Logo claro sobre
     * fundo preto, como no topo dos e-mails dela. O emitente é o mesmo CNPJ.
     */
    private const LOGO_FERRAKORA = 'nfe/ferrakora.png';

    public function generate(string $xml, ?Order $order = null): string
    {
        $danfe = new KazaKoraDanfe($xml);

        $logo = $order?->site_origem === Order::SITE_FERRAKORA
            ? $this->logoFerraKora()
            : $this->logo();

        if ($logo !== null) {
            $danfe->setMarca($logo);
        }

        return $danfe->render();
    }

    private function logo(): ?string
    {
        $company = Company::query()->first();

        if ($company?->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            return Storage::disk('public')->get($company->logo_path);
        }

        $padrao = public_path(self::LOGO_PADRAO);

        return is_file($padrao) ? file_get_contents($padrao) : null;
    }

    private function logoFerraKora(): ?string
    {
        $arquivo = resource_path(self::LOGO_FERRAKORA);

        return is_file($arquivo) ? file_get_contents($arquivo) : $this->logo();
    }
}
