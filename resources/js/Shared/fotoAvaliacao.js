// Fotos de avaliação importadas vêm no tamanho cheio do marketplace (ML: 900x1200,
// até ~370 KB) e eram baixadas inteiras para virar miniatura de 60px (PageSpeed,
// 2026-10-21). Os dois marketplaces servem a mesma foto em tamanhos menores pelo
// sufixo do endereço; foto guardada na loja segue como está.

const ML_FULL = /^(https:\/\/http2\.mlstatic\.com\/D_[^?#]+)-F\.(?:jpe?g|webp)$/i;
const SHOPEE = /^https:\/\/[a-z0-9.-]*susercontent\.com\/(?:file\/)?[a-z0-9-]+$/i;

// Miniatura da lista: ML 120x160 em WebP (~5 KB); Shopee "_tn" (~132px, ~10 KB).
export const miniaturaAvaliacao = (url) => {
    if (!url) return url;
    const ml = url.match(ML_FULL);
    if (ml) return `${ml[1]}-T.webp`;
    if (SHOPEE.test(url)) return `${url}_tn`;
    return url;
};

// Foto ampliada: mesma resolução do ML, só que em WebP (~50% menor).
export const fotoAvaliacaoAmpliada = (url) => {
    const ml = url?.match(ML_FULL);
    return ml ? `${ml[1]}-F.webp` : url;
};
