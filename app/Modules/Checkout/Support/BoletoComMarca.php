<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Boleto com o topo da KazaKora (pedido do Lira 2026-10-24). O PDF é do
 * Mercado Pago (Bradesco 237) e a API não aceita logo; então a loja baixa o
 * PDF original e só carimba o topo no espaço vazio acima da linha que separa
 * o topo do corpo. Linha digitável, código de barras e Bradesco não mudam.
 *
 * Topo (3º pedido do Lira, 2026-10-24): fundo branco, o logo encostado na
 * margem esquerda e, à direita, copyright, CNPJ e endereço completo da loja.
 * Desde 2026-10-25 o logo é o oficial "fiel" (KazaKora em preto com os dois K
 * laranja), no lugar do símbolo KK com o nome escrito em Helvetica.
 * Para caber, a página do Mercado Pago desce DESCIDA pt sem reduzir (o código
 * de barras fica no tamanho que o banco lê); o rodapé dela tem folga.
 *
 * Vale para as três lojas (kazakora.com, FerraKora e Beauty Kora): a conta do
 * Mercado Pago e o CNPJ são os mesmos. Se o PDF não vier, o cliente vai para
 * o boleto original, que é o que vale para pagar.
 *
 * FerraKora com a marca dela (pedido do Lira 2026-10-29: "cada empresa com a
 * sua identidade"): pedido do site FerraKora sai com o logo dela (claro no
 * fundo preto, o mesmo da nota fiscal) e "FerraKora | Grupo AlphaKora", como
 * no rodapé do checkout dela. CNPJ e endereço são os mesmos; o beneficiário
 * dentro do boleto é o da conta do Mercado Pago e não muda.
 */
class BoletoComMarca
{
    /** Logo oficial fiel (logo-kazakora-fiel-preto-laranja-master.png), fundo transparente. */
    private const LOGO = 'images/marca/logo-kazakora-fiel.png';

    /** Logo da FerraKora no fundo preto, o mesmo da DANFE (NFeDanfeService). */
    private const LOGO_FERRAKORA = 'nfe/ferrakora.png';

    /** Topo da página A4 (595 x 842 pt), dentro das margens do boleto. */
    private const TOPO = ['x' => 16.0, 'y' => 8.0, 'largura' => 563.0, 'altura' => 79.0];

    private const ALTURA_LOGO = 40.0;

    /** Quanto o PDF do Mercado Pago desce (pt): a linha do topo dele sai de 69 para 93, abaixo da faixa. */
    public const DESCIDA = 24.0;

    /** Versão do desenho: muda a pasta dos PDFs guardados, para não servir o carimbo antigo. */
    private const PASTA = 'boletos/v4';

    /** FerraKora em pasta própria: os guardados em v4 saíram com o topo da KazaKora. */
    private const PASTA_FERRAKORA = 'boletos/v5-ferrakora';

    /** Link que o cliente recebe: o boleto com o topo da loja (assinado). */
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
            && (bool) $payment->boleto_url;
    }

    /** PDF com o logo, ou null se o do Mercado Pago não puder ser lido. Guarda o arquivo pronto. */
    public function pdf(Payment $payment): ?string
    {
        $ferrakora = self::ehFerraKora($payment);
        $arquivo = ($ferrakora ? self::PASTA_FERRAKORA : self::PASTA)."/{$payment->id}.pdf";
        $disco = Storage::disk('local');

        if ($disco->exists($arquivo)) {
            return $disco->get($arquivo);
        }

        $original = rescue(fn () => Http::timeout(20)->retry(2, 500)->get($payment->boleto_url), null, false);

        if (! $original?->successful() || ! str_starts_with($original->body(), '%PDF')) {
            return null;
        }

        $pdf = rescue(fn () => $this->carimbar($original->body(), $ferrakora), null);

        if ($pdf !== null) {
            $disco->put($arquivo, $pdf);
        }

        return $pdf;
    }

    public static function ehFerraKora(Payment $payment): bool
    {
        return $payment->order?->site_origem === Order::SITE_FERRAKORA;
    }

    public function carimbar(string $original, bool $ferrakora = false): string
    {
        $fpdi = new Fpdi('P', 'pt');
        $fpdi->SetAutoPageBreak(false);
        $paginas = $fpdi->setSourceFile(StreamReader::createByString($original));

        for ($n = 1; $n <= $paginas; $n++) {
            $modelo = $fpdi->importPage($n);
            $tamanho = $fpdi->getTemplateSize($modelo);
            $fpdi->AddPage($tamanho['orientation'], [$tamanho['width'], $tamanho['height']]);
            if ($n === 1) {
                $fpdi->useTemplate($modelo, 0, self::DESCIDA);
                $this->cabecalho($fpdi, $ferrakora);
            } else {
                $fpdi->useTemplate($modelo);
            }
        }

        $fpdi->SetTitle($ferrakora ? 'Boleto FerraKora' : 'Boleto KazaKora', true);

        return $fpdi->Output('S');
    }

    private function cabecalho(Fpdi $fpdi, bool $ferrakora): void
    {
        $topo = self::TOPO;
        $logo = $ferrakora ? resource_path(self::LOGO_FERRAKORA) : public_path(self::LOGO);
        [$larguraPx, $alturaPx] = getimagesize($logo);
        $larguraLogo = self::ALTURA_LOGO * $larguraPx / $alturaPx;
        $meio = $topo['y'] + $topo['altura'] / 2;

        $fpdi->SetFillColor(255, 255, 255);
        $fpdi->Rect($topo['x'], $topo['y'], $topo['largura'], $topo['altura'], 'F');
        $fpdi->Image($logo, $topo['x'], $meio - self::ALTURA_LOGO / 2, $larguraLogo, self::ALTURA_LOGO);

        $linhas = self::dadosDaEmpresa($ferrakora);
        $fpdi->SetXY($topo['x'], $meio - count($linhas) * 11 / 2);
        foreach ($linhas as $i => $linha) {
            $fpdi->SetFont('Helvetica', $i === 0 ? 'B' : '', 8.5);
            $fpdi->SetTextColor(60, 60, 60);
            $fpdi->Cell($topo['largura'], 11, (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $linha), 0, 2, 'R');
        }
    }

    /**
     * As linhas do lado direito do topo, as mesmas do rodapé do checkout
     * (CheckoutLayout.vue e resources/js/Shared/company.js; na FerraKora,
     * CheckoutPage.jsx do site dela).
     *
     * @return list<string>
     */
    public static function dadosDaEmpresa(bool $ferrakora = false): array
    {
        return [
            '© '.now('America/Sao_Paulo')->format('Y').($ferrakora ? ' FerraKora' : ' KazaKora').' | Grupo AlphaKora',
            'CNPJ: '.config('loja.empresa.cnpj'),
            (string) config('loja.empresa.endereco_completo'),
        ];
    }
}
