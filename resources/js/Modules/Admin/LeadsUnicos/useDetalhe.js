import { reactive, watch } from 'vue';

// Carrega o JSON de uma janela de detalhe da tela única de Leads quando a
// chave da linha muda. A recarga da lista (5 s) não mexe na chave, então a
// janela aberta não pisca nem fecha.
export function useDetalhe(props, caminho) {
    const estado = reactive({ carregando: false, erro: '', dados: null });

    const carregar = async (chave) => {
        Object.assign(estado, { carregando: true, erro: '', dados: null });
        try {
            const r = await fetch(`${props.base}/${chave}/${caminho}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const dados = await r.json().catch(() => ({}));
            if (!r.ok) throw new Error(dados.message || 'Não foi possível carregar.');
            if (props.lead?.chave === chave) estado.dados = dados;
        } catch (e) {
            if (props.lead?.chave === chave) estado.erro = e.message;
        } finally {
            if (props.lead?.chave === chave) estado.carregando = false;
        }
    };

    watch(() => props.lead?.chave, (chave) => { if (chave) carregar(chave); }, { immediate: true });

    return estado;
}
