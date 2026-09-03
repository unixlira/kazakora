import { router } from '@inertiajs/vue3';

export const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

export const addToCart = (productId) => {
    router.post('/carrinho', { product_id: productId, quantity: 1 }, { preserveScroll: true });
};

export const toggleFavorite = (productId) => {
    router.post(`/favoritos/${productId}`, {}, { preserveScroll: true });
};

/**
 * Performance 2026-09-03: card e carrinho mostram a foto num quadro de
 * ~250px, mas usavam a imagem de catálogo inteira (1600px, ~150 KB). Agora
 * usam a miniatura — `thumb_url` cai pra original sozinho quando a foto
 * ainda não tem miniatura gerada, então nada some enquanto o backfill
 * (`artisan catalog:generate-thumbnails`) não passou.
 *
 * A página de produto continua usando `image.url` de propósito: lá o zoom
 * precisa da foto grande.
 */
export const cardImageUrl = (image) => image?.thumb_url ?? image?.url ?? null;

export const primaryImage = (product) => {
    const image = product.images?.find((img) => img.is_primary) ?? product.images?.[0];
    return cardImageUrl(image);
};

export const specLine = (product) => {
    const parts = [product.brand, product.model, product.color].filter(Boolean);
    return parts.length ? parts.join(' · ') : null;
};
