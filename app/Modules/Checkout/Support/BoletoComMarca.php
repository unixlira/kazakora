<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Boleto com o logo da KazaKora (pedido do Lira 2026-10-24). O PDF é do
 * Mercado Pago (Bradesco 237) e a API não aceita logo; então a loja baixa o
 * PDF original e só carimba o logo oficial (v5: dois K laranja, resto
 * branco, o mesmo do rodapé) no espaço vazio do topo, sobre o preto do
 * rodapé do site. Linha digitável, código de barras e Bradesco não mudam.
 *
 * Só pedido do kazakora.com (site_origem nulo): FerraKora e Beauty Kora
 * seguem com o link do Mercado Pago. Se o PDF não vier, o cliente vai para
 * o boleto original, que é o que vale para pagar.
 */
class BoletoComMarca
{
    private const LOGO = 'images/marca/logo-rodape.png';

    /** Faixa preta no topo da página A4 (595 x 842 pt), dentro das margens do boleto. */
    private const FAIXA = ['x' => 16.0, 'y' => 12.0, 'largura' => 563.0, 'altura' => 50.0];

    private const ALTURA_LOGO = 28.0;

    /** Link que o cliente recebe: o boleto da loja (assinado) ou, fora do kazakora.com, o do Mercado Pago. */
    public static function url(Payment $payment): ?string
    {
        if (! $payment->boleto_url) {
            return null;
        }

        if (! self::temMarca($payment)) {
            return $payment->boleto_url;
        }

        return URL::signedRoute('boleto.pdf', ['payment' => $payment->id]);
    }

    public static function temMarca(Payment $payment): bool
    {
        return $payment->method_type === Payment::METHOD_BOLETO
            && $payment->site_origem === null
            && (bool) $payment->boleto_url;
    }

    /** PDF com o logo, ou null se o do Mercado Pago não puder ser lido. Guarda o arquivo pronto. */
    public function pdf(Payment $payment): ?string
    {
        $arquivo = "boletos/{$payment->id}.pdf";
        $disco = Storage::disk('local');

        if ($disco->exists($arquivo)) {
            return $disco->get($arquivo);
        }

        $original = rescue(fn () => Http::timeout(20)->retry(2, 500)->get($payment->boleto_url), null, false);

        if (! $original?->successful() || ! str_starts_with($original->body(), '%PDF')) {
            return null;
        }

        $pdf = rescue(fn () => $this->carimbar($original->body()), null);

        if ($pdf !== null) {
            $disco->put($arquivo, $pdf);
        }

        return $pdf;
    }

    public function carimbar(string $original): string
    {
        $fpdi = new Fpdi('P', 'pt');
        $fpdi->SetAutoPageBreak(false);
        $paginas = $fpdi->setSourceFile(StreamReader::createByString($original));

        for ($n = 1; $n <= $paginas; $n++) {
            $modelo = $fpdi->importPage($n);
            $tamanho = $fpdi->getTemplateSize($modelo);
            $fpdi->AddPage($tamanho['orientation'], [$tamanho['width'], $tamanho['height']]);
            $fpdi->useTemplate($modelo);

            if ($n === 1) {
                $this->logo($fpdi);
            }
        }

        $fpdi->SetTitle('Boleto KazaKora', true);

        return $fpdi->Output('S');
    }

    private function logo(Fpdi $fpdi): void
    {
        $faixa = self::FAIXA;
        [$larguraPx, $alturaPx] = getimagesize(public_path(self::LOGO));
        $largura = self::ALTURA_LOGO * $larguraPx / $alturaPx;

        $fpdi->SetFillColor(11, 11, 11);
        $fpdi->Rect($faixa['x'], $faixa['y'], $faixa['largura'], $faixa['altura'], 'F');
        $fpdi->Image(
            public_path(self::LOGO),
            $faixa['x'] + ($faixa['largura'] - $largura) / 2,
            $faixa['y'] + ($faixa['altura'] - self::ALTURA_LOGO) / 2,
            $largura,
            self::ALTURA_LOGO,
        );
    }
}
