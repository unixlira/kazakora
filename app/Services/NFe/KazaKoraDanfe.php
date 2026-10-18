<?php

namespace App\Services\NFe;

use NFePHP\DA\NFe\Danfe;

/**
 * DANFE com a marca da KazaKora: o quadro "Identificação do emitente" leva o
 * logotipo colorido (laranja e preto) no topo e os dados obrigatórios do
 * emitente (nome, endereço, telefone) logo abaixo, em fonte menor e com menos
 * espaço entre as linhas. O resto do DANFE é o padrão da sped-da.
 */
class KazaKoraDanfe extends Danfe
{
    /** Imagem do logo já achatada em fundo branco (data URI JPEG). */
    private ?string $marca = null;

    private float $marcaProporcao = 0;

    public function setMarca(string $conteudoImagem): void
    {
        $imagem = @imagecreatefromstring($conteudoImagem);

        if (! $imagem) {
            return;
        }

        $largura = imagesx($imagem);
        $altura = imagesy($imagem);

        // A sped-da grava a imagem como JPEG: o fundo transparente do PNG
        // viraria preto, então achata sobre branco antes.
        $fundo = imagecreatetruecolor($largura, $altura);
        imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255));
        imagecopy($fundo, $imagem, 0, 0, 0, 0, $largura, $altura);

        ob_start();
        imagejpeg($fundo, null, 95);
        $jpeg = ob_get_clean();

        $this->marca = 'data://text/plain;base64,'.base64_encode($jpeg);
        $this->marcaProporcao = $largura / $altura;
    }

    protected function header($x = 0, $y = 0, $pag = '1', $totPag = '1')
    {
        if ($this->marca === null) {
            return parent::header($x, $y, $pag, $totPag);
        }

        // Com logoAlign 'F' e sem logomarca a sped-da desenha só a moldura e o
        // título do quadro do emitente; o conteúdo é desenhado aqui.
        $this->logomarca = null;
        $this->logoAlign = 'F';
        $retorno = parent::header($x, $y, $pag, $totPag);

        $maxW = ($this->orientacao === 'P' || $pag != 1)
            ? $this->wPrint
            : $this->wPrint - $this->wCanhoto;
        $w = round($maxW * 0.41, 0);

        // Logo de até 12 mm de altura e, abaixo, ~10 mm de texto; o bloco fica
        // centralizado na altura do quadro (32 mm, 3 mm do título).
        $logoW = min($w - 8, 12 * $this->marcaProporcao);
        $logoH = $logoW / $this->marcaProporcao;
        $yLogo = $y + 3 + (29 - ($logoH + 10.5)) / 2;
        $this->pdf->Image($this->marca, $x + ($w - $logoW) / 2, $yLogo, $logoW, $logoH, 'jpeg');

        $yTexto = $yLogo + $logoH + 1;

        $aFont = ['font' => $this->fontePadrao, 'size' => 7.5, 'style' => 'B'];
        $nome = $this->emit->getElementsByTagName('xNome')->item(0)->nodeValue;
        $this->pdf->textBox($x + 1, $yTexto, $w - 2, 3.5, $nome, $aFont, 'T', 'C', 0, '');

        $cep = $this->formatField($this->getTagValue($this->enderEmit, 'CEP'), '#####-###');
        $fone = $this->getTagValue($this->enderEmit, 'fone');
        $texto = $this->getTagValue($this->enderEmit, 'xLgr').', '
            .$this->getTagValue($this->enderEmit, 'nro')
            .$this->getTagValue($this->enderEmit, 'xCpl', ' - ')
            .' - '.$this->getTagValue($this->enderEmit, 'xBairro')."\n"
            .$this->getTagValue($this->enderEmit, 'xMun').' - '
            .$this->getTagValue($this->enderEmit, 'UF').' - CEP '.$cep
            .($fone !== '' ? ' - Fone: '.$fone : '');
        $aFont = ['font' => $this->fontePadrao, 'size' => 6.5, 'style' => ''];
        $this->pdf->textBox($x + 1, $yTexto + 3.3, $w - 2, 6, $texto, $aFont, 'T', 'C', 0, '');

        return $retorno;
    }
}
