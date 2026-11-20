<script setup>
// Munck (2026-11-20): a Máquina de Criativos que o Lira comprou, dentro do admin.
// Ela roda no computador dele (WSL, painel em http://localhost:4848), então só
// abre no navegador desse computador. A tela não guarda nada: só mostra o painel.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
});

const URL_MUNCK = 'http://localhost:4848/';
const ORIGEM_MUNCK = 'http://localhost:4848';
const quadro = ref(null);

// Manda a lista de produtos para o painel (ele troca o link por um select).
function enviarProdutos() {
    const produtos = props.produtos.map((p) => ({ nome: p.nome, url: p.url }));
    quadro.value?.contentWindow?.postMessage({ tipo: 'munck-produtos', produtos }, ORIGEM_MUNCK);
}

function aoMensagem(e) {
    if (e.origin === ORIGEM_MUNCK && e.data?.tipo === 'munck-pronta') enviarProdutos();
}
const estado = ref('verificando'); // verificando | ligada | desligada
const chave = ref(0);

async function verificar() {
    estado.value = 'verificando';
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 3000);
    try {
        // no-cors: a resposta vem opaca, mas só chega se o painel estiver no ar.
        await fetch(URL_MUNCK, { mode: 'no-cors', cache: 'no-store', signal: ctrl.signal });
        estado.value = 'ligada';
    } catch {
        estado.value = 'desligada';
    } finally {
        clearTimeout(t);
    }
}

function recarregar() {
    chave.value++;
    verificar();
}

onMounted(() => {
    window.addEventListener('message', aoMensagem);
    verificar();
});
onBeforeUnmount(() => window.removeEventListener('message', aoMensagem));
</script>

<template>
    <Head title="Munck" />
    <AdminLayout>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">Munck</h1>
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="{
                        'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100': estado === 'ligada',
                        'bg-amber-50 text-amber-700 ring-1 ring-amber-100': estado === 'desligada',
                        'bg-slate-100 text-slate-600': estado === 'verificando',
                    }"
                >
                    {{ estado === 'ligada' ? 'Ligada' : estado === 'desligada' ? 'Desligada' : 'Verificando…' }}
                </span>
            </div>
            <div class="flex gap-2">
                <button type="button" class="rounded-md border px-3 py-1.5 text-sm hover:bg-slate-50" @click="recarregar">
                    <i class="fas fa-rotate-right mr-1" aria-hidden="true"></i>Recarregar
                </button>
                <a :href="URL_MUNCK" target="_blank" rel="noopener" class="rounded-md border px-3 py-1.5 text-sm hover:bg-slate-50">
                    <i class="fas fa-up-right-from-square mr-1" aria-hidden="true"></i>Abrir em nova aba
                </a>
            </div>
        </div>

        <div v-if="estado === 'desligada'" class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            A Munck roda no computador da loja e parece estar desligada. Dê dois cliques em
            <strong>Ligar Munck</strong> na área de trabalho e clique em Recarregar.
            Ela só abre no computador onde está instalada.
        </div>

        <iframe
            :key="chave"
            ref="quadro"
            :src="URL_MUNCK"
            @load="enviarProdutos"
            title="Munck"
            allow="clipboard-read; clipboard-write; local-network-access"
            class="w-full rounded-lg border bg-white"
            style="height: calc(100vh - 170px); min-height: 520px"
        ></iframe>
    </AdminLayout>
</template>
