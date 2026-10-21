<script setup>
// Perguntas Mercado Livre (pedido 2026-10-20): perguntas dos anúncios que
// chegam pelo webhook, com o rascunho da Manuela. Lista à esquerda, pergunta
// à direita, no jeito dos E-mails do site. O Lira revisa, edita se quiser e
// envia; a resposta fica pública no anúncio e não dá para editar depois.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    perguntas: { type: Object, required: true },
    filtro: { type: String, default: 'pendentes' },
    busca: { type: String, default: '' },
    pendentes: { type: Number, default: 0 },
});

const LIMITE = 2000;

const lista = computed(() => props.perguntas.data ?? []);
const selecionadaId = ref(Number(new URLSearchParams(window.location.search).get('p')) || null);
const selecionada = computed(() => lista.value.find((p) => p.id === selecionadaId.value) ?? null);

const texto = ref('');
watch(selecionada, (atual, anterior) => {
    if (atual?.id !== anterior?.id) texto.value = atual?.resposta_rascunho ?? '';
}, { immediate: true });

const termo = ref(props.busca);
let atraso = null;
watch(termo, (valor) => {
    clearTimeout(atraso);
    atraso = setTimeout(() => filtrar({ busca: valor || undefined }), 350);
});

const filtrar = (extra) => router.get('/admin/perguntas-mercado-livre', {
    filtro: props.filtro !== 'pendentes' ? props.filtro : undefined,
    busca: termo.value || undefined,
    ...extra,
}, { preserveState: true, preserveScroll: true, replace: true });

const trocarFiltro = (filtro) => {
    fechar();
    filtrar({ filtro, page: undefined });
};

const fechar = () => {
    selecionadaId.value = null;
    const url = new URL(window.location.href);
    url.searchParams.delete('p');
    window.history.replaceState(window.history.state, '', url);
};

const abrir = (pergunta) => {
    selecionadaId.value = pergunta.id;
    const url = new URL(window.location.href);
    url.searchParams.set('p', pergunta.id);
    window.history.replaceState(window.history.state, '', url);
};

const pendente = (p) => p.status === 'UNANSWERED' && !p.respondida_em;

