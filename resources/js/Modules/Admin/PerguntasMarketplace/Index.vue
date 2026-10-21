<script setup>
// Atendimento > Perguntas MK Place (pedido do Lira 2026-10-20): perguntas dos
// anúncios de todos os marketplaces. Lista à esquerda (com o ícone do canal),
// pergunta e rascunho da Manuela à direita, onde o atendente edita e envia
// pela API. A Manuela atende primeiro; o que ela não sabe fica em "Pra você",
// com alerta no topo do admin.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { notifyError, notifySuccess } from '@/Shared/notify';
import { canalPergunta } from '@/Shared/canaisPerguntas';

const props = defineProps({
    perguntas: { type: Array, required: true },
    filtro: { type: String, default: 'humano' },
    busca: { type: String, default: '' },
    canal: { type: String, default: null },
    canais: { type: Array, default: () => ['mercado_livre'] },
    contagem: { type: Object, default: () => ({}) },
    autoResposta: { type: Boolean, default: false },
    podeConfigurar: { type: Boolean, default: false },
});

const FILTROS = [
    ['humano', 'Pra você'],
    ['rascunho', 'Rascunho da Manuela'],
    ['abertas', 'Sem resposta'],
    ['respondidas', 'Respondidas'],
    ['todas', 'Todas'],
];
const POR = { manuela: 'Manuela', humano: 'Atendente', ml: 'Direto no marketplace' };
const LIMITE = 2000;

const termo = ref(props.busca);
const recarregar = () => router.reload({ only: ['perguntas', 'contagem', 'canais', 'autoResposta', 'sidebarBadges'], preserveScroll: true });
const filtrar = (filtro, canal = props.canal) => router.get('/admin/perguntas-marketplace', { filtro, canal: canal || undefined, busca: termo.value || undefined }, { preserveState: true, preserveScroll: true, only: ['perguntas', 'filtro', 'busca', 'canal', 'canais', 'contagem'] });

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' }) : '');

// Pergunta aberta no painel da direita. Começa pela do link (?pergunta=, vindo
// do aviso do canto) ou pela primeira da lista; se sumir da lista (respondida),
// passa para a primeira.
const inicial = Number(new URLSearchParams(window.location.search).get('pergunta')) || null;
const selecionadaId = ref(inicial);
const selecionada = computed(() => props.perguntas.find((p) => p.id === selecionadaId.value) ?? null);
watch(() => props.perguntas, (lista) => {
    if (!lista.some((p) => p.id === selecionadaId.value)) selecionadaId.value = lista[0]?.id ?? null;
}, { immediate: true });

const painel = ref(null);
const abrir = async (p) => {
    selecionadaId.value = p.id;
    // No celular a lista fica em cima: desce até a pergunta.
    if (window.matchMedia('(max-width: 1023px)').matches) {
        await nextTick();
        painel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
};

// Avatar do cliente: iniciais do nome (o marketplace não entrega foto) numa cor
// fixa por cliente, para reconhecer quem volta a perguntar.
const CORES_AVATAR = ['bg-sky-500', 'bg-violet-500', 'bg-emerald-500', 'bg-amber-500', 'bg-rose-500', 'bg-teal-500', 'bg-indigo-500', 'bg-orange-500'];
const iniciais = (p) => {
    const nome = (p.comprador || '').replace(/[^\p{L}\p{N} ._-]/gu, '').trim();
    if (!nome) return '?';
    const partes = nome.split(/[ ._-]+/).filter(Boolean);
    return (partes.length > 1 ? partes[0][0] + partes[1][0] : nome.slice(0, 2)).toUpperCase();
};
const corAvatar = (p) => {
    const chave = p.comprador || String(p.id);
    let h = 0;
    for (const c of chave) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return p.comprador ? CORES_AVATAR[h % CORES_AVATAR.length] : 'bg-slate-400';
};

const estado = (p) => {
    if (!p.aberta) return { rotulo: 'Respondida', ponto: 'bg-emerald-500' };
    if (p.assumida_por) return { rotulo: `Com ${p.assumida_por}`, ponto: 'bg-violet-500' };
    if (p.precisa_humano) return { rotulo: 'Pra você', ponto: 'bg-red-500' };
    if (p.rascunho) return { rotulo: 'Rascunho pronto', ponto: 'bg-sky-500' };
    return { rotulo: 'Manuela escrevendo', ponto: 'bg-slate-400' };
};

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const chamar = async (url, corpo = {}) => {
    const r = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(corpo),
    });
    const dados = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(dados.message || 'Não foi possível concluir.');
    return dados;
};

