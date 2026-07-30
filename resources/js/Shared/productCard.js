import { router } from '@inertiajs/vue3';

export const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

export const addToCart = (productId) => {
    router.post('/carrinho', { product_id: productId, quantity: 1 }, { preserveScroll: true });
};

export const toggleFavorite = (productId) => {
    router.post(`/favoritos/${productId}`, {}, { preserveScroll: true });
};

export const primaryImage = (product) => {
    const image = product.images?.find((img) => img.is_primary) ?? product.images?.[0];
    return image?.url ?? null;
};

export const specLine = (product) => {
    const parts = [product.brand, product.model, product.color].filter(Boolean);
    return parts.length ? parts.join(' · ') : null;
};
