<script setup>
// E-mails do site (pedido 2026-10-10): mensagens do "Fale conosco" da loja,
// no jeito da caixa do WhatsApp — lista à esquerda, mensagem à direita.
// Abrir marca como lida; dá pra voltar pra não lida.
// Excluir (pedido 2026-10-12) manda para a Lixeira, de onde dá pra restaurar.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

const props = defineProps({
    mensagens: { type: Object, required: true },
    filtro: { type: String, default: 'todas' },
    busca: { type: String, default: '' },
    naoLidas: { type: Number, default: 0 },
    lidas: { type: Number, default: 0 },
    naLixeira: { type: Number, default: 0 },
});

const naLixeiraAgora = computed(() => props.filtro === 'lixeira');

const lista = computed(() => props.mensagens.data ?? []);
const selecionadaId = ref(Number(new URLSearchParams(window.location.search).get('m')) || null);
const selecionada = computed(() => lista.value.find((m) => m.id === selecionadaId.value) ?? null);

const termo = ref(props.busca);
let atraso = null;
watch(termo, (valor) => {
    clearTimeout(atraso);
    atraso = setTimeout(() => filtrar({ busca: valor || undefined }), 350);
});

const filtrar = (extra) => router.get('/admin/mensagens-site', {
    filtro: props.filtro !== 'todas' ? props.filtro : undefined,
    busca: termo.value || undefined,
    ...extra,
}, { preserveState: true, preserveScroll: true, replace: true });

const trocarFiltro = (filtro) => {
    fecharMensagem();
    marcadas.value = [];
    filtrar({ filtro, page: undefined });
};

const fecharMensagem = () => {
    selecionadaId.value = null;
    const url = new URL(window.location.href);
    url.searchParams.delete('m');
    window.history.replaceState(window.history.state, '', url);
};

const abrir = (mensagem) => {
    selecionadaId.value = mensagem.id;
    const url = new URL(window.location.href);
    url.searchParams.set('m', mensagem.id);
    window.history.replaceState(window.history.state, '', url);
    if (!mensagem.lida && !naLixeiraAgora.value) {
        router.post(`/admin/mensagens-site/${mensagem.id}/lida`, {}, { preserveState: true, preserveScroll: true });
    }
};

const marcarNaoLida = (mensagem) => router.post(`/admin/mensagens-site/${mensagem.id}/nao-lida`, {}, { preserveState: true, preserveScroll: true });

// Seleção múltipla
const marcadas = ref([]);
watch(lista, (atual) => {
    const ids = new Set(atual.map((m) => m.id));
    marcadas.value = marcadas.value.filter((id) => ids.has(id));
});
const todasMarcadas = computed(() => lista.value.length > 0 && marcadas.value.length === lista.value.length);
const alternarTodas = () => {
    marcadas.value = todasMarcadas.value ? [] : lista.value.map((m) => m.id);
};
const plural = (n, um, varios) => (n === 1 ? `1 ${um}` : `${n} ${varios}`);
const vaiParaLixeira = (n) => (n === 1 ? 'Ela vai para a Lixeira e dá para restaurar.' : 'Elas vão para a Lixeira e dá para restaurar.');
const processando = ref(false);
const opcoes = (aoTerminar) => ({
    preserveState: true,
    preserveScroll: true,
    onStart: () => { processando.value = true; },
    onFinish: () => { processando.value = false; },
    onSuccess: aoTerminar,
});

const excluir = (mensagem) => {
    if (!window.confirm(`Excluir a mensagem de ${mensagem.nome} ("${mensagem.assunto}")? Ela vai para a Lixeira e dá para restaurar.`)) return;
    router.delete(`/admin/mensagens-site/${mensagem.id}`, opcoes(() => {
        if (selecionadaId.value === mensagem.id) fecharMensagem();
    }));
};

const excluirSelecionadas = () => {
    const ids = [...marcadas.value];
    if (!ids.length) return;
    if (!window.confirm(`Excluir ${plural(ids.length, 'mensagem selecionada', 'mensagens selecionadas')}? ${vaiParaLixeira(ids.length)}`)) return;
    router.delete('/admin/mensagens-site', { ...opcoes(() => {
        if (ids.includes(selecionadaId.value)) fecharMensagem();
        marcadas.value = [];
    }), data: { ids } });
};

