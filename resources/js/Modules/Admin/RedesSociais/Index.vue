<script setup>
// Atendimento › Instagram e Atendimento › Facebook (Lira, 2026-10-27): Direct
// e Messenger de quem chama pelos anúncios, cada rede na sua tela para saber
// de onde veio. O sync roda a cada 2 min e a tela recarrega a cada 30 s.
// Só leitura: responder continua no app do Instagram e do Facebook.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { notifyError, notifySuccess } from '@/Shared/notify';

const props = defineProps({
    rede: { type: String, required: true },
    faltando: { type: Array, default: () => [] },
    conversas: { type: Array, required: true },
    selecionada: { type: Object, default: null },
    mensagens: { type: Array, default: () => [] },
    filtro: { type: String, default: 'todas' },
    busca: { type: String, default: '' },
    contagem: { type: Object, default: () => ({}) },
    ultimaSincronizacao: { type: String, default: null },
});

const REDES = {
    instagram: { nome: 'Instagram', canal: 'Direct do Instagram', icone: 'fab fa-instagram', fundo: 'bg-gradient-to-br from-[#feda75] via-[#d62976] to-[#4f5bd5] text-white' },
    facebook: { nome: 'Facebook', canal: 'Messenger da página do Facebook', icone: 'fab fa-facebook-messenger', fundo: 'bg-[#0866ff] text-white' },
};
const info = computed(() => REDES[props.rede]);
const configurado = computed(() => !props.faltando.length);
const base = computed(() => `/admin/${props.rede}/conversas`);

const FILTROS = [['todas', 'Todas'], ['nao_lidas', 'Não lidas']];
const termo = ref(props.busca);
const fio = ref(null);

const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Sao_Paulo' }) : '');
const iniciais = (c) => (c.cliente || '?').replace(/[^\p{L}\p{N} ._-]/gu, '').trim().slice(0, 2).toUpperCase() || '?';

const consulta = (extra = {}) => ({ filtro: props.filtro, busca: termo.value || undefined, conversa: props.selecionada?.id, ...extra });
const ir = (extra) => router.get(base.value, consulta(extra), { preserveState: true, preserveScroll: true, replace: true });
const abrir = (c) => ir({ conversa: c.id });
const recarregar = () => router.reload({ preserveScroll: true });

const descer = async () => { await nextTick(); if (fio.value) fio.value.scrollTop = fio.value.scrollHeight; };
watch(() => [props.selecionada?.id, props.mensagens.length], descer);
onMounted(descer);

let timer = null;
onMounted(() => { if (configurado.value) timer = setInterval(recarregar, 30000); });
onUnmounted(() => clearInterval(timer));

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const marcarLida = async () => {
    try {
        const r = await fetch(`${base.value}/${props.selecionada.id}/lida`, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!r.ok) throw new Error('Não foi possível marcar como lida.');
        notifySuccess('Marcada como lida.');
        recarregar();
    } catch (e) {
        notifyError(e.message);
    }
};

const sincronizadaHa = computed(() => (props.ultimaSincronizacao ? dataHora(props.ultimaSincronizacao) : 'ainda não sincronizou'));
</script>

