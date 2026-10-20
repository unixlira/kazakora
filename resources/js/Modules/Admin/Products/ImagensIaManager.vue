<script setup>
// "Gerar imagens com IA" (pedido 2026-10-19): de 3 a 5 imagens novas do
// anúncio pelo Gemini, na sequência do Lira (desejo → quebra de objeção →
// CTA). A tela pede o plano do lote e desenha uma imagem por vez; cada uma
// fica como rascunho até "Aprovar", que a coloca no fim da galeria.
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
    temFotos: { type: Boolean, default: false },
    iaImages: { type: Array, default: () => [] },
});

const ETAPAS = {
    desejo: { rotulo: 'Desejo', classe: 'bg-rose-100 text-rose-700' },
    objecao: { rotulo: 'Quebra de objeção', classe: 'bg-amber-100 text-amber-700' },
    cta: { rotulo: 'CTA', classe: 'bg-emerald-100 text-emerald-700' },
};

const imagens = ref([...props.iaImages]);
watch(() => props.iaImages, (lista) => { imagens.value = [...lista]; });
// Ao terminar, a página recarrega a galeria e os rascunhos (a aba pode ser trocada e voltar).
const sincronizar = () => router.reload({ only: ['images', 'iaImages'] });
const quantidade = ref(5);
const planejando = ref(false);
const gerandoId = ref(null);
const ocupadoId = ref(null);
const erro = ref('');

const lotes = computed(() => {
    const porLote = new Map();
    for (const imagem of imagens.value) {
        if (!porLote.has(imagem.lote)) porLote.set(imagem.lote, []);
        porLote.get(imagem.lote).push(imagem);
    }
    return [...porLote.entries()]
        .sort(([a], [b]) => (a < b ? 1 : -1))
        .map(([lote, itens]) => ({ lote, itens: itens.sort((a, b) => a.ordem - b.ordem) }));
});
const ocupado = computed(() => planejando.value || gerandoId.value !== null);

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');
const chamar = async (metodo, url, corpo = {}) => {
    const resposta = await fetch(url, {
        method: metodo,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(corpo),
    });
    const dados = await resposta.json().catch(() => ({}));
    if (dados.imagem) trocar(dados.imagem);
    if (!resposta.ok) throw new Error(dados.message || 'Não deu certo agora. Tente de novo.');
    return dados;
};
const trocar = (imagem) => {
    const indice = imagens.value.findIndex((item) => item.id === imagem.id);
    if (indice >= 0) imagens.value.splice(indice, 1, imagem);
    else imagens.value.push(imagem);
};
const base = `/admin/produtos/${props.product.id}/imagens-ia`;

const gerarUma = async (imagem) => {
    gerandoId.value = imagem.id;
    try {
        await chamar('POST', `${base}/${imagem.id}/gerar`);
        return true;
    } catch (falha) {
        erro.value = falha.message;
        return false;
    } finally {
        gerandoId.value = null;
    }
};
// Uma por vez, na ordem da sequência; para no primeiro erro (cota, etc.).
const gerarPendentes = async (itens) => {
    erro.value = '';
    for (const imagem of itens.filter((item) => item.status === 'pendente' || item.status === 'erro')) {
        if (!(await gerarUma(imagem))) break;
    }
    sincronizar();
};
const planejar = async () => {
    planejando.value = true;
    erro.value = '';
    let novas = [];
    try {
        novas = (await chamar('POST', base, { quantidade: quantidade.value })).imagens;
        novas.forEach(trocar);
    } catch (falha) {
        erro.value = falha.message;
    } finally {
        planejando.value = false;
    }
    if (novas.length) await gerarPendentes(novas);
};
const aprovar = async (imagem) => {
    ocupadoId.value = imagem.id;
    erro.value = '';
    try {
        await chamar('POST', `${base}/${imagem.id}/aprovar`);
        sincronizar();
    } catch (falha) {
        erro.value = falha.message;
    } finally {
        ocupadoId.value = null;
    }
};
const aprovarLote = async (itens) => {
    for (const imagem of itens.filter((item) => item.status === 'pronta')) {
        await aprovar(imagem);
        if (erro.value) break;
    }
};
const descartar = async (imagem) => {
    if (!window.confirm('Descartar esta imagem? O arquivo gerado é apagado.')) return;
    ocupadoId.value = imagem.id;
    erro.value = '';
    try {
        await chamar('DELETE', `${base}/${imagem.id}`);
        imagens.value = imagens.value.filter((item) => item.id !== imagem.id);
        sincronizar();
    } catch (falha) {
        erro.value = falha.message;
    } finally {
        ocupadoId.value = null;
    }
};
</script>

