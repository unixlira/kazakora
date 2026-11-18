<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    ofertas: { type: Array, default: () => [] },
    coletadoEm: { type: String, default: null },
});

const TODOS = 'Todos';
const IDIOMAS = { es: 'Espanhol', en: 'Inglês', pt: 'Português', fr: 'Francês', de: 'Alemão', it: 'Italiano', ro: 'Romeno' };
const nomeIdioma = (codigo) => IDIOMAS[codigo] ?? (codigo || '—');

const nicho = ref(TODOS);
const pais = ref(TODOS);
const idioma = ref(TODOS);

const ordenado = (lista) => [TODOS, ...[...new Set(lista.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'))];
const nichos = computed(() => ordenado(props.ofertas.map((o) => o.nicho)));
const paises = computed(() => ordenado(props.ofertas.flatMap((o) => o.paises ?? [])));
const idiomas = computed(() => ordenado(props.ofertas.map((o) => o.idioma)));

const filtradas = computed(() => props.ofertas.filter((o) => (nicho.value === TODOS || o.nicho === nicho.value)
    && (pais.value === TODOS || (o.paises ?? []).includes(pais.value))
    && (idioma.value === TODOS || o.idioma === idioma.value)));

const preco = (o) => (o.preco === null || o.preco === undefined ? null : `${o.moeda ?? ''} ${Number(o.preco).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim());
const dataColeta = computed(() => (props.coletadoEm ? new Date(props.coletadoEm).toLocaleDateString('pt-BR') : null));
const resumoPaises = (lista) => (lista.length > 4 ? `${lista.slice(0, 4).join(' · ')} +${lista.length - 4}` : lista.join(' · '));
</script>

<template>
    <Head title="Oportunidades em PDF" />
    <AdminLayout>
        <section class="rounded-3xl bg-[#17110d] px-5 py-6 text-white shadow-xl sm:px-8">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-purple-200">Biblioteca de Anúncios da Meta · mundo</p>
            <h1 class="mt-2 text-2xl font-black sm:text-3xl">Oportunidades em PDF</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-purple-50/80">
                As ofertas de infoproduto em PDF (ebook, guia, planner, apostila) com mais anúncios ativos e há mais tempo no ar.
                A Biblioteca não mostra vendas: anúncio que roda há 30+ dias com muitas variações é sinal de que se paga.
            </p>
            <p v-if="dataColeta" class="mt-3 text-xs font-semibold text-purple-100/70">Coleta de {{ dataColeta }} · {{ ofertas.length }} ofertas</p>
        </section>

        <section class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-3">
            <label class="text-xs font-bold uppercase tracking-wide text-slate-500">Nicho
                <select v-model="nicho" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case text-slate-700">
                    <option v-for="n in nichos" :key="n" :value="n">{{ n }}</option>
                </select>
            </label>
            <label class="text-xs font-bold uppercase tracking-wide text-slate-500">País
                <select v-model="pais" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case text-slate-700">
                    <option v-for="p in paises" :key="p" :value="p">{{ p }}</option>
                </select>
            </label>
            <label class="text-xs font-bold uppercase tracking-wide text-slate-500">Idioma
                <select v-model="idioma" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case text-slate-700">
                    <option v-for="i in idiomas" :key="i" :value="i">{{ i === TODOS ? TODOS : nomeIdioma(i) }}</option>
                </select>
            </label>
        </section>

        <p v-if="!ofertas.length" class="mt-6 rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">
            Nenhuma oferta importada ainda. Rode <code class="font-mono">php artisan pdf-oportunidades:importar caminho/ofertas.json</code>.
        </p>
        <p v-else-if="!filtradas.length" class="mt-6 rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">Nenhuma oferta com esses filtros.</p>

        <section class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <Link v-for="o in filtradas" :key="o.id" :href="`/admin/pdf-oportunidades/${o.id}`"
                class="flex gap-3 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-100 transition hover:ring-purple-300">
                <div class="relative h-24 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                    <img v-if="o.miniatura" :src="o.miniatura" alt="" loading="lazy" class="h-full w-full object-cover" />
                    <i v-else class="fas fa-file-pdf absolute inset-0 m-auto h-6 w-6 text-2xl text-slate-300"></i>
                    <span class="absolute left-1 top-1 rounded-md bg-black/70 px-1.5 text-xs font-black text-white">#{{ o.posicao ?? '—' }}</span>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="line-clamp-2 text-sm font-black leading-5 text-slate-900">{{ o.nome }}</p>
                    <p class="mt-0.5 truncate text-xs font-bold uppercase tracking-wide text-purple-700">{{ o.nicho || 'Sem nicho' }} · {{ nomeIdioma(o.idioma) }}</p>
                    <p class="mt-0.5 truncate text-xs text-slate-500">{{ resumoPaises(o.paises ?? []) }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-bold">
                        <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ o.anunciosAtivos }} anúncios</span>
                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ o.diasRodando }} dias</span>
                        <span v-if="preco(o)" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ preco(o) }}</span>
                    </div>
                </div>
            </Link>
        </section>
    </AdminLayout>
</template>
