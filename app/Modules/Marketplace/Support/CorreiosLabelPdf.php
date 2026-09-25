<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Fiscal\Models\Company;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Com\Tecnick\Barcode\Barcode;
use FPDF;

/**
 * Etiqueta 10x15 da pré-postagem dos Correios em PDF, gerada no servidor
 * pra entrar na impressão automática (LabelFetchService) — a tela do menu
 * Correios desenha a mesma etiqueta no navegador (ShippingFiscalLabel.vue),
 * mas o agente de impressão só recebe arquivo.
 *
 * Mesmas três áreas da etiqueta da tela: QR + código de postagem + CEP;
 * remetente; declaração + DANFE (Code128 da chave). Acrescenta o
 * destinatário e o SKU | QTD de cada item, que é por onde o galpão confere
 * a caixa (mesma faixa das etiquetas da Shopee/ML).
 */
class CorreiosLabelPdf
{
    private const LARGURA = 100.0;

    private const ALTURA = 150.0;

    private const MARGEM = 4.0;

    public function render(CorreiosPrePostagem $pp): string
    {
        $pp->loadMissing('order.items.product', 'order.invoice');

        $pdf = new FPDF('P', 'mm', [self::LARGURA, self::ALTURA]);
        $pdf->SetMargins(self::MARGEM, self::MARGEM, self::MARGEM);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $pdf->SetLineWidth(0.6);
        $util = self::LARGURA - 2 * self::MARGEM;

        // 1. QR + código de postagem + CEP.
        $qrLado = 40.0;
        $this->qr($pdf, (string) $pp->qr_payload, self::MARGEM, self::MARGEM, $qrLado);

        $x = self::MARGEM + $qrLado + 3;
        $w = self::LARGURA - self::MARGEM - $x;
        $this->kicker($pdf, $x, 5, $w, 'Código de postagem');
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetXY($x, 9);
        $pdf->MultiCell($w, 4.6, $this->t($pp->codigo_objeto ?: $pp->correios_id), 0, 'C');
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetX($x);
        $pdf->Cell($w, 4, $this->t($pp->service_label.' · '.$pp->weight_grams.' g'), 0, 1, 'C');
        $pdf->Line($x, 28, self::LARGURA - self::MARGEM, 28);
        $this->kicker($pdf, $x, 29.5, $w, 'CEP destinatário');
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->SetXY($x, 34);
        $pdf->Cell($w, 8, $this->t($this->cep($pp->zip)), 0, 0, 'C');

        $y = self::MARGEM + $qrLado + 2;
        $pdf->Line(self::MARGEM, $y, self::LARGURA - self::MARGEM, $y);

        // 2. Destinatário.
        $this->kicker($pdf, self::MARGEM, $y + 1, $util, 'Destinatário', 'L');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetXY(self::MARGEM, $y + 5);
        $pdf->Cell($util, 4.5, $this->t($pp->customer_name), 0, 1);
        $pdf->SetFont('Helvetica', '', 8);
        foreach ($this->enderecoDestinatario($pp) as $linha) {
            $pdf->SetX(self::MARGEM);
            $pdf->Cell($util, 3.6, $this->t($linha), 0, 1);
        }

        $y = $pdf->GetY() + 1.5;
        $pdf->Line(self::MARGEM, $y, self::LARGURA - self::MARGEM, $y);

        // 3. Remetente.
        $empresa = Company::query()->first();
        $this->kicker($pdf, self::MARGEM, $y + 1, $util, 'Remetente', 'L');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetXY(self::MARGEM, $y + 5);
        $pdf->Cell($util, 4, $this->t('KazaKora'.($empresa?->cnpj ? ' · CNPJ '.$empresa->cnpj : '')), 0, 1);
        $pdf->SetFont('Helvetica', '', 7.5);
        foreach ($this->enderecoEmpresa($empresa) as $linha) {
            $pdf->SetX(self::MARGEM);
            $pdf->Cell($util, 3.4, $this->t($linha), 0, 1);
        }

        $y = $pdf->GetY() + 1.5;
        $pdf->Line(self::MARGEM, $y, self::LARGURA - self::MARGEM, $y);

        // 4. Declaração (SKU | QTD) + DANFE.
        $this->kicker($pdf, self::MARGEM, $y + 1, $util, 'Declaração do produto', 'L');
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetXY(self::MARGEM, $y + 5);
        $pdf->MultiCell($util, 3.8, $this->t($this->declaracao($pp)), 0, 'L');

        $invoice = $pp->order?->invoice;
        $chave = preg_replace('/\D/', '', (string) $invoice?->chave_acesso);
        $yDanfe = self::ALTURA - self::MARGEM - 24;
        $pdf->Line(self::MARGEM, $yDanfe - 1.5, self::LARGURA - self::MARGEM, $yDanfe - 1.5);
        $this->kicker($pdf, self::MARGEM, $yDanfe, $util / 2, 'Código DANFE', 'L');
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetXY(self::MARGEM + $util / 2, $yDanfe);
        $pdf->Cell($util / 2, 3, $this->t('DANFE / NF '.($invoice?->numero ?? '—').($invoice?->serie ? ' série '.$invoice->serie : '')), 0, 0, 'R');

        if (strlen($chave) === 44) {
            $this->code128($pdf, $chave, self::MARGEM, $yDanfe + 4, $util, 12);
            $pdf->SetFont('Courier', 'B', 7.5);
            $pdf->SetXY(self::MARGEM, $yDanfe + 17);
            $pdf->Cell($util, 3.5, trim(chunk_split($chave, 4, ' ')), 0, 0, 'C');
        }

        $pdf->SetFont('Helvetica', '', 6.5);
        $pdf->SetXY(self::MARGEM, self::ALTURA - self::MARGEM - 2.5);
        $pdf->Cell($util, 3, $this->t(trim(($pp->origin ? ucfirst(str_replace('_', ' ', $pp->origin)).' ' : '').($pp->external_order_id ?? '').' · Pedido #'.$pp->order_id)), 0, 0, 'C');

        return $pdf->Output('S');
    }