// Texto que o atendente está editando, por pergunta (começa no rascunho da Manuela).
const textos = reactive({});
const texto = (p) => (textos[p.id] ?? p.rascunho ?? '');
const ocupado = ref(null);

const executar = async (p, fn, sucesso) => {
    ocupado.value = p.id;
    try {
        await fn();
        notifySuccess(sucesso);
        delete textos[p.id];
        recarregar();
    } catch (e) {
        notifyError(e.message);
    } finally {
        ocupado.value = null;
    }
};

const enviar = (p) => {
    const msg = texto(p).trim();
    if (!msg) return notifyError('Escreva a resposta antes de enviar.');
    const nome = canalPergunta(p.canal).nome;
    // Resposta de pergunta é pública e não pode ser editada nem apagada.
    if (!confirm(`Publicar esta resposta no anúncio do ${nome}? Ela fica pública e não dá para editar depois.\n\n${msg}`)) return;
    executar(p, () => chamar(`/admin/perguntas-marketplace/${p.id}/enviar`, { texto: msg }), `Resposta publicada no ${nome}.`);
};
const assumir = (p) => executar(p, () => chamar(`/admin/perguntas-marketplace/${p.id}/assumir`), 'Você assumiu a pergunta. A Manuela não envia nada nela.');
const devolver = (p) => executar(p, () => chamar(`/admin/perguntas-marketplace/${p.id}/devolver`), 'Devolvida para a Manuela. O rascunho novo chega em instantes.');

const auto = ref(props.autoResposta);
const alternarAuto = async () => {
    const ligar = !auto.value;
    if (ligar && !confirm('Ligar "Manuela responde sozinha"? As respostas que ela souber pela ficha do anúncio vão direto para o comprador, sem revisão. O que ela não souber continua vindo para você.')) return;
    try {
        auto.value = (await chamar('/admin/perguntas-marketplace/auto-resposta', { ligado: ligar })).ligado;
        notifySuccess(auto.value ? 'A Manuela agora responde sozinha.' : 'A Manuela só deixa rascunho. Você revisa e envia.');
    } catch (e) {
        notifyError(e.message);
    }
};

