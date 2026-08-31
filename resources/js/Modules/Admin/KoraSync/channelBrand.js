/**
 * Identidade visual de cada canal — mesmos valores do app desktop KoraSync
 * (Theming/ChannelBrandColors.cs), reaproveitados aqui pra ficar
 * "igualzinho" (pedido explícito 2026-08-31). Cores são a cor de MARCA do
 * canal (faixa/badge), nunca cor de status — essas ficam nos tokens
 * --ks-warning/--ks-error/etc. definidos em Index.vue.
 */
export const CHANNEL_BRAND = {
    loja: { name: 'KazaKora', short: 'KazaKora', color: '#04D7B6' },
    mercado_livre: { name: 'Mercado Livre', short: 'MeLi', color: '#FFE600' },
    shopee: { name: 'Shopee', short: 'Shopee', color: '#EE4D2D' },
    tiktok_shop: { name: 'TikTok Shop', short: 'TikTok', color: '#7B61FF' },
    amazon: { name: 'Amazon', short: 'Amazon', color: '#FF9900' },
    shein: { name: 'Shein', short: 'Shein', color: '#3D3D3D' },
};

export function channelBrand(channel) {
    return CHANNEL_BRAND[channel] ?? { name: channel ?? '—', short: channel ?? '—', color: '#8B8DA0' };
}
