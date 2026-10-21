<script>
// Fora do setup: o AdminLayout remonta a cada navegação do Inertia e o cursor
// e os já avisados precisam sobreviver (mesmo motivo do WhatsAppToasts).
let desde = null;
const avisadas = new Set();
</script>

<script setup>
import { canalPergunta } from '@/Shared/canaisPerguntas';
import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Pergunta de marketplace que a Manuela não conseguiu responder (pedido do
// Lira 2026-10-20): aviso vermelho no canto superior, ao lado da coluna do
// WhatsApp. Fica até alguém clicar ou fechar, porque é pendência de humano.
// Clique abre Atendimento > Perguntas MK Place. Polling de 10s com a aba
// visível; o contador do menu e a pílula do topo acompanham.

const POLL_MS = 10000;
const MAX_VISIBLE = 3;

const page = usePage();
const toasts = ref([]);

let timer = null;
let busy = false;

onMounted(() => {
    document.addEventListener('visibilitychange', onVisibility);
    poll();
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    document.removeEventListener('visibilitychange', onVisibility);
});

function onVisibility() {
    if (document.visibilityState === 'visible') {
        clearTimeout(timer);
        poll();
    }
}

async function poll() {
    if (busy) return;
    busy = true;

    try {
        const response = await fetch(`/admin/perguntas-marketplace/chegando${desde === null ? '' : `?desde=${encodeURIComponent(desde)}`}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        const data = response.ok ? await response.json() : null;
        if (data) receber(data);
    } catch {
        // rede caiu: tenta de novo no próximo ciclo
    } finally {
        busy = false;
        if (document.visibilityState === 'visible') timer = setTimeout(poll, POLL_MS);
    }
}

function receber(data) {
    const firstRun = desde === null;
    desde = data.desde;

    if (page.props.sidebarBadges) page.props.sidebarBadges.perguntasMkplace = data.total;
    if (firstRun) return;

    const novas = data.perguntas.filter((p) => !avisadas.has(p.id));
    if (!novas.length) return;
    novas.forEach((p) => avisadas.add(p.id));

    if (window.location.pathname === '/admin/perguntas-marketplace') {
        router.reload({ preserveScroll: true });
    }

    novas.forEach((p) => {
        toasts.value.push({ ...p, key: `pergunta-${p.id}` });
        if (toasts.value.length > MAX_VISIBLE) toasts.value.shift();
    });
}

function dismiss(key) {
    toasts.value = toasts.value.filter((t) => t.key !== key);
}

function open(toast) {
    dismiss(toast.key);
    router.visit(`/admin/perguntas-marketplace?filtro=humano&pergunta=${toast.id}`);
}
</script>

<template>
    <div class="pointer-events-none fixed right-4 top-4 z-[71] w-[min(360px,calc(100vw-2rem))] md:right-[calc(360px+2rem)]">
        <TransitionGroup tag="div" name="pergunta-toast" class="relative flex flex-col gap-2">
            <div
                v-for="toast in toasts"
                :key="toast.key"
                role="alert"
                class="pergunta-toast pointer-events-auto flex w-full items-start gap-3 rounded-xl border-l-4 border-red-600 bg-white p-3 text-left shadow-lg ring-1 ring-red-600/20 dark:bg-[#2a1a1a]"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                    <i class="fas fa-circle-question"></i>
                </span>
                <button type="button" class="min-w-0 flex-1 text-left" @click="open(toast)">
                    <span class="block text-sm font-semibold text-red-700 dark:text-red-300">A Manuela não soube responder</span>
                    <span class="flex items-center gap-1.5 text-[11px] text-slate-500 dark:text-slate-400"><span class="inline-flex h-4 w-4 items-center justify-center rounded text-[9px]" :class="canalPergunta(toast.canal).fundo"><i :class="canalPergunta(toast.canal).icone"></i></span>{{ canalPergunta(toast.canal).nome }} · {{ toast.item_id }}</span>
                    <span class="mt-1 line-clamp-2 text-sm text-slate-700 dark:text-slate-200">{{ toast.pergunta }}</span>
                    <span class="mt-1 block text-xs font-medium text-red-700 underline dark:text-red-300">Assumir o atendimento</span>
                </button>
                <button type="button" class="shrink-0 text-slate-400 hover:text-slate-600" aria-label="Fechar aviso" @click="dismiss(toast.key)">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.pergunta-toast { transition: transform 0.35s ease, opacity 0.35s ease, filter 0.35s ease; }
.pergunta-toast-enter-from { opacity: 0; transform: translateY(-16px); }
.pergunta-toast-leave-to { opacity: 0; filter: blur(8px); transform: scale(0.96); }
.pergunta-toast-leave-active { position: absolute; left: 0; right: 0; }
.pergunta-toast-move { transition: transform 0.35s ease; }
</style>
