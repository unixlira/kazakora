<script setup>
// Vids (2026-11-20): a Máquina de Criativos que o Lira comprou, dentro do admin.
// Roda na VPS (serviço vids, usuário próprio munck) e o nginx a serve em /vids/,
// com o mesmo login do admin. A tela só mostra o painel e manda os produtos.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
});

const URL_VIDS = '/vids/';
const ORIGEM_VIDS = window.location.origin;
const quadro = ref(null);

// Manda a lista de produtos para o painel (ele troca o link por um select).
function enviarProdutos() {
    const produtos = props.produtos.map((p) => ({ id: p.id, nome: p.nome, url: p.url }));
    quadro.value?.contentWindow?.postMessage({ tipo: 'munck-produtos', produtos }, ORIGEM_VIDS);
}

function aoMensagem(e) {
    if (e.origin === ORIGEM_VIDS && e.data?.tipo === 'munck-pronta') enviarProdutos();
}
const estado = ref('verificando'); // verificando | ligada | desligada

async function verificar() {
    estado.value = 'verificando';
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 3000);
    try {
        const r = await fetch(`${URL_VIDS}api/projetos`, { cache: 'no-store', signal: ctrl.signal, credentials: 'same-origin' });
        estado.value = r.ok ? 'ligada' : 'desligada';
    } catch {
        estado.value = 'desligada';
    } finally {
        clearTimeout(t);
    }
}


onMounted(() => {
    window.addEventListener('message', aoMensagem);
    verificar();
});
onBeforeUnmount(() => window.removeEventListener('message', aoMensagem));
</script>

<template>
    <Head title="Vids" />
    <AdminLayout>
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <h1 class="text-xl font-semibold">Vids</h1>
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
        </div>

        <div v-if="estado === 'desligada'" class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            A Vids não respondeu no servidor. Atualize a página; se continuar assim,
            o serviço <code>vids</code> da VPS está parado.
        </div>

        <iframe
            ref="quadro"
            :src="URL_VIDS"
            @load="enviarProdutos"
            title="Vids"
            allow="clipboard-read; clipboard-write"
            class="w-full rounded-lg border bg-white"
            style="height: calc(100vh - 170px); min-height: 520px"
        ></iframe>
    </AdminLayout>
</template>