const excluirLidas = () => {
    if (!props.lidas) return;
    const quantas = props.lidas === 1 ? 'a 1 mensagem lida' : `todas as ${props.lidas} mensagens lidas`;
    if (!window.confirm(`Excluir ${quantas}? ${vaiParaLixeira(props.lidas)}`)) return;
    router.delete('/admin/mensagens-site/lidas', opcoes(() => {
        if (selecionada.value?.lida) fecharMensagem();
        marcadas.value = [];
    }));
};

const restaurar = (ids) => {
    if (!ids.length) return;
    router.post('/admin/mensagens-site/restaurar', { ids }, opcoes(() => {
        if (ids.includes(selecionadaId.value)) fecharMensagem();
        marcadas.value = [];
    }));
};

onMounted(() => {
    if (selecionada.value) abrir(selecionada.value);
});

const quando = (iso) => {
    if (!iso) return '';
    const data = new Date(iso);
    const hoje = new Date();
    return data.toDateString() === hoje.toDateString()
        ? data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
        : data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
};
const quandoCompleto = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'long', timeStyle: 'short' }) : '');

const iniciais = (nome) => {
    const partes = String(nome ?? '').trim().split(/\s+/).filter(Boolean);
    return ((partes[0]?.[0] ?? '') + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase() || '?';
};
const CORES = ['#f27a2a', '#53bdeb', '#a78bfa', '#f472b6', '#34d399', '#fbbf24', '#60a5fa', '#ff8a65'];
const cor = (mensagem) => CORES[mensagem.id % CORES.length];

const linkResposta = (m) => `mailto:${m.email}?subject=${encodeURIComponent('Re: ' + m.assunto)}`;
const linkWhats = (m) => {
    const digitos = String(m.telefone ?? '').replace(/\D/g, '');
    if (digitos.length < 10) return null;
    return `https://wa.me/${digitos.startsWith('55') ? digitos : '55' + digitos}`;
};
</script>

<template>
    <Head title="E-mails do site" />

    <AdminLayout>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">E-mails do site</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Mensagens enviadas pelo "Fale conosco" da loja. Cada uma também chega no seu e-mail.</p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <button type="button" class="rounded-full px-3 py-1.5 font-medium"
                    :class="filtro === 'todas' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-[var(--surface-border)]'"
                    @click="trocarFiltro(undefined)">Todas</button>
                <button type="button" class="rounded-full px-3 py-1.5 font-medium"
                    :class="filtro === 'nao-lidas' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-[var(--surface-border)]'"
                    @click="trocarFiltro('nao-lidas')">
                    Não lidas <span v-if="naoLidas" class="ml-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ naoLidas }}</span>
                </button>
                <button type="button" class="rounded-full px-3 py-1.5 font-medium"
                    :class="filtro === 'lixeira' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-[var(--surface-border)]'"
                    @click="trocarFiltro('lixeira')">
                    <i class="fas fa-trash-can mr-1 text-xs"></i> Lixeira <span v-if="naLixeira" class="ml-1 text-xs text-slate-400">{{ naLixeira }}</span>
                </button>
            </div>
        </div>

        <div class="grid min-h-[60vh] grid-cols-1 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm lg:grid-cols-[380px_1fr]">
            <!-- Lista -->
            <div class="flex min-h-0 flex-col border-b border-[var(--surface-border)] lg:border-b-0 lg:border-r" :class="{ 'hidden lg:flex': selecionada }">
                <div class="border-b border-[var(--surface-border)] p-3">
                    <div class="relative">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input v-model="termo" type="search" placeholder="Buscar por nome, e-mail ou assunto"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent py-2 pl-8 pr-3 text-sm">
                    </div>
                    <div v-if="lista.length || (!naLixeiraAgora && lidas)" class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <label v-if="lista.length" class="mr-auto inline-flex cursor-pointer items-center gap-2 font-medium text-slate-600 dark:text-slate-300">
                            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 accent-[#f27a2a]" :checked="todasMarcadas" @change="alternarTodas">
                            {{ marcadas.length ? `${marcadas.length} selecionada${marcadas.length === 1 ? '' : 's'}` : 'Selecionar todas' }}
                        </label>
                        <template v-if="naLixeiraAgora">
                            <button v-if="marcadas.length" type="button" :disabled="processando"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-2.5 py-1.5 font-medium disabled:opacity-50"
                                @click="restaurar([...marcadas])">
                                <i class="fas fa-rotate-left"></i> Restaurar selecionadas
                            </button>
                        </template>
                        <template v-else>
                            <button v-if="marcadas.length" type="button" :disabled="processando"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-2.5 py-1.5 font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10"
                                @click="excluirSelecionadas">
                                <i class="fas fa-trash-can"></i> Excluir selecionadas
                            </button>
                            <button v-if="lidas" type="button" :disabled="processando"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-2.5 py-1.5 font-medium text-slate-600 hover:text-red-600 disabled:opacity-50 dark:text-slate-300"
                                @click="excluirLidas">
                                <i class="fas fa-envelope-open"></i> Excluir todas as lidas ({{ lidas }})
                            </button>
                        </template>
                    </div>
                </div>

                <p v-if="!lista.length" class="p-8 text-center text-sm text-slate-500">
                    <i class="far fa-envelope-open mb-2 block text-3xl opacity-50"></i>
                    {{ filtro === 'nao-lidas' ? 'Nenhuma mensagem não lida.' : filtro === 'lixeira' ? 'A lixeira está vazia.' : 'Nenhuma mensagem por aqui ainda.' }}
                </p>

                <div class="max-h-[70vh] flex-1 overflow-y-auto">
                    <div v-for="m in lista" :key="m.id"
                        class="group flex items-stretch border-b border-[var(--surface-border)] transition hover:bg-slate-50 dark:hover:bg-slate-800/60"
                        :class="{ 'bg-orange-50 dark:bg-orange-500/10': m.id === selecionadaId }">
                        <label class="flex shrink-0 cursor-pointer items-start pl-3 pt-[22px]" :title="`Selecionar mensagem de ${m.nome}`">
                            <input v-model="marcadas" type="checkbox" :value="m.id" class="h-4 w-4 rounded border-slate-300 accent-[#f27a2a]" :aria-label="`Selecionar mensagem de ${m.nome}`">
                        </label>
                        <button type="button"
                            class="flex min-w-0 flex-1 items-start gap-3 py-3 pl-3 pr-2 text-left"
                            @click="abrir(m)">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ backgroundColor: cor(m) }">{{ iniciais(m.nome) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm" :class="m.lida ? 'font-medium' : 'font-bold'">{{ m.nome }}</span>
                                    <span class="shrink-0 text-[11px]" :class="m.lida ? 'text-slate-400' : 'font-semibold text-[#f27a2a]'">{{ quando(m.recebida_em) }}</span>
                                </span>
                                <span class="block truncate text-sm" :class="m.lida ? 'text-slate-600 dark:text-slate-300' : 'font-semibold'">{{ m.assunto }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="line-clamp-1 flex-1 text-xs text-slate-500">{{ m.previa }}</span>
                                    <span v-if="!m.lida" class="h-2.5 w-2.5 shrink-0 rounded-full bg-[#f27a2a]"></span>
                                </span>
                            </span>
                        </button>
                        <button v-if="naLixeiraAgora" type="button" :disabled="processando"
                            class="shrink-0 px-3 text-slate-400 hover:text-slate-700 disabled:opacity-50 dark:hover:text-slate-200"
                            title="Restaurar" aria-label="Restaurar mensagem" @click="restaurar([m.id])">
                            <i class="fas fa-rotate-left"></i>
                        </button>
                        <button v-else type="button" :disabled="processando"
                            class="shrink-0 px-3 text-slate-400 opacity-100 hover:text-red-600 disabled:opacity-50 lg:opacity-0 lg:group-hover:opacity-100 lg:focus:opacity-100"
                            title="Excluir" aria-label="Excluir mensagem" @click="excluir(m)">
                            <i class="fas fa-trash-can"></i>
                        </button>
                    </div>
                </div>

                <div v-if="mensagens.last_page > 1" class="flex items-center justify-between border-t border-[var(--surface-border)] px-4 py-2 text-xs">
                    <Link v-if="mensagens.prev_page_url" :href="mensagens.prev_page_url" preserve-scroll class="font-medium">‹ Anteriores</Link><span v-else></span>
                    <span class="text-slate-400">Página {{ mensagens.current_page }} de {{ mensagens.last_page }}</span>
                    <Link v-if="mensagens.next_page_url" :href="mensagens.next_page_url" preserve-scroll class="font-medium">Mais antigas ›</Link><span v-else></span>
                </div>
            </div>

            <!-- Mensagem aberta -->
            <div class="min-w-0" :class="{ 'hidden lg:block': !selecionada }">
                <div v-if="!selecionada" class="flex h-full min-h-[40vh] flex-col items-center justify-center p-8 text-center text-slate-400">
                    <i class="far fa-envelope text-5xl opacity-40"></i>
                    <p class="mt-3 text-sm">Escolha uma mensagem para ler.</p>
                </div>

                <article v-else class="p-5 md:p-7">
                    <p v-if="naLixeiraAgora" class="mb-4 rounded-lg bg-slate-100 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <i class="fas fa-trash-can mr-1"></i> Esta mensagem está na lixeira desde {{ quandoCompleto(selecionada.excluida_em) }}.
                    </p>
                    <button type="button" class="mb-4 text-sm font-medium text-slate-500 lg:hidden" @click="fecharMensagem">
                        <i class="fas fa-arrow-left mr-1"></i> Voltar
                    </button>
                    <h2 class="text-xl font-bold">{{ selecionada.assunto }}</h2>
                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-full text-sm font-semibold text-white" :style="{ backgroundColor: cor(selecionada) }">{{ iniciais(selecionada.nome) }}</span>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-semibold">{{ selecionada.nome }}</p>
                            <p class="text-slate-500">
                                {{ selecionada.email }}<template v-if="selecionada.telefone"> · {{ selecionada.telefone }}</template>
                            </p>
                        </div>
                        <p class="text-xs text-slate-400">{{ quandoCompleto(selecionada.recebida_em) }}</p>
                    </div>

                    <div class="mt-6 whitespace-pre-line rounded-xl border-l-4 border-[var(--faixa-card)] bg-orange-50/60 p-5 text-[15px] leading-relaxed dark:bg-orange-500/5">{{ selecionada.mensagem }}</div>

                    <div class="mt-6 flex flex-wrap gap-2">
                        <a :href="linkResposta(selecionada)" class="inline-flex items-center gap-2 rounded-lg bg-[#f27a2a] px-4 py-2.5 text-sm font-semibold text-white hover:brightness-95">
                            <i class="fas fa-reply"></i> Responder por e-mail
                        </a>
                        <a v-if="linkWhats(selecionada)" :href="linkWhats(selecionada)" target="_blank" rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg bg-[#25D366] px-4 py-2.5 text-sm font-semibold text-white hover:brightness-95">
                            <i class="fab fa-whatsapp"></i> Chamar no WhatsApp
                        </a>
                        <button v-if="selecionada.lida && !naLixeiraAgora" type="button" class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2.5 text-sm font-medium" @click="marcarNaoLida(selecionada)">
                            <i class="fas fa-envelope"></i> Marcar como não lida
                        </button>
                        <button v-if="naLixeiraAgora" type="button" :disabled="processando"
                            class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2.5 text-sm font-medium disabled:opacity-50"
                            @click="restaurar([selecionada.id])">
                            <i class="fas fa-rotate-left"></i> Restaurar
                        </button>
                        <button v-else type="button" :disabled="processando"
                            class="inline-flex items-center gap-2 rounded-lg border border-red-200 px-4 py-2.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-500/40 dark:text-red-400 dark:hover:bg-red-500/10"
                            @click="excluir(selecionada)">
                            <i class="fas fa-trash-can"></i> Excluir
                        </button>
                    </div>
                </article>
            </div>
        </div>
    </AdminLayout>
</template>
