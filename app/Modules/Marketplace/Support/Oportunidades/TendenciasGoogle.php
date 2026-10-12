<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Radar de Oportunidades (pedido do Lira 2026-10-12): buscas em alta no
 * Google Brasil, pelo RSS público do Google Trends
 * (trends.google.com/trending/rss?geo=BR). Não precisa de chave. São as
 * buscas do dia em geral (muita notícia e esporte), por isso entram como
 * sinal de apoio: o Radar cruza com os produtos dos marketplaces.
 */
class TendenciasGoogle
{
    public const URL = 'https://trends.google.com/trending/rss?geo=BR';

    public const LIMITE = 20;

    private const CACHE_HORAS = 3;

    private const NS = 'https://trends.google.com/trending/rss';

    public function analisar(bool $atualizar = false): array
    {
        $chave = 'oportunidades:google:trends:BR';
        if ($atualizar) {
            Cache::forget($chave);
        }

        try {
            return Cache::remember($chave, now()->addHours(self::CACHE_HORAS), fn () => $this->coletar());
        } catch (Throwable $e) {
            report($e);

            return ['termos' => [], 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar o Google Trends agora. Tente de novo em alguns minutos.'];
        }
    }

    private function coletar(): array
    {
        $xml = Http::timeout(15)->get(self::URL)->throw()->body();
        $rss = @simplexml_load_string($xml);
        if (! $rss instanceof SimpleXMLElement) {
            throw new RuntimeException('Google Trends: RSS inválido.');
        }

        $termos = [];
        foreach ($rss->channel->item ?? [] as $item) {
            if (count($termos) >= self::LIMITE) {
                break;
            }
            $ht = $item->children(self::NS);
            $noticia = $ht->news_item[0] ?? null;
            $termo = trim((string) $item->title);
            if ($termo === '') {
                continue;
            }
            $termos[] = [
                'termo' => $termo,
                'trafego' => trim((string) $ht->approx_traffic) ?: null,
                'noticia' => $noticia ? trim((string) $noticia->news_item_title) ?: null : null,
                'link' => 'https://www.google.com/search?q='.rawurlencode($termo),
            ];
        }

        return ['termos' => $termos, 'atualizado_em' => now()->toIso8601String(), 'erro' => null];
    }
}