let timer = null;
onMounted(() => { timer = setInterval(recarregar, 30000); });
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head title="Perguntas MK Place" />

    <AdminLayout>
        <div class="min-w-0 space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-lg text-primary"><i class="fas fa-comment-dots"></i></span>
                    <div>
                        <h1 class="font-display text-2xl font-semibold">Perguntas MK Place</h1>
                        <p class="text-sm text-slate-500">Perguntas dos anúncios nos marketplaces. A Manuela atende primeiro; o que ela não souber fica em "Pra você".</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-2 shadow-sm">
                    <div class="text-sm">
                        <p class="font-semibold">Manuela responde sozinha</p>
                        <p class="text-xs text-slate-500">{{ auto ? 'Ligado: o que está na ficha vai direto ao comprador.' : 'Desligado: ela deixa rascunho e você envia.' }}</p>
                    </div>
                    <button type="button" role="switch" :aria-checked="auto" :disabled="!podeConfigurar"
                        :title="podeConfigurar ? '' : 'Só o administrador muda essa opção'"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="auto ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600'" @click="alternarAuto">
                        <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform" :class="auto ? 'translate-x-5' : 'translate-x-0.5'"></span>
                    </button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button v-for="[k, rotulo] in FILTROS" :key="k" type="button"
                    class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold"
                    :class="filtro === k ? (k === 'humano' ? 'bg-red-500 text-white' : 'bg-primary text-white') : 'bg-[var(--surface-muted)] text-slate-600 dark:text-slate-300'"
                    @click="filtrar(k)">
                    {{ rotulo }} <span class="opacity-75">{{ contagem[k] ?? 0 }}</span>
                </button>
                <form class="relative ml-auto w-full sm:w-72" @submit.prevent="filtrar(filtro)">
                    <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input v-model="termo" type="search" placeholder="Buscar pergunta ou código do anúncio" class="w-full rounded-full border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-8 pr-3 text-sm">
                </form>
            </div>

            <!-- Filtro por marketplace (só aparecem os canais que já têm pergunta) -->
            <div v-if="canais.length > 1" class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" class="rounded-full px-3 py-1.5 font-semibold"
                    :class="!canal ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-[var(--surface-muted)] text-slate-600 dark:text-slate-300'"
                    @click="filtrar(filtro, null)">Todos os marketplaces</button>
                <button v-for="c in canais" :key="c" type="button" class="flex items-center gap-1.5 rounded-full px-3 py-1.5 font-semibold"
                    :class="canal === c ? 'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900' : 'bg-[var(--surface-muted)] text-slate-600 dark:text-slate-300'"
                    @click="filtrar(filtro, c)">
                    <span class="inline-flex h-4 w-4 items-center justify-center rounded text-[9px]" :class="canalPergunta(c).fundo"><i :class="canalPergunta(c).icone"></i></span>
                    {{ canalPergunta(c).nome }}
                </button>
            </div>

            <p v-if="!perguntas.length" class="rounded-2xl border border-dashed border-[var(--surface-border)] p-10 text-center text-sm text-slate-500">
                {{ filtro === 'humano' ? 'Nenhuma pergunta esperando você.' : 'Nenhuma pergunta aqui.' }}
            </p>

            <div v-else class="grid gap-4 lg:grid-cols-[minmax(300px,380px)_1fr]">
                <!-- Lista (esquerda) -->
                <aside class="overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <p class="border-b border-[var(--surface-border)] px-4 py-2 text-xs font-semibold text-slate-500">{{ perguntas.length }} {{ perguntas.length === 1 ? 'pergunta' : 'perguntas' }}</p>
                    <ul class="max-h-[60vh] divide-y divide-[var(--surface-border)] overflow-y-auto overscroll-contain lg:max-h-[calc(100vh-300px)]">
                        <!-- Cada cliente: avatar com o ícone do marketplace, nome e, embaixo,
                             o produto (clicar no produto abre a pergunta no painel do marketplace). -->
                        <li v-for="p in perguntas" :key="p.id">
                            <div role="button" tabindex="0" class="flex w-full cursor-pointer gap-3 px-4 py-3 text-left transition-colors"
                                :class="p.id === selecionadaId ? 'bg-primary/10' : 'hover:bg-[var(--surface-muted)]'"
                                :aria-current="p.id === selecionadaId ? 'true' : undefined"
                                @click="abrir(p)" @keydown.enter.prevent="abrir(p)" @keydown.space.prevent="abrir(p)">
                                <span class="relative mt-0.5 h-10 w-10 shrink-0">
                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white" :class="corAvatar(p)">{{ iniciais(p) }}</span>
                                    <span class="absolute -bottom-1 -right-1 inline-flex h-5 w-5 items-center justify-center rounded-full text-[9px] ring-2 ring-[var(--surface)]" :class="canalPergunta(p.canal).fundo" :title="canalPergunta(p.canal).nome">
                                        <i :class="canalPergunta(p.canal).icone"></i>
                                    </span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate text-sm font-semibold" :class="p.comprador ? '' : 'text-slate-500'">{{ p.comprador || `Cliente ${canalPergunta(p.canal).nome}` }}</span>
                                        <span class="ml-auto shrink-0 text-[11px] text-slate-400">{{ dataHora(p.perguntada_em) }}</span>
                                    </span>
                                    <a v-if="p.link_pergunta" :href="p.link_pergunta" target="_blank" rel="noopener"
                                        class="block truncate text-[11px] font-medium text-primary hover:underline"
                                        :title="`Abrir a pergunta no ${canalPergunta(p.canal).nome}`" @click.stop>{{ p.produto || p.item_id }} <i class="fas fa-arrow-up-right-from-square ml-0.5 text-[9px]"></i></a>
                                    <span v-else class="block truncate text-[11px] text-slate-500">{{ p.produto || p.item_id }}</span>
                                    <span class="mt-0.5 line-clamp-2 text-sm" :class="p.aberta ? 'font-medium' : 'text-slate-500'">{{ p.pergunta }}</span>
                                    <span class="mt-1 flex items-center gap-1.5 text-[11px] text-slate-500">
                                        <span class="h-2 w-2 rounded-full" :class="estado(p).ponto"></span>{{ estado(p).rotulo }}
                                    </span>
                                </span>
                            </div>
                        </li>
                    </ul>
                </aside>

                <!-- Pergunta aberta (direita) -->
                <section ref="painel" class="min-w-0 scroll-mt-4">
                    <article v-if="selecionada" :key="selecionada.id"
                        class="rounded-2xl border bg-[var(--surface)] p-5 shadow-sm lg:sticky lg:top-4"
                        :class="selecionada.aberta && (selecionada.precisa_humano || selecionada.assumida_por) ? 'border-red-300 dark:border-red-500/50' : 'border-[var(--surface-border)]'">
                        <header class="flex flex-wrap items-center gap-3">
                            <img v-if="canalPergunta(selecionada.canal).logo" :src="canalPergunta(selecionada.canal).logo" :alt="canalPergunta(selecionada.canal).nome" class="h-7 w-auto">
                            <span v-else class="rounded-full px-2 py-0.5 text-xs font-bold" :class="canalPergunta(selecionada.canal).fundo">{{ canalPergunta(selecionada.canal).nome }}</span>
                            <div class="min-w-0 flex-1">
                                <a v-if="selecionada.link" :href="selecionada.link" target="_blank" rel="noopener" class="block truncate font-semibold text-primary hover:underline">{{ selecionada.produto || selecionada.item_id }} <i class="fas fa-arrow-up-right-from-square ml-1 text-[10px]"></i></a>
                                <p v-else class="truncate font-semibold">{{ selecionada.produto || selecionada.item_id }}</p>
                                <p class="text-xs text-slate-400">{{ selecionada.item_id }} · {{ dataHora(selecionada.perguntada_em) }}</p>
                            </div>
                        </header>

                        <div class="mt-4 rounded-xl bg-[var(--surface-muted)] p-4">
                            <p class="flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                                <span><i class="fas fa-user mr-1.5"></i>Pergunta de {{ selecionada.comprador || 'cliente sem nome' }}</span>
                                <a v-if="selecionada.link_pergunta" :href="selecionada.link_pergunta" target="_blank" rel="noopener" class="ml-auto font-semibold text-primary hover:underline">Responder no {{ canalPergunta(selecionada.canal).nome }} <i class="fas fa-arrow-up-right-from-square ml-0.5 text-[10px]"></i></a>
                            </p>
                            <p class="mt-1 whitespace-pre-line text-[15px] font-medium">{{ selecionada.pergunta }}</p>
                        </div>

                        <!-- Aberta: estado do atendimento + rascunho editável -->
                        <template v-if="selecionada.aberta">
                            <div class="mt-4 flex flex-wrap items-center gap-2 text-xs">
                                <span v-if="selecionada.assumida_por" class="rounded-full bg-violet-100 px-2 py-0.5 font-semibold text-violet-700 dark:bg-violet-500/15 dark:text-violet-300"><i class="fas fa-user-tie mr-1"></i>Com {{ selecionada.assumida_por }}</span>
                                <span v-else-if="selecionada.precisa_humano" class="rounded-full bg-red-100 px-2 py-0.5 font-semibold text-red-700 dark:bg-red-500/15 dark:text-red-300"><i class="fas fa-triangle-exclamation mr-1"></i>A Manuela não soube responder</span>
                                <span v-else-if="selecionada.rascunho" class="rounded-full bg-sky-100 px-2 py-0.5 font-semibold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300"><i class="fas fa-robot mr-1"></i>Rascunho da Manuela</span>
                                <span v-else class="rounded-full bg-slate-200 px-2 py-0.5 font-semibold text-slate-600 dark:bg-slate-500/15 dark:text-slate-300"><i class="fas fa-circle-notch mr-1 animate-spin"></i>Manuela escrevendo</span>
                            </div>

                            <p v-if="selecionada.erro_envio" class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-300">Último envio falhou: {{ selecionada.erro_envio }}</p>
                            <p v-if="!selecionada.pode_enviar" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">O {{ canalPergunta(selecionada.canal).nome }} ainda não aceita resposta pela API. Copie o texto e responda no painel dele.</p>

                            <label class="mt-3 block text-xs font-semibold text-slate-500" :for="`resposta-${selecionada.id}`">Resposta</label>
                            <textarea :id="`resposta-${selecionada.id}`" :value="texto(selecionada)" rows="6" :maxlength="LIMITE"
                                class="mt-1 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)] p-3 text-sm"
                                placeholder="Escreva a resposta. Sem telefone, e-mail, link ou contato fora do marketplace."
                                @input="textos[selecionada.id] = $event.target.value"></textarea>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="text-xs text-slate-400">{{ texto(selecionada).length }}/{{ LIMITE }}</span>
                                <div class="ml-auto flex flex-wrap gap-2">
                                    <button v-if="!selecionada.assumida_por" type="button" :disabled="ocupado === selecionada.id"
                                        class="rounded-lg border border-violet-300 px-3 py-1.5 text-xs font-semibold text-violet-700 hover:bg-violet-50 disabled:opacity-50 dark:text-violet-300 dark:hover:bg-violet-500/10"
                                        @click="assumir(selecionada)"><i class="fas fa-hand mr-1"></i>Assumir</button>
                                    <button type="button" :disabled="ocupado === selecionada.id"
                                        class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-[var(--surface-muted)] disabled:opacity-50 dark:text-slate-300"
                                        @click="devolver(selecionada)"><i class="fas fa-robot mr-1"></i>Devolver para a Manuela</button>
                                    <button type="button" :disabled="ocupado === selecionada.id || !texto(selecionada).trim() || !selecionada.pode_enviar"
                                        class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white hover:brightness-95 disabled:opacity-50"
                                        @click="enviar(selecionada)">
                                        <i class="fas mr-1" :class="ocupado === selecionada.id ? 'fa-circle-notch animate-spin' : 'fa-paper-plane'"></i>Enviar resposta
                                    </button>
                                </div>
                            </div>
                        </template>

                        <!-- Respondida -->
                        <div v-else class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm dark:bg-emerald-500/10">
                            <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-300"><i class="fas fa-reply mr-1.5"></i>Resposta publicada</p>
                            <p class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ selecionada.resposta || 'Respondida no marketplace.' }}</p>
                            <p class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">
                                <i class="fas fa-check mr-1"></i>{{ POR[selecionada.respondida_por] ?? 'Respondida' }}<template v-if="selecionada.respondida_por_nome"> ({{ selecionada.respondida_por_nome }})</template> · {{ dataHora(selecionada.respondida_em) }}
                            </p>
                        </div>
                    </article>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
