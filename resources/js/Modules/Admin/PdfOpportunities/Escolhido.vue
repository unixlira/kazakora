<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    escolhido: { type: Object, required: true },
    anuncios: { type: Array, default: () => [] },
});

const IDIOMAS = { es: 'Espanhol', en: 'Inglês', pt: 'Português', fr: 'Francês', de: 'Alemão', it: 'Italiano', ro: 'Romeno' };
const dados = computed(() => props.escolhido.dados ?? {});
const preco = computed(() => (props.escolhido.preco === null || props.escolhido.preco === undefined
    ? null
    : `${props.escolhido.moeda ?? ''} ${Number(props.escolhido.preco).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`.trim()));
const data = (iso) => (iso ? new Date(`${iso}T12:00:00`).toLocaleDateString('pt-BR') : '—');

const aba = ref('plano');
const copiado = ref(false);
const copiarPrompt = async () => {
    try {
        await navigator.clipboard.writeText(props.escolhido.prompt);
    } catch {
        const area = document.createElement('textarea');
        area.value = props.escolhido.prompt;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
    }
    copiado.value = true;
    setTimeout(() => { copiado.value = false; }, 2500);
};

const remover = () => {
    if (window.confirm(`Tirar "${props.escolhido.nome}" dos PDFs Escolhidos? Os criativos copiados e o plano são apagados.`)) {
        router.delete(`/admin/pdf-oportunidades/escolhidos/${props.escolhido.id}`);
    }
};
</script>

<template>
    <Head :title="escolhido.nome" />
    <AdminLayout>
        <Link href="/admin/pdf-oportunidades/escolhidos" class="inline-flex items-center gap-2 text-sm font-bold text-purple-700"><i class="fas fa-arrow-left"></i> Voltar aos PDFs Escolhidos</Link>

        <section class="mt-3 flex flex-col gap-4 rounded-3xl bg-white p-4 shadow-lg shadow-slate-200/70 sm:flex-row sm:p-6">
            <div class="mx-auto w-40 shrink-0 overflow-hidden rounded-2xl bg-slate-100 sm:mx-0">
                <img v-if="escolhido.miniatura" :src="escolhido.miniatura" alt="" class="aspect-[4/5] w-full object-cover" />
                <div v-else class="flex aspect-[4/5] items-center justify-center"><i class="fas fa-file-pdf text-4xl text-slate-300"></i></div>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-wide text-purple-700">#{{ dados.posicao ?? '—' }} no radar · {{ escolhido.nicho || 'Sem nicho' }} · {{ IDIOMAS[escolhido.idioma] ?? escolhido.idioma }}</p>
                <h1 class="mt-1 text-xl font-black text-slate-900 sm:text-2xl">{{ escolhido.nome }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ dados.anunciante }}<span v-if="dados.plataforma"> · {{ dados.plataforma }}</span><span v-if="dados.dominio"> · {{ dados.dominio }}</span></p>

                <dl class="mt-4 grid grid-cols-2 gap-2 text-sm lg:grid-cols-4">
                    <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Anúncios ativos</dt><dd class="mt-1 text-lg font-black text-emerald-700">{{ dados.anunciosAtivos ?? '—' }}</dd></div>
                    <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Rodando há</dt><dd class="mt-1 text-lg font-black text-amber-700">{{ dados.diasRodando ?? '—' }} dias</dd></div>
                    <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Preço lá fora</dt><dd class="mt-1 text-lg font-black text-slate-900">{{ preco ?? 'não visível' }}</dd></div>
                    <div class="rounded-2xl bg-slate-50 p-3"><dt class="text-xs font-bold uppercase text-slate-400">Variações</dt><dd class="mt-1 text-lg font-black text-slate-900">{{ dados.variacoes ?? '—' }}</dd></div>
                </dl>
                <p class="mt-3 text-sm font-semibold text-slate-600">
                    Países: {{ (dados.paises ?? []).join(' · ') || '—' }} · No ar desde {{ data(dados.inicioMaisAntigo) }} · Clonado em {{ escolhido.clonadoEm }}
                </p>
                <p v-if="dados.observacao" class="mt-2 rounded-2xl border-l-4 border-purple-500 bg-[var(--surface-muted)] p-3 text-sm leading-6 text-[var(--surface-foreground)]">{{ dados.observacao }}</p>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a v-if="dados.urlDestino" :href="dados.urlDestino" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-purple-700 px-4 py-2 text-sm font-black text-white">Página de vendas</a>
                    <a v-if="escolhido.paginaBiblioteca" :href="escolhido.paginaBiblioteca" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white">Anúncios do anunciante</a>
                    <Link v-if="escolhido.ofertaId" :href="`/admin/pdf-oportunidades/${escolhido.ofertaId}`" class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-black text-slate-700">Oferta no radar</Link>
                    <button type="button" @click="remover" class="rounded-xl bg-red-50 px-4 py-2 text-sm font-black text-red-700">Remover</button>
                </div>
            </div>
        </section>

        <nav class="mt-5 flex gap-2 overflow-x-auto">
            <button v-for="t in [['plano', 'Plano de ação'], ['prompt', 'Prompt do entregável'], ['criativos', `Criativos (${anuncios.length})`]]" :key="t[0]" type="button"
                @click="aba = t[0]"
                :class="aba === t[0] ? 'bg-slate-900 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'"
                class="shrink-0 rounded-xl px-4 py-2 text-sm font-black">{{ t[1] }}</button>
        </nav>

        <section v-if="aba === 'plano'" class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
            <article v-for="etapa in escolhido.planoAcao" :key="etapa.titulo" class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
                <h2 class="text-base font-black text-slate-900">{{ etapa.titulo }}</h2>
                <ul class="mt-2 space-y-2">
                    <li v-for="(item, i) in etapa.itens" :key="i" class="flex gap-2 text-sm leading-6 text-slate-700">
                        <i class="fas fa-circle-check mt-1.5 text-xs text-purple-600"></i><span>{{ item }}</span>
                    </li>
                </ul>
            </article>
        </section>

        <section v-else-if="aba === 'prompt'" class="mt-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-slate-600">Cole no Claude (ou Gemini) para escrever o PDF completo, em português e com conteúdo original.</p>
                <button type="button" @click="copiarPrompt" class="rounded-xl bg-purple-700 px-4 py-2 text-sm font-black text-white">
                    <i :class="copiado ? 'fas fa-check' : 'fas fa-copy'"></i> {{ copiado ? 'Copiado' : 'Copiar prompt' }}
                </button>
            </div>
            <pre class="mt-3 max-h-[70vh] overflow-auto whitespace-pre-wrap break-words rounded-xl bg-slate-950 p-4 font-mono text-xs leading-6 text-slate-100">{{ escolhido.prompt }}</pre>
        </section>

        <section v-else class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <p v-if="!anuncios.length" class="rounded-2xl bg-white p-6 text-sm text-slate-500 shadow">Nenhum criativo foi copiado desta oferta.</p>
            <article v-for="a in anuncios" :key="a.adId" class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
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
