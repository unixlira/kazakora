<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SeloFase from '@/Shared/Components/SeloFase.vue';
import Termometro from '@/Shared/Components/Termometro.vue';

const props = defineProps({
    ofertas: { type: Array, default: () => [] },
    coletadoEm: { type: String, default: null },
    totalEscolhidos: { type: Number, default: 0 },
    fases: { type: Object, default: () => ({}) },
});

const clonando = ref(null);
const clonar = (o) => {
    clonando.value = o.id;
    router.post(`/admin/pdf-oportunidades/${o.id}/clonar`, {}, { preserveScroll: true, onFinish: () => { clonando.value = null; } });
};

const TODOS = 'Todos';
const IDIOMAS = { es: 'Espanhol', en: 'Inglês', pt: 'Português', fr: 'Francês', de: 'Alemão', it: 'Italiano', ro: 'Romeno' };
const nomeIdioma = (codigo) => IDIOMAS[codigo] ?? (codigo || '—');

const nicho = ref(TODOS);
const pais = ref(TODOS);
const idioma = ref(TODOS);
const fase = ref(TODOS);
const ordem = ref('termometro');
const ORDENS = { termometro: 'Termômetro (mais quente primeiro)', posicao: 'Posição do coletor', anuncios: 'Mais anúncios ativos', novos: 'Mais criativos novos (7 dias)' };
// Ordem dos cartões de fase, da mais quente para a mais fria.
const ORDEM_FASE = { escala: 0, validando: 1, saturando: 2, teste: 3 };

const ordenado = (lista) => [TODOS, ...[...new Set(lista.filter(Boolean))].sort((a, b) => a.localeCompare(b, 'pt-BR'))];
const nichos = computed(() => ordenado(props.ofertas.map((o) => o.nicho)));
const paises = computed(() => ordenado(props.ofertas.flatMap((o) => o.paises ?? [])));
const idiomas = computed(() => ordenado(props.ofertas.map((o) => o.idioma)));

const contagem = computed(() => props.ofertas.reduce((acc, o) => ({ ...acc, [o.termometro.fase]: (acc[o.termometro.fase] ?? 0) + 1 }), {}));
const fasesOrdenadas = computed(() => Object.entries(props.fases).sort(([a], [b]) => (ORDEM_FASE[a] ?? 9) - (ORDEM_FASE[b] ?? 9)));

// Empate no termômetro é comum (sem alcance nem concorrentes o teto é 87,5):
// desempata por criativos novos e depois por anúncios ativos.
const COMPARAR = {
    termometro: (a, b) => b.termometro.pontuacao - a.termometro.pontuacao || b.termometro.novos7d - a.termometro.novos7d || b.anunciosAtivos - a.anunciosAtivos,
    posicao: (a, b) => (a.posicao ?? 9999) - (b.posicao ?? 9999),
    anuncios: (a, b) => b.anunciosAtivos - a.anunciosAtivos,
    novos: (a, b) => b.termometro.novos7d - a.termometro.novos7d || b.termometro.pontuacao - a.termometro.pontuacao,
};

const filtradas = computed(() => props.ofertas.filter((o) => (nicho.value === TODOS || o.nicho === nicho.value)
    && (pais.value === TODOS || (o.paises ?? []).includes(pais.value))
    && (idioma.value === TODOS || o.idioma === idioma.value)
    && (fase.value === TODOS || o.termometro.fase === fase.value))
    .sort(COMPARAR[ordem.value] ?? COMPARAR.termometro));

