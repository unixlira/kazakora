<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    escolhidos: { type: Array, default: () => [] },
});

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

        <section class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article v-for="e in escolhidos" :key="e.id" class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <div class="relative aspect-[4/5] bg-slate-100">
                    <img v-if="e.miniatura" :src="e.miniatura" alt="" loading="lazy" class="h-full w-full object-cover" />
                    <i v-else class="fas fa-file-pdf absolute inset-0 m-auto h-10 w-10 text-4xl text-slate-300"></i>
                    <Link :href="`/admin/pdf-oportunidades/escolhidos/${e.id}`" title="Ver tudo"
                        class="absolute right-2 top-2 flex h-10 w-10 items-center justify-center rounded-full bg-white/95 text-slate-900 shadow-lg ring-1 ring-slate-200 transition hover:bg-purple-700 hover:text-white">
                        <i class="fas fa-eye"></i>
                    </Link>
                </div>
                <div class="flex flex-1 flex-col p-3">
                    <p class="line-clamp-2 text-sm font-black leading-5 text-slate-900">{{ e.nome }}</p>
                    <p class="mt-0.5 truncate text-xs font-bold uppercase tracking-wide text-purple-700">{{ e.nicho || 'Sem nicho' }} · {{ nomeIdioma(e.idioma) }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5 text-xs font-bold">
                        <span v-if="e.anunciosAtivos !== null" class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ e.anunciosAtivos }} anúncios</span>
                        <span v-if="e.diasRodando !== null" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ e.diasRodando }} dias</span>
                        <span v-if="preco(e)" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ preco(e) }}</span>
                        <span class="rounded-full bg-purple-50 px-2 py-0.5 text-purple-700">{{ e.totalMidias }} criativos</span>
                    </div>
                    <p class="mt-auto pt-3 text-xs text-slate-400">Clonado em {{ e.clonadoEm }}</p>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
