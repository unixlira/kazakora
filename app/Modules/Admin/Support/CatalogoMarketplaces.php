<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Http\Controllers\MarketplaceAdPhotoController;
use App\Modules\Admin\Http\Controllers\MercadoLivreCupomController;
use App\Modules\Admin\Http\Controllers\OportunidadesController;
use App\Modules\Admin\Http\Controllers\PublicidadeShopeeController;
use App\Modules\Admin\Http\Controllers\RadarController;
use App\Modules\Marketplace\Models\MarketplaceAccount;

/**
 * Menu Marketplaces do admin (pedido do Lira, 17/10/2026): um lugar por
 * marketplace, com as mesmas seis abas em todos.
 *
 * Só entram os canais com integração de verdade no código
 * (MarketplaceDriverManager): Mercado Livre e Shopee pela API direta,
 * TikTok Shop e Amazon com os pedidos chegando pelo Bling. A Shein existe
 * só como rótulo de origem de pedido, sem driver, por isso fica de fora.
 *
 * Cada aba é de um destes tipos:
 * - `tela`: renderiza a tela que já existe (mesmo controller, mesma página
 *   Vue), com as abas do marketplace em cima. Nada é duplicado.
 * - `em_breve`: estado vazio honesto, com a frase do que vai ter. Pode ter
 *   um `atalho` para uma tela parecida que já existe.
 */
final class CatalogoMarketplaces
{
    /** Ordem e nome das abas, iguais em todos os marketplaces. */
    public const ABAS = [
        'oportunidades' => 'Oportunidades',
        'promocao' => 'Promoção',
        'imagens' => 'Imagens',
        'videoclipes' => 'Videoclipes',
        'cupom' => 'Cupom de desconto',
        'campanha' => 'Gerar campanha',
    ];

    public const ABA_PADRAO = 'oportunidades';

