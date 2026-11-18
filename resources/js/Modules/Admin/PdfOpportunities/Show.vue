<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    oferta: { type: Object, required: true },
    anuncios: { type: Array, default: () => [] },
});

const IDIOMAS = { es: 'Espanhol', en: 'Inglês', pt: 'Português', fr: 'Francês', de: 'Alemão', it: 'Italiano', ro: 'Romeno' };
const preco = computed(() => (props.oferta.preco === null || props.oferta.preco === undefined
    ? null
    : `${props.oferta.moeda ?? ''} ${Number(props.oferta.preco).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim()));
const clonando = ref(false);
const clonar = () => {
    clonando.value = true;
    router.post(`/admin/pdf-oportunidades/${props.oferta.id}/clonar`, {}, { preserveScroll: true, onFinish: () => { clonando.value = false; } });
};
const totalMidias = computed(() => props.anuncios.reduce((n, a) => n + a.midias.length, 0));
</script>

<template>
    <Head :title="oferta.nome" />
    <AdminLayout>
        <Link href="/admin/pdf-oportunidades" class="inline-flex items-center gap-2 text-sm font-bold text-purple-700"><i class="fas fa-arrow-left"></i> Voltar ao ranking</Link>

        <section class="mt-3 rounded-3xl bg-white p-4 shadow-lg shadow-slate-200/70 sm:p-6">
            <p class="text-xs font-bold uppercase tracking-wide text-purple-700">#{{ oferta.posicao ?? '—' }} · {{ oferta.nicho || 'Sem nicho' }} · {{ IDIOMAS[oferta.idioma] ?? oferta.idioma }}</p>
            <h1 class="mt-1 text-xl font-black text-slate-900 sm:text-2xl">{{ oferta.nome }}</h1>
            <p class="mt-1 text-sm text-slate-500">{{ oferta.anunciante }}<span v-if="oferta.plataforma"> · {{ oferta.plataforma }}</span><span v-if="oferta.dominio"> · {{ oferta.dominio }}</span></p>

            <dl class="mt-4 grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Anúncios ativos</dt><dd class="mt-1 text-lg font-black text-emerald-700">{{ oferta.anunciosAtivos }}</dd></div>
                <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Rodando há</dt><dd class="mt-1 text-lg font-black text-amber-700">{{ oferta.diasRodando }} dias</dd></div>
                <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Preço</dt><dd class="mt-1 text-lg font-black text-slate-900">{{ preco ?? 'não visível' }}</dd></div>
                <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Desde</dt><dd class="mt-1 text-lg font-black text-slate-900">{{ oferta.inicioMaisAntigo ?? '—' }}</dd></div>
            </dl>
            <p class="mt-3 text-xs font-semibold text-slate-500">Países: {{ (oferta.paises ?? []).join(' · ') || '—' }}</p>
            <p v-if="oferta.observacao" class="mt-2 rounded-2xl bg-purple-50 p-3 text-sm leading-6 text-purple-950">{{ oferta.observacao }}</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <Link v-if="oferta.escolhidoId" :href="`/admin/pdf-oportunidades/escolhidos/${oferta.escolhidoId}`" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white"><i class="fas fa-check"></i> Clonado · ver plano</Link>
                <button v-else type="button" :disabled="clonando" @click="clonar" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white disabled:opacity-60"><i :class="clonando ? 'fas fa-spinner fa-spin' : 'fas fa-clone'"></i> {{ clonando ? 'Clonando…' : 'Clonar' }}</button>
                <a v-if="oferta.urlDestino" :href="oferta.urlDestino" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-purple-700 px-4 py-2 text-sm font-black text-white">Página de vendas</a>
                <a v-if="oferta.paginaBiblioteca" :href="oferta.paginaBiblioteca" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white">Todos os anúncios do anunciante</a>
            </div>
        </section>

        <h2 class="mt-6 text-lg font-black text-slate-900">Criativos ({{ anuncios.length }} anúncios · {{ totalMidias }} mídias)</h2>

        <section class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="a in anuncios" :key="a.id" class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <div v-for="(m, i) in a.midias" :key="i" class="bg-black">
                    <video v-if="m.tipo === 'video'" :src="m.url" :poster="m.poster || undefined" controls preload="none" playsinline class="max-h-[520px] w-full"></video>
                    <img v-else :src="m.url" alt="" loading="lazy" class="max-h-[520px] w-full object-contain" />
                </div>
                <p v-if="!a.midias.length" class="bg-slate-50 p-4 text-center text-xs text-slate-400">Mídia não baixada</p>
                <div class="flex flex-1 flex-col p-3">
                    <div class="flex flex-wrap gap-1.5 text-xs font-bold">
                        <span v-if="a.diasRodando !== null" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ a.diasRodando }} dias · desde {{ a.iniciadoEm }}</span>
                        <span v-if="a.variacoes > 1" class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ a.variacoes }} anúncios usam este criativo</span>
                    </div>
                    <p v-if="a.titulo" class="mt-2 text-sm font-black text-slate-900">{{ a.titulo }}</p>
                    <p v-if="a.texto" class="mt-1 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ a.texto }}</p>
                    <p v-if="a.cta" class="mt-2 text-xs font-bold uppercase text-slate-400">Botão: {{ a.cta }}</p>
                    <div class="mt-auto flex flex-wrap gap-2 pt-3">
                        <a :href="a.link" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-black text-white">Ver na Biblioteca</a>
                        <a v-if="a.urlDestino" :href="a.urlDestino" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-purple-50 px-3 py-2 text-xs font-black text-purple-700">Destino do anúncio</a>
                    </div>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
