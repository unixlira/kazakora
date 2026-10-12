<?php

namespace App\Modules\Admin\Support\Publicidade;

use InvalidArgumentException;

/**
 * Plataformas de anúncio fora dos marketplaces (Meta Ads e Google Ads),
 * menu Publicidade (pedido do Lira em 2026-10-12).
 *
 * Hoje NÃO existe integração com nenhuma das duas: não há credencial no
 * projeto e nenhum serviço chama a API delas. As telas mostram isso
 * claramente e leem só a tabela channel_ad_spends (a mesma do Shopee Ads e
 * do Mercado Ads), filtrando pelo `canal` daqui. Enquanto ninguém gravar
 * linha com esse canal, a tela fica no estado "integração ainda não
 * conectada".
 *
 * Ponto de integração (quando o Lira liberar o acesso):
 *  1. Criar o serviço da plataforma, no mesmo formato de
 *     App\Services\Shopee\ShopeeAdsService::dailyPerformance(), devolvendo
 *     por dia: impressions, clicks, attributed_orders, attributed_gmv, spend.
 *  2. Ligar no comando ads:sync-spend (App\Console\Commands\
 *     SyncChannelAdSpend), gravando com channel = canal() daqui.
 *  3. Decidir com o Lira como esse gasto entra na conta de lucro:
 *     ContributionMargin::periodo() soma channel_ad_spends inteiro (então
 *     passa a descontar sozinho), mas porCanal() separa por `channel`, e
 *     "meta_ads"/"google_ads" não são origem de pedido. Provavelmente o
 *     gasto deve ir para o canal `loja` (site próprio). Não gravar nada
 *     antes dessa decisão.
 */
final class PlataformaDeAnuncio
{
    public const META = 'meta_ads';

    public const GOOGLE = 'google_ads';

    private const PLATAFORMAS = [
        self::META => [
            'nome' => 'Meta ADS',
            'subtitulo' => 'Anúncios no Facebook e no Instagram',
            'icone' => 'fab fa-meta',
            'cor' => '#0866FF',
            'precisa' => [
                'Conta de anúncios da Meta (ID act_...) ligada ao Gerenciador de Negócios da KazaKora.',
                'App da Meta com a permissão ads_read e um token de usuário do sistema (só leitura).',
                'OK do Lira para guardar esse acesso no servidor.',
            ],
            'painel' => 'https://adsmanager.facebook.com/',
            'painel_nome' => 'Gerenciador de Anúncios',
        ],
        self::GOOGLE => [
            'nome' => 'Google ADS',
            'subtitulo' => 'Anúncios na pesquisa, no Shopping e no YouTube',
            'icone' => 'fab fa-google',
            'cor' => '#34A853',
            'precisa' => [
                'ID da conta do Google Ads (formato 000-000-0000).',
                'Developer token da API do Google Ads e um acesso OAuth com permissão de leitura.',
                'OK do Lira para guardar esse acesso no servidor.',
            ],
            'painel' => 'https://ads.google.com/',
            'painel_nome' => 'Google Ads',
        ],
    ];

    /**
     * @return array{canal: string, nome: string, subtitulo: string, icone: string, cor: string, precisa: array<int, string>, painel: string, painel_nome: string}
     */
    public static function dados(string $canal): array
    {
        if (! isset(self::PLATAFORMAS[$canal])) {
            throw new InvalidArgumentException("Plataforma de anúncio desconhecida: {$canal}");
        }

        return ['canal' => $canal] + self::PLATAFORMAS[$canal];
    }
}
