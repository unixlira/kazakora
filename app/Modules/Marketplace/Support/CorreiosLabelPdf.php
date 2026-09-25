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

        // 4. Declaração do produto + DANFE — tudo na MESMA etiqueta (pedido
        // do usuário 2026-09-25: a folha separada de declaração saía como
        // uma 2ª etiqueta, "o certo é gerar só 1").
        $yDanfe = self::ALTURA - self::MARGEM - 24;
        $this->kicker($pdf, self::MARGEM, $y + 1, $util, 'Declaração do produto', 'L');
        $this->declaracaoNaEtiqueta($pdf, $pp, $y + 5, $yDanfe - 2.5);

        $invoice = $pp->order?->invoice;
        $chave = preg_replace('/\D/', '', (string) $invoice?->chave_acesso);
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

    /**
     * Nome + COR e quantidade de cada produto, SKU embaixo em fonte menor,
     * no espaço entre o remetente e a DANFE. Até ~3 produtos cabem no
     * tamanho cheio; com mais, a linha encolhe; se nem assim couber, cai
     * pra lista corrida "SKU | QTD" — nunca vira uma 2ª etiqueta.
     */
    private function declaracaoNaEtiqueta(FPDF $pdf, CorreiosPrePostagem $pp, float $topo, float $base): void
    {
        $linhas = $this->linhasDaDeclaracao($pp);
        $util = self::LARGURA - 2 * self::MARGEM;
        $disponivel = $base - $topo;

        $tamanhos = [
            ['altura' => 9.0, 'nome' => 8.5, 'sku' => 6.5, 'qtd' => 14],
            ['altura' => 6.5, 'nome' => 7.0, 'sku' => 5.5, 'qtd' => 10],
        ];
        $tamanho = collect($tamanhos)->first(fn ($t) => count($linhas) * $t['altura'] <= $disponivel);

        if (! $tamanho || $linhas === []) {
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->SetXY(self::MARGEM, $topo);
            $pdf->MultiCell($util, 3.2, $this->t($this->declaracao($pp)), 0, 'L');

            return;
        }

        $larguraQtd = 14.0;
        $larguraNome = $util - $larguraQtd - 1.5;
        $y = $topo;

        foreach ($linhas as $i => $linha) {
            // A cor nunca é cortada (é ela que separa as variações de mesmo
            // nome); quem encolhe é o nome.
            $cor = $linha['cor'] ? $this->t(' — '.mb_strtoupper($linha['cor'])) : '';

            $pdf->SetFont('Helvetica', 'B', $tamanho['nome']);
            $pdf->SetXY(self::MARGEM, $y);
            $pdf->Cell($larguraNome, $tamanho['altura'] * 0.5, $this->caber($pdf, $linha['nome'], $larguraNome - $pdf->GetStringWidth($cor)).$cor, 0, 0, 'L');

            $pdf->SetFont('Helvetica', '', $tamanho['sku']);
            $pdf->SetXY(self::MARGEM, $y + $tamanho['altura'] * 0.5);
            $pdf->Cell($larguraNome, $tamanho['altura'] * 0.4, $this->t('SKU '.($linha['sku'] ?: '—')), 0, 0, 'L');

            // Quantidade em destaque à direita — é o número que confere a caixa.
            $pdf->SetFont('Helvetica', 'B', $tamanho['qtd']);
            $pdf->SetXY(self::LARGURA - self::MARGEM - $larguraQtd, $y);
            $pdf->Cell($larguraQtd, $tamanho['altura'] - 1, $this->t($linha['quantidade'].'x'), 0, 0, 'R');

            if ($i < count($linhas) - 1) {
                $pdf->SetLineWidth(0.15);
                $pdf->Line(self::MARGEM, $y + $tamanho['altura'] - 0.6, self::LARGURA - self::MARGEM, $y + $tamanho['altura'] - 0.6);
                $pdf->SetLineWidth(0.6);
            }

            $y += $tamanho['altura'];
        }
    }

    /** Corta com reticências o que não cabe numa linha da largura dada. */
    private function caber(FPDF $pdf, string $texto, float $largura): string
    {
        $convertido = $this->t($texto);

        if ($pdf->GetStringWidth($convertido) <= $largura) {
            return $convertido;
        }

        while ($convertido !== '' && $pdf->GetStringWidth($convertido.'...') > $largura) {
            $convertido = substr($convertido, 0, -1);
        }

        return rtrim($convertido).'...';
    }

    /**
     * Um produto por linha (itens repetidos do mesmo produto somam), na
     * ordem do pedido. Cor do cadastro do produto; nome sem a cor no fim,
     * pra ela não aparecer duas vezes.
     *
     * @return list<array{nome: string, cor: ?string, sku: ?string, quantidade: int}>
     */
    private function linhasDaDeclaracao(CorreiosPrePostagem $pp): array
    {
        $itens = $pp->order?->items ?? collect();

        if ($itens->isEmpty()) {
            return collect($pp->content_items ?? [])->map(fn ($i) => [
                'nome' => (string) ($i['conteudo'] ?? 'Produto'),
                'cor' => null,
                'sku' => $i['sku'] ?? null,
                'quantidade' => (int) ($i['quantidade'] ?? 1),
            ])->values()->all();
        }

        return $itens
            ->groupBy(fn ($item) => $item->product_id ?? 'n:'.$item->product_name)
            ->map(function ($grupo) {
                $produto = $grupo->first()->product;
                $nome = (string) ($produto?->name ?? $grupo->first()->product_name);
                $cor = $produto?->color ? trim($produto->color) : null;

                if ($cor && str_ends_with(mb_strtolower($nome), ' '.mb_strtolower($cor))) {
                    $nome = trim(mb_substr($nome, 0, mb_strlen($nome) - mb_strlen($cor)));
                }

                return ['nome' => $nome, 'cor' => $cor, 'sku' => $produto?->sku, 'quantidade' => (int) $grupo->sum('quantity')];
            })
            ->values()
            ->all();
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
