<script setup>
// Vids (2026-11-20): a Máquina de Criativos que o Lira comprou, dentro do admin.
// Roda na VPS (serviço vids, usuário próprio munck) e o nginx a serve em /vids/,
// com o mesmo login do admin. A tela só mostra o painel e manda os produtos.
// Campanha | Redes Sociais (Lira, 23/11/2026): primeiro escolhe o tipo de vídeo e cada
// escolha abre o seu mecanismo: Campanha = Máquina de Criativos (/vids/, serviço vids),
// Redes Sociais = Máquina de Vídeos Redes Sociais (/vids-redes/, serviço vids-redes).
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const MAQUINAS = {
    campanha: { url: '/vids/', nome: 'Campanha', servico: 'vids', icone: 'fa-bullhorn', texto: 'Vídeos de venda para anúncio (Meta Ads): oferta, ganchos, copy e voz ElevenLabs.' },
    redes: { url: '/vids-redes/', nome: 'Redes Sociais', servico: 'vids-redes', icone: 'fa-hashtag', texto: 'Vídeos de engajamento para o perfil: gancho viral com crédito, dica e "segue a gente". Sem preço.' },
};
const inicial = new URLSearchParams(window.location.search).get('modo');
const modo = ref(MAQUINAS[inicial] ? inicial : null);
const maquina = computed(() => (modo.value ? MAQUINAS[modo.value] : null));
const URL_VIDS_DE = () => maquina.value?.url || '/vids/';
const ORIGEM_VIDS = window.location.origin;
const quadro = ref(null);

// Busca de produtos (Lira, 20/11): o painel manda o que a pessoa digitou e a
// tela responde com a busca do site (/admin/vids/produtos).
const enviar = (dados) => quadro.value?.contentWindow?.postMessage(dados, ORIGEM_VIDS);
function ligarBusca() {
    enviar({ tipo: 'munck-busca-ligada' });
}
async function buscar(q) {
    try {
        const r = await fetch(`/admin/vids/produtos?q=${encodeURIComponent(q || '')}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const d = await r.json();
        enviar({ tipo: 'munck-resultados', q: d.q, produtos: d.produtos || [] });
    } catch {
        enviar({ tipo: 'munck-resultados', q, produtos: [], erro: true });
    }
}

function aoMensagem(e) {
    if (e.origin !== ORIGEM_VIDS) return;
    if (e.data?.tipo === 'munck-pronta') ligarBusca();
    else if (e.data?.tipo === 'munck-buscar') buscar(String(e.data.q || '').slice(0, 100));
}
const estado = ref('verificando'); // verificando | ligada | desligada

async function verificar() {
    estado.value = 'verificando';
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 3000);
    try {
        const r = await fetch(`${URL_VIDS_DE()}api/projetos`, { cache: 'no-store', signal: ctrl.signal, credentials: 'same-origin' });
        estado.value = r.ok ? 'ligada' : 'desligada';
    } catch {
        estado.value = 'desligada';
    } finally {
        clearTimeout(t);
    }
}


function escolher(chave) {
    modo.value = chave;
    const u = new URL(window.location.href);
    if (chave) u.searchParams.set('modo', chave); else u.searchParams.delete('modo');
    window.history.replaceState(window.history.state, '', u);
    if (chave) verificar();
}

onMounted(() => {
    window.addEventListener('message', aoMensagem);
    if (modo.value) verificar();
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
                    v-if="maquina"
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
            <div v-if="maquina" class="inline-flex rounded-lg border bg-white p-1 text-sm" role="tablist" aria-label="Tipo de vídeo">
                <button
                    v-for="(m, chave) in MAQUINAS"
                    :key="chave"
                    type="button"
                    role="tab"
                    :aria-selected="modo === chave"
                    class="rounded-md px-3 py-1.5 font-medium transition"
                    :class="modo === chave ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    @click="escolher(chave)"
                >
                    <i class="fa-solid mr-1.5" :class="m.icone"></i>{{ m.nome }}
                </button>
            </div>
        </div>

        <div v-if="!maquina" class="mx-auto mt-6 max-w-3xl">
            <p class="mb-4 text-sm text-slate-600">Que tipo de vídeo você quer criar?</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <button
                    v-for="(m, chave) in MAQUINAS"
                    :key="chave"
                    type="button"
                    class="group rounded-xl border bg-white p-6 text-left shadow-sm transition hover:border-slate-900 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900"
                    @click="escolher(chave)"
                >
                    <span class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-lg bg-slate-900 text-lg text-white">
                        <i class="fa-solid" :class="m.icone"></i>
                    </span>
                    <span class="block text-lg font-semibold text-slate-900">{{ m.nome }}</span>
                    <span class="mt-1 block text-sm text-slate-600">{{ m.texto }}</span>
                </button>
            </div>
        </div>

        <template v-else>
            <div v-if="estado === 'desligada'" class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                A máquina {{ maquina.nome }} não respondeu no servidor. Atualize a página; se continuar assim,
                o serviço <code>{{ maquina.servico }}</code> da VPS está parado.
            </div>

            <iframe
                :key="modo"
                ref="quadro"
                :src="maquina.url"
                @load="ligarBusca"
                :title="`Vids ${maquina.nome}`"
                allow="clipboard-read; clipboard-write"
                class="w-full rounded-lg border bg-white"
                style="height: calc(100vh - 170px); min-height: 520px"
            ></iframe>
        </template>
    </AdminLayout>
</template>
