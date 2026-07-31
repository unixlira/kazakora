import { ref } from 'vue';

function readXsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : null;
}

export function useFreightQuote() {
    const loading = ref(false);
    const error = ref(null);

    async function quote(zip) {
        const digits = (zip ?? '').replace(/\D/g, '');

        if (digits.length !== 8) {
            return [];
        }

        loading.value = true;
        error.value = null;

        try {
            const response = await fetch('/finalizacao/frete', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': readXsrfToken() ?? '',
                },
                body: JSON.stringify({ zip }),
            });

            if (!response.ok) {
                error.value = 'Não foi possível calcular o frete agora.';
                return [];
            }

            const data = await response.json();

            return data.quotes ?? [];
        } catch {
            error.value = 'Não foi possível calcular o frete agora.';
            return [];
        } finally {
            loading.value = false;
        }
    }

    return { loading, error, quote };
}
