import { ref } from 'vue';

/**
 * Busca uma prévia do SKU em /admin/produtos/sku-preview (mesmo
 * SkuGeneratorService que roda de verdade ao salvar, sem gravar nada) —
 * mesmo padrão de composable do useCep.js (loading/error refs), mas via
 * fetch nativo com o cookie XSRF-TOKEN que o Laravel já seta em toda
 * sessão autenticada (esse endpoint é same-origin/autenticado, ao
 * contrário do ViaCEP, então precisa do header CSRF — não dá pra só
 * fetch() puro como lá).
 */
function csrfTokenFromCookie() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : null;
}

export function useSkuPreview() {
    const loading = ref(false);
    const error = ref(null);

    async function preview(payload) {
        loading.value = true;
        error.value = null;

        try {
            const response = await fetch('/admin/produtos/sku-preview', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfTokenFromCookie(),
                },
                body: JSON.stringify(payload),
            });

            if (!response.ok) {
                error.value = 'Não foi possível gerar a prévia do SKU agora.';

                return null;
            }

            const data = await response.json();

            return data.sku ?? null;
        } catch {
            error.value = 'Não foi possível gerar a prévia do SKU agora.';

            return null;
        } finally {
            loading.value = false;
        }
    }

    return { loading, error, preview };
}