// Nível ilustrativo do ícone em cada cartão de fase (do frio ao quente).
const NIVEL_FASE = { teste: 20, validando: 55, escala: 95, saturando: 70 };
const pontos = (n) => Number(n ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 });

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
                O termômetro é o mesmo das Oportunidades KazaKora: dias no ar, variações e criativos novos na semana
                (sem alcance nem concorrentes, o máximo aqui é 87,5).
            </p>
            <p v-if="dataColeta" class="mt-3 text-xs font-semibold text-purple-100/70">Coleta de {{ dataColeta }} · {{ ofertas.length }} ofertas</p>
        </section>

        <section class="mt-4 grid grid-cols-2 gap-2 lg:grid-cols-4">
            <button v-for="[chave, f] in fasesOrdenadas" :key="chave" type="button" @click="fase = fase === chave ? TODOS : chave"
                class="rounded-2xl bg-white p-3 text-left shadow-sm ring-1 transition"
                :class="fase === chave ? 'ring-2 ring-purple-600' : 'ring-slate-100 hover:ring-purple-300'">
                <SeloFase :pontuacao="NIVEL_FASE[chave]" :fase="chave" :rotulo="f.rotulo" />
                <p class="mt-2 text-2xl font-black text-slate-900">{{ contagem[chave] ?? 0 }}</p>
                <p class="mt-1 line-clamp-2 text-xs leading-5 text-slate-500">{{ f.descricao }}</p>
            </button>
        </section>

        <section class="mt-3 grid grid-cols-1 items-end gap-2 sm:grid-cols-2 lg:grid-cols-5">
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
            <label class="text-xs font-bold uppercase tracking-wide text-slate-500">Ordenar por
                <select v-model="ordem" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold normal-case text-slate-700">
                    <option v-for="(rotulo, chave) in ORDENS" :key="chave" :value="chave">{{ rotulo }}</option>
                </select>
            </label>
            <Link href="/admin/pdf-oportunidades/escolhidos"
                class="flex items-center justify-center gap-2 rounded-xl bg-purple-700 px-3 py-2 text-sm font-black text-white shadow-sm hover:bg-purple-800">
                <i class="fas fa-star"></i> PDFs Escolhidos
                <span class="rounded-full bg-white/20 px-2 text-xs">{{ totalEscolhidos }}</span>
            </Link>
        </section>

        <p v-if="!ofertas.length" class="mt-6 rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">
            Nenhuma oferta importada ainda. Rode <code class="font-mono">php artisan pdf-oportunidades:importar caminho/ofertas.json</code>.
        </p>
        <p v-else-if="!filtradas.length" class="mt-6 rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">Nenhuma oferta com esses filtros.</p>

        <section class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="o in filtradas" :key="o.id"
                class="flex flex-col rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-100 transition hover:ring-purple-300">
                <Link :href="`/admin/pdf-oportunidades/${o.id}`" class="flex flex-1 gap-3">
                    <div class="relative h-24 w-20 shrink-0 overflow-hidden rounded-xl bg-slate-100">
                        <img v-if="o.miniatura" :src="o.miniatura" alt="" loading="lazy" class="h-full w-full object-cover" />
                        <i v-else class="fas fa-file-pdf absolute inset-0 m-auto h-6 w-6 text-2xl text-slate-300"></i>
                        <span class="absolute left-1 top-1 rounded-md bg-black/70 px-1.5 text-xs font-black text-white">#{{ o.posicao ?? '—' }}</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <SeloFase :pontuacao="o.termometro.pontuacao" :fase="o.termometro.fase" :rotulo="fases[o.termometro.fase]?.rotulo" />
                        <p class="mt-1 line-clamp-2 text-sm font-black leading-5 text-slate-900">{{ o.nome }}</p>
                        <p class="mt-0.5 truncate text-xs font-bold uppercase tracking-wide text-purple-700">{{ o.nicho || 'Sem nicho' }} · {{ nomeIdioma(o.idioma) }}</p>
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ resumoPaises(o.paises ?? []) }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-bold">
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ o.anunciosAtivos }} anúncios</span>
                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ o.diasRodando }} dias</span>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ o.termometro.novos7d }} novos/7d</span>
                            <span v-if="preco(o)" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ preco(o) }}</span>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-col items-center">
                        <Termometro :pontuacao="o.termometro.pontuacao" :fase="o.termometro.fase" />
                        <span class="text-xs font-black text-slate-900">{{ pontos(o.termometro.pontuacao) }}</span>
                    </div>
                </Link>
                <div class="mt-3 flex justify-end">
                    <Link v-if="o.escolhidoId" :href="`/admin/pdf-oportunidades/escolhidos/${o.escolhidoId}`"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">
                        <i class="fas fa-check"></i> Clonado · ver
                    </Link>
                    <button v-else type="button" :disabled="clonando === o.id" @click="clonar(o)"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3 py-1.5 text-xs font-black text-white hover:bg-purple-700 disabled:opacity-60">
                        <i :class="clonando === o.id ? 'fas fa-spinner fa-spin' : 'fas fa-clone'"></i> {{ clonando === o.id ? 'Clonando…' : 'Clonar' }}
                    </button>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
