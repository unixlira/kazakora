<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Termometro from './Termometro.vue';

const props = defineProps({
    oportunidades: { type: Array, default: () => [] },
    fases: { type: Object, default: () => ({}) },
    coletadoEm: { type: String, default: null },
});

const TODAS = 'todas';
const fase = ref(TODAS);
const nicho = ref(TODAS);
const busca = ref('');

const nichos = computed(() => [...new Set(props.oportunidades.map((o) => o.nicho).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR')));
const contagem = computed(() => props.oportunidades.reduce((acc, o) => ({ ...acc, [o.fase]: (acc[o.fase] ?? 0) + 1 }), {}));
const filtradas = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    return props.oportunidades.filter((o) => (fase.value === TODAS || o.fase === fase.value)
        && (nicho.value === TODAS || o.nicho === nicho.value)
        && (!termo || `${o.nome} ${o.anunciante ?? ''} ${o.dominio ?? ''}`.toLowerCase().includes(termo)));
});

const CORES_FASE = { teste: 'bg-blue-50 text-blue-700', validando: 'bg-amber-50 text-amber-700', escala: 'bg-red-50 text-red-700', saturando: 'bg-slate-100 text-slate-600' };
const numero = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('pt-BR', { notation: n >= 10000 ? 'compact' : 'standard' }));
const dataColeta = computed(() => (props.coletadoEm ? new Date(props.coletadoEm).toLocaleDateString('pt-BR') : null));
</script>

<template>
    <Head title="Oportunidades KazaKora" />
    <AdminLayout>
        <section class="rounded-3xl bg-[#1f2225] px-5 py-6 text-white shadow-xl sm:px-8">
            <div class="flex items-center gap-3">
                <img src="/images/marca/icone-menu-kazakora.png" alt="" class="h-10 w-10" />
                <div>
                    <h1 class="text-2xl font-black sm:text-3xl">Oportunidades KazaKora</h1>
                </div>
            </div>
            <p class="mt-3 max-w-3xl text-sm leading-6 text-white/75">
                Produtos que outros anunciantes estão escalando. O termômetro junta dias no ar, variações ativas, alcance,
                criativos novos na semana e quantos anunciantes vendem o mesmo produto. Abra um produto para ver os criativos e criar os nossos.
            </p>
            <p v-if="dataColeta" class="mt-3 text-xs font-semibold text-white/55">Coleta de {{ dataColeta }} · {{ oportunidades.length }} produtos</p>
        </section>

        <section class="mt-4 grid grid-cols-2 gap-2 lg:grid-cols-4">
            <button v-for="(f, chave) in fases" :key="chave" type="button" @click="fase = fase === chave ? TODAS : chave"
                class="rounded-2xl bg-white p-3 text-left shadow-sm ring-1 transition"
                :class="fase === chave ? 'ring-2 ring-orange-500' : 'ring-slate-100 hover:ring-orange-300'">
                <span class="rounded-full px-2 py-0.5 text-xs font-black" :class="CORES_FASE[chave]">{{ f.rotulo }}</span>
                <p class="mt-2 text-2xl font-black text-slate-900">{{ contagem[chave] ?? 0 }}</p>
                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{{ f.descricao }}</p>
            </button>
        </section>

        <section class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <input v-model="busca" type="search" placeholder="Buscar produto, anunciante ou site"
                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700" />
            <select v-model="nicho" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                <option :value="TODAS">Todos os nichos</option>
                <option v-for="n in nichos" :key="n" :value="n">{{ n }}</option>
            </select>
        </section>

        <p v-if="!oportunidades.length" class="mt-6 rounded-2xl bg-white p-6 text-sm leading-6 text-slate-600 shadow">
            Nenhum produto importado ainda. O coletor do Homer roda com termos de produto físico e a importação é
            <code class="font-mono">php artisan oportunidades-kazakora:importar caminho/ofertas.json</code>.
        </p>
        <p v-else-if="!filtradas.length" class="mt-6 rounded-2xl bg-white p-6 text-sm text-slate-600 shadow">Nenhum produto com esses filtros.</p>

        <section class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="o in filtradas" :key="o.id" :href="`/admin/oportunidades/kazakora/${o.id}`"
                class="flex gap-3 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-100 transition hover:ring-orange-300">
                <div class="relative h-28 w-24 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                    <img v-if="o.miniatura" :src="o.miniatura" alt="" loading="lazy" class="h-full w-full object-cover" />
                    <i v-else class="fas fa-box absolute inset-0 m-auto h-6 w-6 text-2xl text-slate-300"></i>
                </div>
                <div class="min-w-0 flex-1">
                    <span class="rounded-full px-2 py-0.5 text-xs font-black" :class="CORES_FASE[o.fase]">{{ fases[o.fase]?.rotulo ?? o.fase }}</span>
                    <p class="mt-1 line-clamp-2 text-sm font-black leading-5 text-slate-900">{{ o.nome }}</p>
                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ o.nicho || 'Sem nicho' }}<span v-if="o.anunciante"> · {{ o.anunciante }}</span></p>
                    <div class="mt-2 flex flex-wrap gap-1 text-xs font-bold">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ o.anunciosAtivos }} anúncios</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ o.diasRodando }} dias</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ o.criativosNovos7d }} novos/7d</span>
                        <span v-if="o.totalCriativos" class="rounded-full bg-orange-50 px-2 py-0.5 text-orange-700">{{ o.totalCriativos }} nossos</span>
                    </div>
                </div>
                <div class="flex shrink-0 flex-col items-center">
                    <Termometro :pontuacao="o.pontuacao" :fase="o.fase" />
                    <span class="text-xs font-black text-slate-900">{{ numero(o.pontuacao) }}</span>
                </div>
            </Link>
        </section>
    </AdminLayout>
</template>