const situacao = (p) => {
    if (p.respondida_em) return { texto: 'Respondida', classe: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' };
    if (p.status !== 'UNANSWERED') return { texto: 'Fechada no ML', classe: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' };
    if (p.resposta_rascunho) return { texto: 'Rascunho pronto', classe: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' };
    if (p.precisa_humano) return { texto: 'Para você responder', classe: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' };
    return { texto: 'Manuela escrevendo', classe: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' };
};

const enviando = ref(false);
const podeEnviar = computed(() => !enviando.value && texto.value.trim().length > 0 && texto.value.length <= LIMITE);

const enviar = async () => {
    const p = selecionada.value;
    if (!p || !podeEnviar.value) return;
    const ok = await confirmDelete({
        icon: 'question',
        title: 'Enviar esta resposta?',
        text: `"${texto.value.trim()}" vai aparecer pública no anúncio e não dá para editar depois.`,
        confirmButtonText: 'Enviar ao Mercado Livre',
        confirmButtonColor: '#f27a2a',
        focusCancel: true,
    });
    if (!ok) return;
    router.post(`/admin/perguntas-mercado-livre/${p.id}/enviar`, { texto: texto.value }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { enviando.value = true; },
        onFinish: () => { enviando.value = false; },
    });
};

const quando = (iso) => {
    if (!iso) return '';
    const data = new Date(iso);
    const hoje = new Date();
    return data.toDateString() === hoje.toDateString()
        ? data.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
        : data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
};
const quandoCompleto = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'long', timeStyle: 'short' }) : '');
</script>

<template>
    <Head title="Perguntas Mercado Livre" />

    <AdminLayout>
        <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Perguntas Mercado Livre</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Perguntas dos anúncios com o rascunho da Manuela. Nada vai ao comprador sem você enviar.</p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <button type="button" class="rounded-full px-3 py-1.5 font-medium"
                    :class="filtro === 'pendentes' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-[var(--surface-border)]'"
                    @click="trocarFiltro(undefined)">
                    Sem resposta <span v-if="pendentes" class="ml-1 rounded-full bg-red-600 px-1.5 text-xs text-white">{{ pendentes }}</span>
                </button>
                <button type="button" class="rounded-full px-3 py-1.5 font-medium"
                    :class="filtro === 'respondidas' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-[var(--surface-border)]'"
                    @click="trocarFiltro('respondidas')">Respondidas</button>
                <button type="button" class="rounded-full px-3 py-1.5 font-medium"
                    :class="filtro === 'todas' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'border border-[var(--surface-border)]'"
                    @click="trocarFiltro('todas')">Todas</button>
            </div>
        </div>

        <div class="grid min-h-[60vh] grid-cols-1 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm lg:grid-cols-[380px_1fr]">
            <!-- Lista -->
            <div class="flex min-h-0 flex-col border-b border-[var(--surface-border)] lg:border-b-0 lg:border-r" :class="{ 'hidden lg:flex': selecionada }">
                <div class="border-b border-[var(--surface-border)] p-3">
                    <div class="relative">
                        <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                        <input v-model="termo" type="search" placeholder="Buscar na pergunta ou pelo MLB"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent py-2 pl-8 pr-3 text-sm">
                    </div>
                </div>

                <p v-if="!lista.length" class="p-8 text-center text-sm text-slate-500">
                    <i class="far fa-circle-question mb-2 block text-3xl opacity-50"></i>
                    {{ filtro === 'pendentes' ? 'Nenhuma pergunta esperando resposta.' : 'Nenhuma pergunta por aqui ainda.' }}
                </p>

                <div class="max-h-[70vh] flex-1 overflow-y-auto">
                    <button v-for="p in lista" :key="p.id" type="button"
                        class="block w-full border-b border-[var(--surface-border)] px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/60"
                        :class="{ 'bg-orange-50 dark:bg-orange-500/10': p.id === selecionadaId }"
                        @click="abrir(p)">
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate text-xs font-medium text-slate-500">{{ p.produto || p.item_id }}</span>
                            <span class="shrink-0 text-[11px]" :class="pendente(p) ? 'font-semibold text-[#f27a2a]' : 'text-slate-400'">{{ quando(p.perguntada_em) }}</span>
                        </span>
                        <span class="mt-0.5 line-clamp-2 block text-sm" :class="pendente(p) ? 'font-semibold' : 'text-slate-600 dark:text-slate-300'">{{ p.pergunta }}</span>
                        <span class="mt-1.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-medium" :class="situacao(p).classe">{{ situacao(p).texto }}</span>
                    </button>
                </div>

                <div v-if="perguntas.last_page > 1" class="flex items-center justify-between border-t border-[var(--surface-border)] px-4 py-2 text-xs">
                    <Link v-if="perguntas.prev_page_url" :href="perguntas.prev_page_url" preserve-scroll class="font-medium">‹ Anteriores</Link><span v-else></span>
                    <span class="text-slate-400">Página {{ perguntas.current_page }} de {{ perguntas.last_page }}</span>
                    <Link v-if="perguntas.next_page_url" :href="perguntas.next_page_url" preserve-scroll class="font-medium">Mais antigas ›</Link><span v-else></span>
                </div>
            </div>

            <!-- Pergunta aberta -->
            <div class="min-w-0" :class="{ 'hidden lg:block': !selecionada }">
                <div v-if="!selecionada" class="flex h-full min-h-[40vh] flex-col items-center justify-center p-8 text-center text-slate-400">
                    <i class="far fa-circle-question text-5xl opacity-40"></i>
                    <p class="mt-3 text-sm">Escolha uma pergunta para responder.</p>
                </div>

                <article v-else class="p-5 md:p-7">
                    <button type="button" class="mb-4 text-sm font-medium text-slate-500 lg:hidden" @click="fechar">
                        <i class="fas fa-arrow-left mr-1"></i> Voltar
                    </button>

                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="font-semibold">{{ selecionada.produto || 'Anúncio' }}</span>
                        <a v-if="selecionada.link_anuncio" :href="selecionada.link_anuncio" target="_blank" rel="noopener" class="text-xs font-medium text-sky-600 hover:underline dark:text-sky-400">
                            {{ selecionada.item_id }} <i class="fas fa-arrow-up-right-from-square ml-0.5 text-[10px]"></i>
                        </a>
                        <span v-else class="text-xs text-slate-400">{{ selecionada.item_id }}</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="situacao(selecionada).classe">{{ situacao(selecionada).texto }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-400">Perguntada em {{ quandoCompleto(selecionada.perguntada_em) }}</p>

                    <div class="mt-5 whitespace-pre-line rounded-xl border-l-4 border-[var(--faixa-card)] bg-orange-50/60 p-5 text-[15px] leading-relaxed dark:bg-orange-500/5">{{ selecionada.pergunta }}</div>

                    <!-- Já respondida -->
                    <div v-if="selecionada.respondida_em" class="mt-6">
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Resposta</p>
                        <div v-if="selecionada.resposta_enviada" class="whitespace-pre-line rounded-xl bg-emerald-50 p-5 text-[15px] leading-relaxed dark:bg-emerald-500/10">{{ selecionada.resposta_enviada }}</div>
                        <p v-else class="text-sm text-slate-500">Respondida direto no Mercado Livre.</p>
                        <p class="mt-2 text-xs text-slate-400">Em {{ quandoCompleto(selecionada.respondida_em) }}</p>
                    </div>

                    <p v-else-if="!pendente(selecionada)" class="mt-6 rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        O Mercado Livre fechou esta pergunta ({{ selecionada.status }}). Não dá mais para responder.
                    </p>

                    <!-- Responder -->
                    <div v-else class="mt-6">
                        <p v-if="selecionada.resposta_rascunho" class="mb-2 text-xs text-slate-500">
                            <i class="fas fa-wand-magic-sparkles mr-1 text-sky-500"></i> Rascunho da Manuela. Revise e edite se precisar.
                        </p>
                        <p v-else-if="selecionada.precisa_humano" class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            <i class="fas fa-user mr-1"></i> A Manuela não achou a resposta no anúncio. Escreva você.
                        </p>
                        <p v-else class="mb-2 text-xs text-slate-500">A Manuela ainda está escrevendo o rascunho. Você pode responder direto.</p>

                        <label for="resposta" class="sr-only">Resposta</label>
                        <textarea id="resposta" v-model="texto" rows="6" :maxlength="LIMITE"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent p-3 text-[15px] leading-relaxed"
                            placeholder="Escreva a resposta ao comprador"></textarea>
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-400">
                            <span>Sem telefone, e-mail, link ou WhatsApp: o Mercado Livre proíbe.</span>
                            <span :class="{ 'text-red-600': texto.length > LIMITE }">{{ texto.length }}/{{ LIMITE }}</span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" :disabled="!podeEnviar"
                                class="inline-flex items-center gap-2 rounded-lg bg-[#f27a2a] px-4 py-2.5 text-sm font-semibold text-white hover:brightness-95 disabled:opacity-50"
                                @click="enviar">
                                <i class="fas" :class="enviando ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i> Aprovar e enviar
                            </button>
                            <button v-if="selecionada.resposta_rascunho && texto !== selecionada.resposta_rascunho" type="button"
                                class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-4 py-2.5 text-sm font-medium"
                                @click="texto = selecionada.resposta_rascunho">
                                <i class="fas fa-rotate-left"></i> Voltar ao rascunho
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </AdminLayout>
</template>
