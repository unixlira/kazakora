<?php

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lê a primeira aba de uma planilha enviada pelo admin: .xlsx (Excel, Google
 * Planilhas baixada) ou .csv/.txt (separador ; , ou tab, UTF-8 ou Windows-1252).
 * Sem biblioteca: o .xlsx é um zip de XML (mesma leitura do
 * ImportadorDemonstrativoTikTok). Linhas vazias ficam de fora.
 */
class LerPlanilha
{
    /** @return list<list<string>> */
    public static function linhas(string $caminho, string $nomeOriginal): array
    {
        $extensao = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        $linhas = match ($extensao) {
            'xlsx' => self::xlsx($caminho),
            'csv', 'txt' => self::csv($caminho),
            default => throw new RuntimeException('Mande a planilha em .xlsx ou .csv.'),
        };

        return array_values(array_filter(
            array_map(fn ($l) => array_map(fn ($v) => trim((string) $v), $l), $linhas),
            fn ($l) => implode('', $l) !== '',
        ));
    }

    /** @return list<list<string>> */
    private static function csv(string $caminho): array
    {
        $texto = (string) file_get_contents($caminho);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }

        // Separador: o que mais aparece na primeira linha (Excel em português usa ;).
        $primeira = strtok($texto, "\r\n") ?: '';
        $contagem = [';' => substr_count($primeira, ';'), ',' => substr_count($primeira, ','), "\t" => substr_count($primeira, "\t")];
        arsort($contagem);
        $separador = array_key_first($contagem);

        $linhas = [];
        $arquivo = fopen('php://memory', 'r+');
        fwrite($arquivo, $texto);
        rewind($arquivo);
        while (($linha = fgetcsv($arquivo, null, $separador, '"', '')) !== false) {
            $linhas[] = array_map(fn ($v) => (string) $v, $linha);
        }
        fclose($arquivo);

        return $linhas;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $caminho): array
    {
        $zip = new ZipArchive;
        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('Não é um arquivo Excel (.xlsx) válido.');
        }

        try {
            $compartilhadas = [];
            if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                foreach ((new SimpleXMLElement($xml))->si as $si) {
                    $compartilhadas[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
                }
            }

            $livro = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($livro === false || $rels === false) {
                throw new RuntimeException('Não é um arquivo Excel (.xlsx) válido.');
            }
            $alvos = [];
            foreach ((new SimpleXMLElement($rels))->Relationship as $r) {
                $alvos[(string) $r['Id']] = 'xl/'.ltrim(str_replace('/xl/', '', (string) $r['Target']), '/');
            }
            $folha = (new SimpleXMLElement($livro))->sheets->sheet[0] ?? null;
            $rid = $folha ? (string) $folha->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
            $conteudo = $zip->getFromName($alvos[$rid] ?? 'xl/worksheets/sheet1.xml');
            if ($conteudo === false) {
                throw new RuntimeException('A planilha está vazia.');
            }

            $linhas = [];
            foreach ((new SimpleXMLElement($conteudo))->sheetData->row as $row) {
                $valores = [];
                foreach ($row->c as $c) {
                    $valores[self::indiceColuna((string) $c['r'])] = match ((string) $c['t']) {
                        's' => $compartilhadas[(int) $c->v] ?? '',
                        'inlineStr' => (string) $c->is->t,
                        default => (string) $c->v,
                    };
                }
                if ($valores) {
                    $linhas[] = array_map(fn ($i) => $valores[$i] ?? '', range(0, max(array_keys($valores))));
                }
            }

            return $linhas;
        } finally {
            $zip->close();
        }
    }

    private static function indiceColuna(string $ref): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/[^A-Z]/', '', strtoupper($ref))) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }
}