<template>
    <Head :title="info.nome" />

    <AdminLayout>
        <div class="min-w-0 space-y-4">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl" :class="info.fundo"><i :class="info.icone"></i></span>
                {{ info.nome }}
            </h1>

            <!-- Rede ainda sem permissão no token: mostra o que falta, sem lista vazia enganosa. -->
            <div v-if="!configurado" class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                <p class="font-semibold">{{ info.canal }}: integração ainda não ligada</p>
                <p class="mt-2">As mensagens vêm da página "Kaza Kora &amp; CO" pela API da Meta, com o mesmo acesso do Meta ADS. Falta liberar no acesso da Meta:</p>
                <ul class="mt-2 list-disc space-y-0.5 pl-5">
                    <li v-for="f in faltando" :key="f">{{ f }}</li>
                </ul>
            </div>

            <template v-else>
            <p class="text-sm text-slate-500">
                Mensagens do {{ info.canal }}. Atualiza sozinho a cada 2 minutos (último: {{ sincronizadaHa }}).
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <button v-for="[k, rotulo] in FILTROS" :key="k" type="button"
                    class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold"
                    :class="filtro === k ? (k === 'nao_lidas' ? 'bg-red-500 text-white' : 'bg-primary text-white') : 'bg-[var(--surface-muted)] text-slate-600 dark:text-slate-300'"
                    @click="ir({ filtro: k, conversa: undefined })">
                    {{ rotulo }} <span class="opacity-75">{{ contagem[k] ?? 0 }}</span>
                </button>
                <form class="relative ml-auto w-full sm:w-72" @submit.prevent="ir({ conversa: undefined })">
                    <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                    <input v-model="termo" type="search" placeholder="Buscar cliente ou mensagem" class="w-full rounded-full border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-8 pr-3 text-sm">
                </form>
            </div>

            <p v-if="!conversas.length" class="rounded-2xl border border-dashed border-[var(--surface-border)] p-10 text-center text-sm text-slate-500">
                {{ filtro === 'nao_lidas' ? 'Nenhuma conversa esperando você.' : 'Nenhuma mensagem chegou ainda.' }}
            </p>

            <div v-else class="grid gap-4 lg:grid-cols-[minmax(300px,380px)_1fr]">
                <aside class="overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <ul class="max-h-[60vh] divide-y divide-[var(--surface-border)] overflow-y-auto overscroll-contain lg:max-h-[calc(100vh-300px)]">
                        <li v-for="c in conversas" :key="c.id">
                            <div role="button" tabindex="0" class="flex w-full cursor-pointer gap-3 px-4 py-3 text-left transition-colors"
                                :class="c.id === selecionada?.id ? 'bg-primary/10' : 'hover:bg-[var(--surface-muted)]'"
                                :aria-current="c.id === selecionada?.id ? 'true' : undefined"
                                @click="abrir(c)" @keydown.enter.prevent="abrir(c)" @keydown.space.prevent="abrir(c)">
                                <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-sm font-bold" :class="info.fundo">{{ iniciais(c) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-2">
                                        <span class="truncate text-sm" :class="c.nao_lidas ? 'font-bold' : 'font-semibold'">{{ c.cliente || `Cliente ${info.nome}` }}</span>
                                        <span class="ml-auto shrink-0 text-[11px] text-slate-400">{{ dataHora(c.ultima_em) }}</span>
                                    </span>
                                    <span class="mt-0.5 flex items-center gap-2">
                                        <span class="line-clamp-2 flex-1 text-sm" :class="c.nao_lidas ? 'font-medium' : 'text-slate-500'">
                                            <i v-if="!c.ultima_do_cliente" class="fas fa-reply mr-1 text-[10px] text-slate-400" title="Última mensagem foi da loja"></i>{{ c.ultima }}
                                        </span>
                                        <span v-if="c.nao_lidas" class="inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-bold text-white">{{ c.nao_lidas }}</span>
                                    </span>
                                </span>
                            </div>
                        </li>
                    </ul>
                </aside>

                <section class="min-w-0">
                    <article v-if="selecionada" :key="selecionada.id" class="flex flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                        <header class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] px-5 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">{{ selecionada.cliente || `Cliente ${info.nome}` }}</p>
                                <p class="text-xs text-slate-400">{{ info.nome }} · Última mensagem em {{ dataHora(selecionada.ultima_em) }}</p>
                            </div>
                            <button v-if="selecionada.nao_lidas" type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-[var(--surface-muted)] dark:text-slate-300" @click="marcarLida">
                                <i class="fas fa-check-double mr-1"></i>Marcar como lida
                            </button>
                        </header>

                        <div ref="fio" class="max-h-[50vh] min-h-[200px] space-y-2 overflow-y-auto overscroll-contain px-5 py-4 lg:max-h-[calc(100vh-420px)]">
                            <p v-if="!mensagens.length" class="py-6 text-center text-sm text-slate-500">As mensagens desta conversa chegam no próximo sync (até 2 minutos).</p>
                            <div v-for="m in mensagens" :key="m.id" class="flex" :class="m.do_cliente ? 'justify-start' : 'justify-end'">
                                <div class="max-w-[80%] rounded-2xl px-3.5 py-2 text-sm" :class="m.do_cliente ? 'bg-[var(--surface-muted)]' : 'bg-primary/10'">
                                    <a v-if="m.imagem" :href="m.imagem" target="_blank" rel="noopener"><img :src="m.imagem" alt="Imagem enviada" class="mb-1 max-h-48 rounded-lg"></a>
                                    <p v-if="!m.imagem || m.texto !== '[imagem]'" class="whitespace-pre-line break-words" :class="m.tipo !== 'text' && !m.imagem ? 'italic text-slate-500' : ''">{{ m.imagem && m.texto === '[imagem]' ? '' : m.texto }}</p>
                                    <p class="mt-1 text-[10px] text-slate-400">{{ dataHora(m.enviada_em) }}<template v-if="!m.do_cliente"> · loja</template></p>
                                </div>
                            </div>
                        </div>

                        <footer class="border-t border-[var(--surface-border)] px-5 py-3 text-xs text-slate-500">
                            Só leitura por enquanto: para responder, use o app do {{ info.nome }}.
                        </footer>
                    </article>
                </section>
            </div>
            </template>
        </div>
    </AdminLayout>
</template>