    private function qr(FPDF $pdf, string $conteudo, float $x, float $y, float $lado): void
    {
        $pdf->Rect($x, $y, $lado, $lado);

        if ($conteudo === '') {
            return;
        }

        $codigo = (new Barcode)->getBarcodeObj('QRCODE,M', $conteudo, -1, -1);
        $modulos = $codigo->getArray()['ncols'];
        // 2mm de respiro dentro da moldura (zona quieta do QR).
        $modulo = ($lado - 4) / $modulos;

        foreach ($codigo->getBarsArray('XYWH') as [$bx, $by, $bw, $bh]) {
            $pdf->Rect($x + 2 + $bx * $modulo, $y + 2 + $by * $modulo, $bw * $modulo, $bh * $modulo, 'F');
        }
    }

    private function code128(FPDF $pdf, string $conteudo, float $x, float $y, float $largura, float $altura): void
    {
        $codigo = (new Barcode)->getBarcodeObj('C128C', $conteudo, -1, -1);
        $modulo = $largura / $codigo->getArray()['ncols'];

        foreach ($codigo->getBarsArray('XYWH') as [$bx, , $bw]) {
            $pdf->Rect($x + $bx * $modulo, $y, $bw * $modulo, $altura, 'F');
        }
    }

    private function kicker(FPDF $pdf, float $x, float $y, float $w, string $texto, string $alinhamento = 'C'): void
    {
        $pdf->SetFont('Helvetica', 'B', 6.5);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 3.5, $this->t(mb_strtoupper($texto)), 0, 0, $alinhamento);
    }

    private function declaracao(CorreiosPrePostagem $pp): string
    {
        $itens = $pp->order?->items ?? collect();

        if ($itens->isEmpty()) {
            return collect($pp->content_items ?? [])->map(fn ($i) => ($i['conteudo'] ?? '').' | QTD: '.($i['quantidade'] ?? 1))->implode(', ');
        }

        return $itens
            ->groupBy(fn ($item) => $item->product?->sku ?: $item->product_name)
            ->map(fn ($grupo, $sku) => $sku.' | QTD: '.str_pad((string) $grupo->sum('quantity'), 2, '0', STR_PAD_LEFT))
            ->implode(', ');
    }

    /**
     * @return array<int, string>
     */
    private function enderecoDestinatario(CorreiosPrePostagem $pp): array
    {
        return array_values(array_filter([
            trim($pp->street.', '.$pp->number.($pp->complement ? ' - '.$pp->complement : '')),
            trim(($pp->neighborhood ? $pp->neighborhood.' — ' : '').$pp->city.'/'.$pp->state),
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function enderecoEmpresa(?Company $empresa): array
    {
        if (! $empresa) {
            return [];
        }

        return array_values(array_filter([
            trim($empresa->street.', '.$empresa->number.($empresa->complement ? ' - '.$empresa->complement : '')),
            trim(($empresa->neighborhood ? $empresa->neighborhood.' — ' : '').$empresa->city.'/'.$empresa->state.' · CEP '.$this->cep($empresa->zip)),
        ]));
    }

    private function cep(?string $cep): string
    {
        $digitos = preg_replace('/\D/', '', (string) $cep);

        return strlen($digitos) === 8 ? substr($digitos, 0, 5).'-'.substr($digitos, 5) : (string) $cep;
    }

    /** FPDF só fala latin1 nas fontes padrão. */
    private function t(?string $texto): string
    {
        return (string) iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $texto);
    }
}
