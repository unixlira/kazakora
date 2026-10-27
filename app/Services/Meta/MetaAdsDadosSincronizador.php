<?php

namespace App\Services\Meta;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Grava no nosso banco os metadados das campanhas da Meta (pedido do Lira
 * em 2026-10-27): estrutura da conta e performance diária de cada anúncio.
 * Só lê da Meta; nada é mudado lá. Rodar de novo não duplica (chave = id
 * da Meta, e anúncio + dia no desempenho).
 */
class MetaAdsDadosSincronizador
{
    private const FUSO = 'America/Sao_Paulo';

    public function __construct(private MetaAdsService $meta) {}

    /**
     * @param  int|null  $dias  Dias para trás (contando hoje); null = desde o início da conta.
     * @return array{campanhas: int, conjuntos: int, anuncios: int, dias_com_dado: int, de: ?string, ate: string}
     *
     * @throws MetaAdsException
     */
    public function sincronizar(?int $dias = 7): array
    {
        $ate = now(self::FUSO)->startOfDay();
        $de = $dias ? $ate->copy()->subDays(max($dias, 1) - 1) : null;

        // Lê tudo antes de gravar: se a Meta falhar no meio, o banco fica como estava.
        $estrutura = $this->meta->estruturaDaConta();
        $desempenho = $this->meta->desempenhoDiarioPorAnuncio($de, $de ? $ate : null);
        $agora = now();

        DB::transaction(function () use ($estrutura, $desempenho, $agora) {
            $this->gravarNivel('meta_ads_dados_campanhas', $estrutura['campanhas'], $agora);
            $this->gravarNivel('meta_ads_dados_conjuntos', $estrutura['conjuntos'], $agora);
            $this->gravarNivel('meta_ads_dados_anuncios', $estrutura['anuncios'], $agora);

            // Excluído a Meta não lista: entra pelo nome que veio no insights.
            $this->gravarExcluidos('meta_ads_dados_campanhas', collect($desempenho)->unique('campanha_meta_id')
                ->map(fn ($l) => ['meta_id' => $l['campanha_meta_id'], 'nome' => $l['campanha_nome']]), $agora);
            $this->gravarExcluidos('meta_ads_dados_conjuntos', collect($desempenho)->unique('conjunto_meta_id')
                ->map(fn ($l) => ['meta_id' => $l['conjunto_meta_id'], 'nome' => $l['conjunto_nome'], 'campanha_meta_id' => $l['campanha_meta_id']]), $agora);
            $this->gravarExcluidos('meta_ads_dados_anuncios', collect($desempenho)->unique('anuncio_meta_id')
                ->map(fn ($l) => ['meta_id' => $l['anuncio_meta_id'], 'nome' => $l['anuncio_nome'], 'conjunto_meta_id' => $l['conjunto_meta_id'], 'campanha_meta_id' => $l['campanha_meta_id']]), $agora);

            foreach (array_chunk($desempenho, 500) as $lote) {
                DB::table('meta_ads_dados_desempenho')->upsert(
                    array_map(fn ($l) => [
                        'anuncio_meta_id' => $l['anuncio_meta_id'],
                        'conjunto_meta_id' => $l['conjunto_meta_id'],
                        'campanha_meta_id' => $l['campanha_meta_id'],
                        'data' => $l['data'],
                        'gasto' => $l['gasto'],
                        'impressoes' => $l['impressoes'],
                        'cliques' => $l['cliques'],
                        'cliques_link' => $l['cliques_link'],
                        'visualizacoes_pagina' => $l['visualizacoes_pagina'],
                        'carrinho' => $l['carrinho'],
                        'checkout' => $l['checkout'],
                        'compras' => $l['compras'],
                        'vendas' => $l['vendas'],
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ], $lote),
                    ['anuncio_meta_id', 'data'],
                    ['conjunto_meta_id', 'campanha_meta_id', 'gasto', 'impressoes', 'cliques', 'cliques_link', 'visualizacoes_pagina', 'carrinho', 'checkout', 'compras', 'vendas', 'updated_at'],
                );
            }
        });

        return [
            'campanhas' => count($estrutura['campanhas']),
            'conjuntos' => count($estrutura['conjuntos']),
            'anuncios' => count($estrutura['anuncios']),
            'dias_com_dado' => count($desempenho),
            'de' => $de?->toDateString(),
            'ate' => $ate->toDateString(),
        ];
    }

    /**
     * Grava o que a Meta listou e marca como excluído (DELETED) o que estava
     * no banco e não veio mais: a Meta só deixa de listar o que foi excluído.
     *
     * @param  list<array<string, mixed>>  $itens
     */
    private function gravarNivel(string $tabela, array $itens, Carbon $agora): void
    {
        $linhas = array_map(fn ($i) => $i + ['sincronizado_em' => $agora, 'created_at' => $agora, 'updated_at' => $agora], $itens);
        foreach (array_chunk($linhas, 200) as $lote) {
            DB::table($tabela)->upsert($lote, ['meta_id'], array_values(array_diff(array_keys($lote[0]), ['meta_id', 'created_at'])));
        }

        DB::table($tabela)
            ->whereNotIn('meta_id', array_column($itens, 'meta_id'))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'DELETED'))
            ->update(['status' => 'DELETED', 'sincronizado_em' => $agora, 'updated_at' => $agora]);
    }

    /** Objeto com desempenho que não está no banco nem na lista da Meta: entra como excluído. */
    private function gravarExcluidos(string $tabela, \Illuminate\Support\Collection $itens, Carbon $agora): void
    {
        $existentes = DB::table($tabela)->whereIn('meta_id', $itens->pluck('meta_id'))->pluck('meta_id')->all();

        $novos = $itens->reject(fn ($i) => in_array($i['meta_id'], $existentes, true))
            ->map(fn ($i) => $i + ['status' => 'DELETED', 'sincronizado_em' => $agora, 'created_at' => $agora, 'updated_at' => $agora])
            ->values()->all();

        foreach (array_chunk($novos, 200) as $lote) {
            DB::table($tabela)->insert($lote);
        }
    }
}
