// Marketplaces da tela Perguntas MK Place (Lira, 2026-10-20): ícone quadrado
// para a lista e o aviso do canto, logo para o painel da pergunta.
export const CANAIS_PERGUNTAS = {
    mercado_livre: { nome: 'Mercado Livre', icone: 'fas fa-handshake', fundo: 'bg-[#ffe600] text-[#2d3277]', logo: '/imagens/mercado_livre.png' },
    shopee: { nome: 'Shopee', icone: 'fas fa-bag-shopping', fundo: 'bg-[#ee4d2d] text-white', logo: '/imagens/shopee.png' },
    amazon: { nome: 'Amazon', icone: 'fab fa-amazon', fundo: 'bg-[#232f3e] text-[#ff9900]', logo: '/imagens/amazon.png' },
    tiktok_shop: { nome: 'TikTok Shop', icone: 'fab fa-tiktok', fundo: 'bg-black text-white', logo: '/imagens/tiktok_shop.png' },
};

export const canalPergunta = (canal) => CANAIS_PERGUNTAS[canal] ?? { nome: canal || 'Marketplace', icone: 'fas fa-store', fundo: 'bg-slate-500 text-white', logo: null };
