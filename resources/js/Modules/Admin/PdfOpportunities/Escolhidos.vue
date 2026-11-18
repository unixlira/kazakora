<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SeloFase from '@/Shared/Components/SeloFase.vue';
import Termometro from '@/Shared/Components/Termometro.vue';

const props = defineProps({
    escolhidos: { type: Array, default: () => [] },
    fases: { type: Object, default: () => ({}) },
});

const TODAS = 'todas';
const fase = ref(TODAS);
const ordem = ref('recentes');
const visiveis = computed(() => {
    const lista = props.escolhidos.filter((e) => fase.value === TODAS || e.termometro.fase === fase.value);
    return ordem.value === 'termometro'
        ? [...lista].sort((a, b) => b.termometro.pontuacao - a.termometro.pontuacao || b.termometro.novos7d - a.termometro.novos7d)
        : lista;
});
const pontos = (n) => Number(n ?? 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 });

const IDIOMAS = { es: 'Espanhol', en: 'Inglês', pt: 'Português', fr: 'Francês', de: 'Alemão', it: 'Italiano', ro: 'Romeno' };
const nomeIdioma = (codigo) => IDIOMAS[codigo] ?? (codigo || '—');
const preco = (e) => (e.preco === null || e.preco === undefined ? null : `${e.moeda ?? ''} ${Number(e.preco).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim());
</script>

<template>
    <Head title="PDFs Escolhidos" />
    <AdminLayout>
        <Link href="/admin/pdf-oportunidades" class="inline-flex items-center gap-2 text-sm font-bold text-purple-700"><i class="fas fa-arrow-left"></i> Voltar ao radar</Link>

        <section class="mt-3 rounded-3xl bg-[#17110d] px-5 py-6 text-white shadow-xl sm:px-8">
            <p class="text-xs font-bold uppercase tracking-[0.2em] text-purple-200">Oportunidades em PDF · clonadas</p>
            <h1 class="mt-2 text-2xl font-black sm:text-3xl">PDFs Escolhidos</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-purple-50/80">
                Ofertas que você clonou do radar. Cada uma guarda uma cópia dos dados e dos criativos, o plano de ação e o prompt que escreve o nosso PDF.
                Clique no olho para ver tudo.
            </p>
        </section>

        <p v-if="!escolhidos.length" class="mt-6 rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">
            Nenhum PDF escolhido ainda. No radar, use o botão <b>Clonar</b> no card da oferta.
        </p>

        <section v-if="escolhidos.length" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <select v-model="fase" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                <option :value="TODAS">Todas as fases</option>
                <option v-for="(f, chave) in fases" :key="chave" :value="chave">{{ f.rotulo }}</option>
            </select>
            <select v-model="ordem" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                <option value="recentes">Clonados mais recentes</option>
                <option value="termometro">Termômetro (mais quente primeiro)</option>
            </select>
        </section>
        <p v-if="escolhidos.length && !visiveis.length" class="mt-4 rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">Nenhum PDF escolhido nessa fase.</p>

        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article v-for="e in visiveis" :key="e.id" class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <div class="relative aspect-[4/5] bg-slate-100">
                    <img v-if="e.miniatura" :src="e.miniatura" alt="" loading="lazy" class="h-full w-full object-cover" />
                    <i v-else class="fas fa-file-pdf absolute inset-0 m-auto h-10 w-10 text-4xl text-slate-300"></i>
                    <Link :href="`/admin/pdf-oportunidades/escolhidos/${e.id}`" title="Ver tudo"
                        class="absolute right-2 top-2 flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-slate-900 shadow-lg ring-1 ring-slate-200 transition hover:bg-purple-700 hover:text-white">
                        <i class="fas fa-eye"></i>
                    </Link>
                </div>
                <div class="flex flex-1 flex-col p-3">
                    <div class="flex items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <SeloFase :pontuacao="e.termometro.pontuacao" :fase="e.termometro.fase" :rotulo="fases[e.termometro.fase]?.rotulo" />
                            <p class="mt-1 line-clamp-2 text-sm font-black leading-5 text-slate-900">{{ e.nome }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col items-center">
                            <Termometro :pontuacao="e.termometro.pontuacao" :fase="e.termometro.fase" />
                            <span class="text-xs font-black text-slate-900">{{ pontos(e.termometro.pontuacao) }}</span>
                        </div>
                    </div>
                    <p class="mt-0.5 truncate text-xs font-bold uppercase tracking-wide text-purple-700">{{ e.nicho || 'Sem nicho' }} · {{ nomeIdioma(e.idioma) }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-bold">
                        <span v-if="e.anunciosAtivos !== null" class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ e.anunciosAtivos }} anúncios</span>
                        <span v-if="e.diasRodando !== null" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ e.diasRodando }} dias</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ e.termometro.novos7d }} novos/7d</span>
                        <span v-if="preco(e)" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ preco(e) }}</span>
                        <span class="rounded-full bg-purple-50 px-2 py-0.5 text-purple-700">{{ e.totalMidias }} criativos</span>
                    </div>
                    <p class="mt-auto pt-3 text-xs text-slate-400">Clonado em {{ e.clonadoEm }}</p>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
