<?php

namespace App\Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copia a planilha da Zaio (Google Sheets, abas Shopee, TikTok e Mercado
 * Livre) para a tabela zaio_anuncios (pedido do Lira em 27/11/2026: "as
 * métricas da Zaio devem aparecer no dashboard-financeiro"). Cada linha é a
 * conta da agência para um anúncio: preço de hoje, custo, renda estimada e
 * margem, com tarifa, taxa fixa, imposto, ADS/afiliados e comissão Zaio já
 * embutidos no preço. É referência: o lucro real continua sendo o de
 * ContributionMargin.
 */
class PlanilhaZaio
{
    /** Aba da planilha => canal da loja. */
    public const ABAS = [
        ' 🟠 Shopee' => 'shopee',
        '⚫Tik Tok' => 'tiktok_shop',
        '🟡Mercado Livre' => 'mercado_livre',
    ];

    /** @return array<string, int> linhas gravadas por canal */
    public function sincronizar(): array
    {
        $id = (string) config('loja.zaio.planilha_id');
        if ($id === '') {
            throw new RuntimeException('Planilha da Zaio não configurada (LOJA_ZAIO_PLANILHA_ID).');
        }

        $porCanal = [];
        $linhas = [];
        foreach (self::ABAS as $aba => $canal) {
            $csv = Http::timeout(60)->get("https://docs.google.com/spreadsheets/d/{$id}/gviz/tq", ['tqx' => 'out:csv', 'sheet' => $aba])->throw()->body();
            $daAba = $this->linhasDaAba($csv, $canal);
            $porCanal[$canal] = count($daAba);
            $linhas = [...$linhas, ...$daAba];
        }

        if ($linhas === []) {
            throw new RuntimeException('A planilha da Zaio veio vazia: nada foi trocado.');
        }

        DB::transaction(function () use ($linhas) {
            DB::table('zaio_anuncios')->delete();
            foreach (array_chunk($linhas, 200) as $lote) {
                DB::table('zaio_anuncios')->insert($lote);
            }
        });

        return $porCanal;
    }

    /** @return list<array<string, mixed>> */
    public function linhasDaAba(string $csv, string $canal): array
    {
        $arquivo = fopen('php://memory', 'r+');
        fwrite($arquivo, $csv);
        rewind($arquivo);
        $cabecalho = fgetcsv($arquivo, null, ',', '"', '') ?: [];
        $col = fn (string $padrao) => collect($cabecalho)->search(fn ($h) => (bool) preg_match($padrao, (string) $h));
        $i = [
            'id' => $col('/Id Anúncio/u'), 'nome' => $col('/^Nome$/u'), 'custo' => $col('/Preço Custo/u'),
            'desejada' => $col('/Margem desejada/u'), 'preco' => $col('/Preço hoje/u'), 'tipo' => $col('/Tipo do anúncio/u'),
            'renda' => $col('/Renda Estimada/u'), 'margem' => $col('/Margem de contribuição:/u'), 'margem_pct' => $col('/Margem de Contribuição %/u'),
            'tarifa' => $col('/Tarifa do Anúncio/u'), 'fixa' => $col('/Taxa Fixa/u'), 'imposto' => $col('/^Imposto$/u'),
            'ads' => $col('/Ads\/Afiliados/u'), 'comissao' => $col('/^Comissão$/u'), 'sugerido' => $col('/Valor de venda/u'),
        ];
        if ($i['id'] === false || $i['nome'] === false || $i['preco'] === false) {
            throw new RuntimeException("Aba de {$canal} da planilha da Zaio mudou de formato (faltam Id Anúncio, Nome ou Preço hoje).");
        }

        $agora = now();
        $linhas = [];
        while (($l = fgetcsv($arquivo, null, ',', '"', '')) !== false) {
            $v = fn ($k) => $i[$k] === false ? null : trim((string) ($l[$i[$k]] ?? ''));
            $anuncio = (string) $v('id');
            if ($anuncio === '' || ! preg_match('/^[A-Z0-9]+$/i', $anuncio) || $v('nome') === '') {
                continue;
            }
            // Mercado Livre: a tarifa vem no tipo do anúncio ("Tarifa Clássica - 14%").
            $tarifa = self::pct($v('tarifa')) ?? (preg_match('/(\d+(?:[,.]\d+)?)\s*%/', (string) $v('tipo'), $m) ? (float) str_replace(',', '.', $m[1]) : null);
            $linhas[] = [
                'canal' => $canal,
                'anuncio_id' => $anuncio,
                'nome' => Str::limit((string) $v('nome'), 295, ''),
                'product_id' => null,
                'custo' => self::reais($v('custo')),
                'preco' => self::reais($v('preco')),
                'renda_estimada' => self::reais($v('renda')),
                'margem' => self::reais($v('margem')),
                'margem_pct' => self::pct($v('margem_pct')),
                'margem_desejada_pct' => self::pct($v('desejada')),
                'preco_sugerido' => self::reais($v('sugerido')),
                'tarifa_pct' => $tarifa,
                'taxa_fixa' => self::reais($v('fixa')),
                'imposto_pct' => self::pct($v('imposto')),
                'ads_pct' => self::pct($v('ads')),
                'comissao_pct' => self::pct($v('comissao')),
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }
        fclose($arquivo);

        return self::ligarProdutos($linhas);
    }

    /** "R$ 1.403,45" => 1403.45; vazio ou "#N/A" => null. */
    public static function reais(?string $texto): ?float
    {
        $t = preg_replace('/[^\d,.\-]/', '', (string) $texto);
        if ($t === '' || $t === '-') {
            return null;
        }

        $valor = round((float) str_replace(',', '.', str_replace('.', '', $t)), 2);

        // Célula quebrada na planilha (27/11/2026: o ID do anúncio do Nível
        // Laser no TikTok foi parar no custo e a margem virou R$ 1,7 quintilhão):
        // fica vazia em vez de travar a cópia das outras linhas.
        return abs($valor) > 1_000_000 ? null : $valor;
    }

    /** "12,81%" => 12.81; "0,14" (fração) => 14.0. */
    public static function pct(?string $texto): ?float
    {
        $t = trim((string) $texto);
        if ($t === '' || ! preg_match('/\d/', $t)) {
            return null;
        }
        $n = (float) str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $t));

        $pct = round(str_contains($t, '%') ? $n : $n * 100, 2);

        return abs($pct) > 9999 ? null : $pct;
    }

    /**
     * Liga cada linha ao produto da loja pelo anúncio do canal
     * (product_channel_listings ou, sem ela, os pedidos daquele anúncio).
     * Anúncio com variações: a de nome mais parecido com a linha da planilha.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return list<array<string, mixed>>
     */
    private static function ligarProdutos(array $linhas): array
    {
        $palavras = fn (string $s) => array_unique(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($s))), fn ($p) => strlen($p) > 1));
        $anuncios = DB::table('product_channel_listings as l')->join('products as p', 'p.id', '=', 'l.product_id')
            ->whereIn('l.external_id', array_unique(array_column($linhas, 'anuncio_id')))
            ->get(['l.channel', 'l.external_id', 'p.id', 'p.name', 'p.variation'])
            ->groupBy(fn ($r) => $r->channel.'|'.$r->external_id);

        // Sem listagem cadastrada (TikTok, anúncio antigo): o produto dos pedidos
        // que vieram daquele anúncio (order_items.external_item_id).
        $dosPedidos = DB::table('order_items')->join('products as p', 'p.id', '=', 'order_items.product_id')
            ->whereIn('order_items.external_item_id', array_unique(array_column($linhas, 'anuncio_id')))
            ->select('order_items.external_item_id', 'p.id', 'p.name', 'p.variation')->distinct()
            ->get()->groupBy('external_item_id');

        foreach ($linhas as &$linha) {
            $opcoes = $anuncios->get($linha['canal'].'|'.$linha['anuncio_id']) ?? $dosPedidos->get($linha['anuncio_id']);
            if (! $opcoes) {
                continue;
            }
            $daLinha = $palavras($linha['nome']);
            $linha['product_id'] = $opcoes->sortByDesc(fn ($p) => count(array_intersect($daLinha, $palavras($p->name.' '.$p->variation))))->first()->id;
        }

        return $linhas;
    }
}
