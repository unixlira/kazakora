import { ref } from 'vue';

export function maskCep(value) {
    return (value ?? '')
        .replace(/\D/g, '')
        .slice(0, 8)
        .replace(/^(\d{5})(\d)/, '$1-$2');
}

export function useCep() {
    const loading = ref(false);
    const error = ref(null);

    async function lookup(cep) {
        const digits = (cep ?? '').replace(/\D/g, '');

        if (digits.length !== 8) {
            return null;
        }

        loading.value = true;
        error.value = null;

        try {
            const response = await fetch(`https://viacep.com.br/ws/${digits}/json/`);
            const data = await response.json();

            if (!response.ok || data.erro) {
                error.value = 'CEP não encontrado.';
                return null;
            }

            return {
                street: data.logradouro ?? '',
                neighborhood: data.bairro ?? '',
                city: data.localidade ?? '',
                state: data.uf ?? '',
            };
        } catch {
            error.value = 'Não foi possível buscar o CEP agora.';
            return null;
        } finally {
            loading.value = false;
        }
    }

    return { loading, error, lookup };
}
