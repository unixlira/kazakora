// Status ao vivo da Meta (pedido do Lira 2026-10-21): pergunta ao servidor a
// cada 20 s, só com a aba visível, o effective_status de campanhas, conjuntos
// e anúncios e o status das campanhas do KazaKora. O servidor guarda a
// leitura da Meta por 15 s, então várias abas não pesam na cota.
import { onBeforeUnmount, onMounted, reactive } from 'vue';
import { pedirJson } from './metaAds';

const URL = '/admin/publicidade/meta-ads/gerenciar/status';
const INTERVALO = 20000;

export function useStatusAoVivo({ aoMudarLocal } = {}) {
    const estado = reactive({ meta: null, locais: {}, lidoEm: null, erro: null });
    let timer = null;
    let pedindo = false;

    const atualizar = async () => {
        if (pedindo || document.visibilityState === 'hidden') return;
        pedindo = true;
        try {
            const resposta = await pedirJson('GET', URL);
            const antes = { ...estado.locais };
            estado.meta = resposta.meta;
            estado.erro = resposta.erro;
            estado.locais = Object.fromEntries((resposta.locais ?? []).map((l) => [l.id, l]));
            estado.lidoEm = resposta.meta?.lido_em ?? new Date().toISOString();
            const mudou = Object.values(estado.locais).some((l) => antes[l.id] && antes[l.id].status !== l.status);
            if (mudou) aoMudarLocal?.();
        } catch (erro) {
            estado.erro = erro.message;
        } finally {
            pedindo = false;
        }
    };

    const aoVoltar = () => { if (document.visibilityState === 'visible') atualizar(); };

    onMounted(() => {
        atualizar();
        timer = setInterval(atualizar, INTERVALO);
        document.addEventListener('visibilitychange', aoVoltar);
    });
    onBeforeUnmount(() => {
        clearInterval(timer);
        document.removeEventListener('visibilitychange', aoVoltar);
    });

    return { estado, atualizar };
}