<template>
    <div class="rounded border border-violet-200 bg-violet-50/60 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <label for="ia-quantidade" class="text-sm text-slate-600">Imagens</label>
            <select id="ia-quantidade" v-model.number="quantidade" :disabled="ocupado"
                class="rounded border border-slate-300 py-1 pl-2 pr-8 text-sm text-slate-700">
                <option :value="3">3</option>
                <option :value="4">4</option>
                <option :value="5">5</option>
            </select>
            <button type="button" :disabled="ocupado || !temFotos"
                class="rounded bg-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50"
                @click="planejar">
                <i class="fas mr-1" :class="ocupado ? 'fa-spinner animate-spin' : 'fa-images'"></i>
                {{ planejando ? 'Planejando as cenas...' : (gerandoId ? 'Gerando imagens...' : 'Gerar imagens com IA') }}
            </button>
            <span class="text-xs text-slate-500">
                {{ temFotos
                    ? 'Sequência: desejo, quebra de objeção e CTA no fim. As fotos atuais são a referência. Nada vai para a loja antes de você aprovar.'
                    : 'Cadastre as fotos (aba Fotos e vídeo) para usar.' }}
            </span>
        </div>
        <p v-if="erro" class="mt-3 text-sm text-red-600" role="alert">{{ erro }}</p>

        <div v-for="grupo in lotes" :key="grupo.lote" class="mt-4">
            <div class="mb-2 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase text-slate-500">Lote de {{ new Date(grupo.itens[0].created_at).toLocaleString('pt-BR') }}</span>
                <button v-if="grupo.itens.some((item) => item.status === 'pronta')" type="button" :disabled="ocupado || ocupadoId !== null"
                    class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                    @click="aprovarLote(grupo.itens)">
                    Aprovar todas na ordem
                </button>
                <button v-if="grupo.itens.some((item) => item.status === 'pendente' || item.status === 'erro')" type="button" :disabled="ocupado"
                    class="rounded border border-violet-300 px-2 py-1 text-xs font-semibold text-violet-700 hover:bg-violet-100 disabled:opacity-50"
                    @click="gerarPendentes(grupo.itens)">
                    Gerar as que faltam
                </button>
            </div>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <figure v-for="imagem in grupo.itens" :key="imagem.id" class="flex flex-col rounded border border-slate-200 bg-white p-2">
                    <div class="relative flex aspect-square items-center justify-center overflow-hidden rounded bg-slate-100">
                        <a v-if="imagem.url" :href="imagem.url" target="_blank" rel="noopener" class="block h-full w-full">
                            <img :src="imagem.url" :alt="imagem.titulo" class="h-full w-full object-cover" loading="lazy" />
                        </a>
                        <i v-else-if="gerandoId === imagem.id" class="fas fa-spinner animate-spin text-2xl text-violet-500"></i>
                        <span v-else class="px-2 text-center text-xs text-slate-400">{{ imagem.status === 'erro' ? 'Falhou' : 'Aguardando' }}</span>
                        <span class="absolute left-1 top-1 rounded px-1.5 py-0.5 text-[11px] font-semibold" :class="ETAPAS[imagem.etapa]?.classe">
                            {{ ETAPAS[imagem.etapa]?.rotulo ?? imagem.etapa }}
                        </span>
                    </div>
                    <figcaption class="mt-2 flex-1 text-xs text-slate-600">
                        <strong class="block text-slate-700">{{ imagem.titulo }}</strong>
                        <span class="line-clamp-3" :title="imagem.cena">{{ imagem.cena }}</span>
                        <span v-if="imagem.status === 'erro' && imagem.erro" class="mt-1 block text-red-600">{{ imagem.erro }}</span>
                    </figcaption>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <span v-if="imagem.status === 'aprovada'" class="rounded bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700">Na galeria</span>
                        <template v-else>
                            <button v-if="imagem.status === 'pronta'" type="button" :disabled="ocupado || ocupadoId !== null"
                                class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                                @click="aprovar(imagem)">Aprovar</button>
                            <button type="button" :disabled="ocupado || ocupadoId !== null"
                                class="rounded border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-100 disabled:opacity-50"
                                @click="gerarPendentes([{ ...imagem, status: 'erro' }])">
                                {{ imagem.status === 'pronta' ? 'Refazer' : 'Gerar' }}
                            </button>
                            <button type="button" :disabled="ocupado || ocupadoId !== null"
                                class="rounded border border-red-200 px-2 py-1 text-xs text-red-600 hover:bg-red-50 disabled:opacity-50"
                                @click="descartar(imagem)">Descartar</button>
                        </template>
                    </div>
                </figure>
            </div>
        </div>
    </div>
</template>