    /** Permissão da área inteira (rota e item do menu). */
    public const PERMISSAO = 'relatorios.view';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function todos(): array
    {
        return [
            'mercado-livre' => [
                'nome' => 'Mercado Livre',
                'canal' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
                'logo' => '/images/marketplaces/mercado-livre.svg',
                'integracao' => 'API do Mercado Livre: pedidos, anúncios, envios, devoluções e cupons.',
                'abas' => [
                    'oportunidades' => [
                        'tela' => [OportunidadesController::class, 'show'],
                        'params' => ['canal' => 'mercado-livre'],
                        'permissao' => 'relatorios.view',
                        'origem' => ['nome' => 'Oportunidades › Mercado Livre', 'href' => '/admin/oportunidades/mercado-livre'],
                    ],
                    'promocao' => ['em_breve' => 'Campanhas da Central de Promoções (relâmpago, desconto, cofinanciadas) com a margem de cada anúncio antes de entrar.'],
                    'imagens' => [
                        'tela' => [MarketplaceAdPhotoController::class, 'index'],
                        'query' => ['marketplace' => 'mercado_livre'],
                        'permissao' => 'relatorios.view',
                        'origem' => ['nome' => 'Fotos de anúncio', 'href' => '/admin/marketplaces/fotos-anuncio?marketplace=mercado_livre'],
                    ],
                    'videoclipes' => ['em_breve' => 'Clipes dos anúncios: quais têm vídeo, quais faltam e o envio para o Mercado Livre.'],
                    'cupom' => [
                        'tela' => [MercadoLivreCupomController::class, 'index'],
                        'permissao' => 'admin',
                        'origem' => ['nome' => 'Integrações › Mercado Livre › Criar cupom', 'href' => '/admin/integracoes/mercado-livre/cupom'],
                    ],
                    'campanha' => ['em_breve' => 'Montar campanha de Mercado Ads com orçamento e meta de ROAS, enviada só depois da sua confirmação.'],
                ],
            ],
            'shopee' => [
                'nome' => 'Shopee',
                'canal' => MarketplaceAccount::CHANNEL_SHOPEE,
                'logo' => '/images/marketplaces/shopee.svg',
                'integracao' => 'API da Shopee: pedidos, produtos, avaliações e ADS.',
                'abas' => [
                    'oportunidades' => [
                        'tela' => [OportunidadesController::class, 'show'],
                        'params' => ['canal' => 'shopee'],
                        'permissao' => 'relatorios.view',
                        'origem' => ['nome' => 'Oportunidades › Shopee', 'href' => '/admin/oportunidades/shopee'],
                    ],
                    'promocao' => ['em_breve' => 'Ofertas relâmpago e descontos da loja na Shopee, com a margem conferida antes de ativar.'],
                    'imagens' => [
                        'tela' => [MarketplaceAdPhotoController::class, 'index'],
                        'query' => ['marketplace' => 'shopee'],
                        'permissao' => 'relatorios.view',
                        'origem' => ['nome' => 'Fotos de anúncio', 'href' => '/admin/marketplaces/fotos-anuncio?marketplace=shopee'],
                    ],
                    'videoclipes' => ['em_breve' => 'Vídeos dos anúncios da Shopee: quais produtos estão sem vídeo e o envio.'],
                    'cupom' => ['em_breve' => 'Cupons da loja na Shopee com o mesmo cuidado do Mercado Livre: modelo pronto e confirmação antes de criar.'],
                    'campanha' => [
                        'tela' => [PublicidadeShopeeController::class, 'index'],
                        'permissao' => 'financeiro.view',
                        'origem' => ['nome' => 'Publicidade › Estratégia Shopee', 'href' => '/admin/publicidade/shopee'],
                        'aviso' => 'Por enquanto esta aba mostra a Estratégia de ADS: as campanhas que já existem e o que fazer com cada uma. Criar campanha nova por aqui ainda não existe.',
                    ],
                ],
            ],
            'tiktok-shop' => [
                'nome' => 'TikTok Shop',
                'canal' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
                'logo' => '/images/marketplaces/tiktok-shop.png',
                'integracao' => 'Pedidos chegam pelo Bling; extratos financeiros pelo agente do outro PC.',
                'abas' => [
                    'oportunidades' => [
                        'tela' => [RadarController::class, 'index'],
                        'params' => ['fonte' => 'tiktok'],
                        'permissao' => 'relatorios.view',
                        'origem' => ['nome' => 'Oportunidades › TikTok', 'href' => '/admin/radar/tiktok'],
                    ],
                    'promocao' => ['em_breve' => 'Promoções da loja no TikTok Shop, quando a API de parceiro for aprovada.'],
                    'imagens' => ['em_breve' => 'Fotos dos produtos no TikTok Shop, no mesmo gerador usado para Shopee e Mercado Livre.'],
                    'videoclipes' => [
                        'em_breve' => 'Vídeos curtos dos produtos para o TikTok Shop, com o que já foi postado e o que falta.',
                        'atalho' => ['nome' => 'Baixar vídeos', 'href' => '/admin/download-videos', 'permissao' => 'operacional.create', 'texto' => 'Para baixar um vídeo de referência do TikTok, use a tela que já existe.'],
                    ],
                    'cupom' => ['em_breve' => 'Cupons do TikTok Shop, quando a API de parceiro for aprovada.'],
                    'campanha' => ['em_breve' => 'Campanhas de anúncio do TikTok Shop com orçamento e meta, enviadas só com a sua confirmação.'],
                ],
            ],
            'amazon' => [
                'nome' => 'Amazon',
                'canal' => MarketplaceAccount::CHANNEL_AMAZON,
                'logo' => '/images/marketplaces/amazon.svg',
                'integracao' => 'Pedidos chegam pelo Bling; pagamentos pela API da Amazon.',
                'abas' => [
                    'oportunidades' => ['em_breve' => 'Mais vendidos e buscas em alta na Amazon, como já existe para Mercado Livre e Shopee.'],
                    'promocao' => ['em_breve' => 'Ofertas e promoções da loja na Amazon, com a margem conferida antes.'],
                    'imagens' => ['em_breve' => 'Fotos dos anúncios da Amazon no padrão exigido por ela (fundo branco, tamanho mínimo).'],
                    'videoclipes' => ['em_breve' => 'Vídeos dos anúncios da Amazon.'],
                    'cupom' => ['em_breve' => 'Cupons da loja na Amazon, com confirmação antes de criar.'],
                    'campanha' => ['em_breve' => 'Campanhas de Sponsored Products com orçamento e meta, enviadas só com a sua confirmação.'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function encontrar(string $slug): ?array
    {
        return self::todos()[$slug] ?? null;
    }

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_keys(self::todos());
    }

    public static function url(string $slug, ?string $aba = null): string
    {
        return "/admin/marketplaces/{$slug}/".($aba ?? self::ABA_PADRAO);
    }
}
