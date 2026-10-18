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
    /**
     * Logo usado quando a empresa não tem um próprio cadastrado: o mesmo
     * do topo do site (laranja e preto; o do rodapé tem letras brancas).
     */
    public const LOGO_PADRAO = 'images/marca/logo-nav.png';

    public function generate(string $xml): string
    {
        $danfe = new Danfe($xml);

        $logo = $this->logoDataUri();

        if ($logo !== null) {
            // 'L': logo à esquerda e razão social/endereço ao lado. O quadro
            // "Identificação do emitente" continua com os dados obrigatórios;
            // o modo 'F' (só logo) esconderia esses dados e um logo largo
            // vaza por cima do quadro "DANFE".
            $danfe->logoParameters($logo, 'L');
        }

        return $danfe->render();
    }

    /**
     * A biblioteca só aceita caminho de arquivo ou 'data://text/plain;base64,...';
     * base64 puro (como era passado antes) é descartado sem erro e o DANFE
     * saía sem logo.
     */
    private function logoDataUri(): ?string
    {
        $conteudo = $this->logoBruto();

        if ($conteudo === null) {
            return null;
        }

        return 'data://text/plain;base64,'.base64_encode($this->fundoBranco($conteudo));
    }

    private function logoBruto(): ?string
    {
        $company = Company::query()->first();

        if ($company?->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            return Storage::disk('public')->get($company->logo_path);
        }

        $padrao = public_path(self::LOGO_PADRAO);

        return is_file($padrao) ? file_get_contents($padrao) : null;
    }

    /**
     * A biblioteca converte PNG em JPEG, e o fundo transparente vira preto
     * (o "Kora" preto do logo some). Achata o PNG sobre fundo branco antes.
     */
    private function fundoBranco(string $conteudo): string
    {
        $info = @getimagesizefromstring($conteudo);

        if (($info[2] ?? null) !== IMAGETYPE_PNG || ! function_exists('imagecreatefromstring')) {
            return $conteudo;
        }

        $origem = @imagecreatefromstring($conteudo);

        if (! $origem) {
            return $conteudo;
        }

        $largura = imagesx($origem);
        $altura = imagesy($origem);
        $destino = imagecreatetruecolor($largura, $altura);
        imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
        imagecopy($destino, $origem, 0, 0, 0, 0, $largura, $altura);

        ob_start();
        imagepng($destino);
        $png = (string) ob_get_clean();

        imagedestroy($origem);
        imagedestroy($destino);

        return $png;
    }
}
