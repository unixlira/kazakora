<?php

namespace App\Modules\Catalog\Support;

/**
 * Nome da loja no texto do produto (pedido do Lira 26/11/2026): a descrição e o conteúdo da
 * página são os mesmos na KazaKora e na FerraKora, então quem abre a kazakora.com lê "KazaKora"
 * e quem abre a ferrakora.com lê "FerraKora". Só troca na hora de mostrar; o banco fica igual.
 * Endereços (url, foto, vídeo, link) não mudam: o arquivo continua no mesmo caminho.
 */
final class MarcaDaLoja
{
    public static function paraKazaKora(mixed $valor): mixed
    {
        return self::trocar($valor, 'FerraKora', 'KazaKora');
    }

    public static function paraFerraKora(mixed $valor): mixed
    {
        return self::trocar($valor, 'KazaKora', 'FerraKora');
    }

    private static function trocar(mixed $valor, string $de, string $para, ?string $chave = null): mixed
    {
        if (is_array($valor)) {
            $saida = [];
            foreach ($valor as $k => $v) {
                $saida[$k] = self::trocar($v, $de, $para, is_string($k) ? $k : $chave);
            }

            return $saida;
        }

        if (! is_string($valor) || stripos($valor, $de) === false) {
            return $valor;
        }
        if (($chave !== null && preg_match('/url|path|src|video|imagem|foto|link|href|slug|arquivo/i', $chave))
            || preg_match('#^(https?:)?/#i', $valor)) {
            return $valor;
        }

        return preg_replace_callback('/'.preg_quote($de, '/').'/i', fn (array $m) => match (true) {
            $m[0] === strtoupper($m[0]) => strtoupper($para),
            $m[0] === strtolower($m[0]) => strtolower($para),
            default => $para,
        }, $valor);
    }
}
