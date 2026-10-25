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
 * Abaixo do logo (2º pedido do Lira em 2026-10-24) vão os dados da empresa no
 * padrão do rodapé do checkout (site, copyright, CNPJ e endereço completo),
 * tudo acima da linha que separa o topo do corpo. Para caber, a página do
 * Mercado Pago desce DESCIDA pt sem reduzir (o código de barras fica no
 * tamanho que o banco lê); o rodapé dela tem folga para isso.
 *
 * Só pedido do kazakora.com (site_origem nulo): FerraKora e Beauty Kora
 * seguem com o link do Mercado Pago. Se o PDF não vier, o cliente vai para
 * o boleto original, que é o que vale para pagar.
 */
class BoletoComMarca
{
    private const LOGO = 'images/marca/logo-rodape.png';

    /** Faixa preta no topo da página A4 (595 x 842 pt), dentro das margens do boleto. */
    private const FAIXA = ['x' => 16.0, 'y' => 8.0, 'largura' => 563.0, 'altura' => 79.0];

    private const ALTURA_LOGO = 30.0;

    /** Quanto o PDF do Mercado Pago desce (pt): a linha do topo dele sai de 69 para 93, abaixo da faixa. */
    public const DESCIDA = 24.0;

    /** Versão do desenho: muda a pasta dos PDFs guardados, para não servir o carimbo antigo. */
    private const PASTA = 'boletos/v2';

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
        $arquivo = self::PASTA."/{$payment->id}.pdf";
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
            if ($n === 1) {
                $fpdi->useTemplate($modelo, 0, self::DESCIDA);
                $this->cabecalho($fpdi);
            } else {
                $fpdi->useTemplate($modelo);
            }
        }

        $fpdi->SetTitle('Boleto KazaKora', true);

        return $fpdi->Output('S');
    }

    private function cabecalho(Fpdi $fpdi): void
    {
        $faixa = self::FAIXA;
        [$larguraPx, $alturaPx] = getimagesize(public_path(self::LOGO));
        $largura = self::ALTURA_LOGO * $larguraPx / $alturaPx;
        $topoLogo = $faixa['y'] + 7;

        $fpdi->SetFillColor(11, 11, 11);
        $fpdi->Rect($faixa['x'], $faixa['y'], $faixa['largura'], $faixa['altura'], 'F');
        $fpdi->Image(
            public_path(self::LOGO),
            $faixa['x'] + ($faixa['largura'] - $largura) / 2,
            $topoLogo,
            $largura,
            self::ALTURA_LOGO,
        );

        $fpdi->SetXY($faixa['x'], $topoLogo + self::ALTURA_LOGO + 3);
        foreach (self::dadosDaEmpresa() as $i => $linha) {
            $fpdi->SetFont('Helvetica', $i === 0 ? 'B' : '', $i === 0 ? 8 : 7.5);
            $i === 0 ? $fpdi->SetTextColor(255, 255, 255) : $fpdi->SetTextColor(200, 200, 200);
            $fpdi->Cell($faixa['largura'], 8.5, (string) iconv('UTF-8', 'windows-1252//TRANSLIT', $linha), 0, 2, 'C');
        }
    }

    /**
     * As linhas abaixo do logo, as mesmas do rodapé do checkout
     * (CheckoutLayout.vue e resources/js/Shared/company.js).
     *
     * @return list<string>
     */
    public static function dadosDaEmpresa(): array
    {
        return [
            'KazaKora: kazakora.com',
            '© '.now('America/Sao_Paulo')->format('Y').' KazaKora | Grupo AlphaKora',
            'CNPJ: '.config('loja.empresa.cnpj'),
            (string) config('loja.empresa.endereco_completo'),
        ];
    }
}
