<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import SeloFase from '@/Shared/Components/SeloFase.vue';
import Termometro from '@/Shared/Components/Termometro.vue';

const props = defineProps({
    oportunidade: { type: Object, required: true },
    fases: { type: Object, default: () => ({}) },
    anuncios: { type: Array, default: () => [] },
    criativos: { type: Array, default: () => [] },
    maximoPorLote: { type: Number, default: 10 },
});

const o = computed(() => props.oportunidade);
const CORES_STATUS = { roteirizando: 'bg-blue-50 text-blue-700', roteiro_pronto: 'bg-amber-50 text-amber-700', produzindo: 'bg-blue-50 text-blue-700', pronto: 'bg-emerald-50 text-emerald-700', aprovado: 'bg-emerald-50 text-emerald-700', reprovado: 'bg-slate-100 text-slate-600', erro: 'bg-red-50 text-red-700' };
const numero = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString('pt-BR'));
const preco = computed(() => (o.value.preco === null || o.value.preco === undefined ? null : `${o.value.moeda ?? ''} ${Number(o.value.preco).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}`.trim()));

const metricas = computed(() => [
    { rotulo: 'Anúncios ativos', valor: numero(o.value.anunciosAtivos) },
    { rotulo: 'Dias no ar', valor: numero(o.value.diasRodando), dica: o.value.inicioMaisAntigo ? `desde ${o.value.inicioMaisAntigo}` : null },
    { rotulo: 'Variações ativas', valor: numero(o.value.variacoes) },
    { rotulo: 'Criativos novos (7 dias)', valor: numero(o.value.criativosNovos7d) },
    { rotulo: 'Anunciantes vendendo', valor: numero(o.value.anunciantesDistintos) },
    { rotulo: 'Alcance na UE', valor: o.value.alcanceUe ? numero(o.value.alcanceUe) : 'fora da UE' },
]);

// Clonar um anúncio
const clonando = ref(null);
const clonar = (a) => {
    clonando.value = a.id;
    router.post(`/admin/oportunidades/kazakora/anuncios/${a.id}/clonar`, {}, { preserveScroll: true, onFinish: () => { clonando.value = null; } });
};

// Criar do zero
const quantidade = ref(3);
const criando = ref(false);
const criar = () => {
    criando.value = true;
    router.post(`/admin/oportunidades/kazakora/${o.value.id}/criativos`, { quantidade: quantidade.value }, { preserveScroll: true, onFinish: () => { criando.value = false; } });
};

const refazer = (c) => router.post(`/admin/oportunidades/kazakora/criativos/${c.id}/refazer`, {}, { preserveScroll: true });

// Enquanto houver roteiro sendo gerado, atualiza a lista sozinha.
const aberto = ref(null);
let timer = null;
const gerando = computed(() => props.criativos.some((c) => c.status === 'roteirizando'));
const vigiar = () => {
    clearInterval(timer);
    if (gerando.value) {
        timer = setInterval(() => router.reload({ only: ['criativos', 'anuncios'], preserveScroll: true }), 6000);
    }
};
onMounted(vigiar);
watch(gerando, vigiar);
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head :title="o.nome" />
    <AdminLayout>
        <Link href="/admin/oportunidades/kazakora" class="inline-flex items-center gap-2 text-sm font-bold text-orange-700"><i class="fas fa-arrow-left"></i> Voltar às oportunidades</Link>

        <section class="mt-3 flex flex-col gap-4 rounded-3xl bg-white p-4 shadow-lg shadow-slate-200/70 sm:flex-row sm:p-6">
            <div class="mx-auto w-36 shrink-0 overflow-hidden rounded-2xl bg-slate-100 sm:mx-0">
                <img v-if="o.miniatura" :src="o.miniatura" alt="" class="aspect-[4/5] w-full object-cover" />
                <div v-else class="flex aspect-[4/5] items-center justify-center"><i class="fas fa-box text-4xl text-slate-300"></i></div>
            </div>
            <div class="min-w-0 flex-1">
                <SeloFase :pontuacao="o.pontuacao" :fase="o.fase" :rotulo="fases[o.fase]?.rotulo" />
                <h1 class="mt-2 text-xl font-black text-slate-900 sm:text-2xl">{{ o.nome }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ o.nicho || 'Sem nicho' }}<span v-if="o.anunciante"> · {{ o.anunciante }}</span><span v-if="o.dominio"> · {{ o.dominio }}</span><span v-if="preco"> · {{ preco }} lá</span></p>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ fases[o.fase]?.descricao }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-2 text-sm lg:grid-cols-3">
                    <div v-for="m in metricas" :key="m.rotulo" class="rounded-2xl bg-slate-50 p-3">
                        <dt class="text-xs font-bold uppercase text-slate-500">{{ m.rotulo }}</dt>
                        <dd class="mt-1 text-lg font-black text-slate-900">{{ m.valor }}</dd>
                        <dd v-if="m.dica" class="text-xs text-slate-500">{{ m.dica }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-sm font-semibold text-slate-600">Países: {{ (o.paises ?? []).join(' · ') || '—' }}</p>
                <p v-if="o.observacao" class="mt-2 rounded-2xl border-l-4 border-orange-500 bg-[var(--surface-muted)] p-3 text-sm leading-6 text-[var(--surface-foreground)]">{{ o.observacao }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a v-if="o.urlDestino" :href="o.urlDestino" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-orange-600 px-4 py-2 text-sm font-black text-white">Página do anunciante</a>
                    <a v-if="o.paginaBiblioteca" :href="o.paginaBiblioteca" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-black text-white">Todos os anúncios dele</a>
                </div>
            </div>
            <div class="flex shrink-0 flex-col items-center justify-center rounded-2xl bg-slate-50 px-4 py-3">
                <Termometro :pontuacao="o.pontuacao" :fase="o.fase" grande />
                <p class="mt-1 text-3xl font-black text-slate-900">{{ numero(o.pontuacao) }}</p>
                <p class="text-xs font-bold uppercase text-slate-500">de 100</p>
            </div>
        </section>

        <!-- Fábrica: criar do zero -->
        <section class="mt-5 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 sm:flex-row sm:items-center">
            <div class="flex-1">
                <h2 class="text-base font-black text-slate-900">Criar criativos do zero</h2>
                <p class="mt-1 text-sm leading-6 text-slate-600">
                    Cada um sai com conceito diferente: gancho, roteiro segundo a segundo, retenção, CTA, legenda, narração, música, texto do anúncio e a lista de takes reais a filmar.
                </p>
            </div>
            <form class="flex items-center gap-2" @submit.prevent="criar">
                <label class="text-xs font-bold uppercase text-slate-500" for="qtd">Quantos</label>
                <input id="qtd" v-model.number="quantidade" type="number" min="1" :max="maximoPorLote" class="w-20 rounded-xl border border-slate-200 bg-white px-3 py-2 text-center text-sm font-black text-slate-900" />
                <button type="submit" :disabled="criando || quantidade < 1 || quantidade > maximoPorLote" class="rounded-xl bg-orange-600 px-4 py-2 text-sm font-black text-white disabled:opacity-60">
                    <i :class="criando ? 'fas fa-spinner fa-spin' : 'fas fa-wand-magic-sparkles'"></i> Criar
                </button>
            </form>
        </section>

        <!-- Nossos criativos -->
        <section v-if="criativos.length" class="mt-5">
            <h2 class="text-lg font-black text-slate-900">Nossos criativos ({{ criativos.length }})</h2>
            <div class="mt-3 space-y-2">
                <article v-for="c in criativos" :key="c.id" class="rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                    <button type="button" class="flex w-full flex-wrap items-center gap-2 p-3 text-left" @click="aberto = aberto === c.id ? null : c.id">
                        <span class="font-mono text-sm font-black text-slate-900">{{ c.codigo }}</span>
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold" :class="CORES_STATUS[c.status]">
                            <i v-if="c.status === 'roteirizando'" class="fas fa-spinner fa-spin"></i> {{ c.statusRotulo }}
                        </span>
                        <span class="text-xs font-semibold text-slate-500">{{ c.origem === 'clone' ? `clone do anúncio ${c.referenciaAdId}` : `do zero · ${c.lote}` }} · {{ c.criadoEm }}</span>
                        <span v-if="c.briefing?.titulo" class="min-w-0 flex-1 truncate text-sm font-bold text-slate-700">{{ c.briefing.titulo }}</span>
                        <i class="fas fa-chevron-down ml-auto text-xs text-slate-500 transition-transform" :class="{ 'rotate-180': aberto === c.id }"></i>
                    </button>
                    <div v-if="aberto === c.id" class="border-t border-slate-100 p-4 text-sm leading-6 text-slate-700">
                        <p v-if="c.erro" class="rounded-xl bg-red-50 p-3 text-red-700">{{ c.erro }} <button type="button" class="ml-2 font-black underline" @click="refazer(c)">Tentar de novo</button></p>
                        <p v-else-if="!c.briefing" class="text-slate-500">Gerando o roteiro…</p>
                        <template v-else>
                            <p class="font-semibold text-slate-900">{{ c.briefing.conceito }}</p>
                            <p class="mt-1 text-xs text-slate-500">Ângulo: {{ c.briefing.angulo }} · Formato: {{ c.briefing.formato }} · {{ c.briefing.duracao_s }} s · {{ c.briefing.proporcao }} · Público: {{ c.briefing.publico }}</p>
                            <p v-if="c.pasta" class="mt-1 font-mono text-xs text-slate-500">Servidor: {{ c.pasta }}/</p>

                            <div class="mt-3 grid gap-3 lg:grid-cols-3">
                                <div class="rounded-xl bg-slate-50 p-3 lg:col-span-1">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Gancho (0-3 s)</h3>
                                    <p class="mt-1"><b>Fala:</b> {{ c.briefing.gancho?.fala }}</p>
                                    <p><b>Tela:</b> {{ c.briefing.gancho?.texto_tela }}</p>
                                    <p><b>Visual:</b> {{ c.briefing.gancho?.visual }}</p>
                                    <h3 class="mt-3 text-xs font-black uppercase text-slate-500">Ganchos para testar</h3>
                                    <p v-for="(g, i) in c.briefing.ganchos_alternativos ?? []" :key="i" class="mt-1">{{ i + 2 }}. {{ g.fala }} <span class="text-slate-500">| {{ g.texto_tela }}</span></p>
                                </div>
                                <div class="overflow-x-auto rounded-xl bg-slate-50 p-3 lg:col-span-2">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Roteiro</h3>
                                    <table class="mt-1 w-full text-left text-xs">
                                        <thead class="text-slate-500"><tr><th class="py-1 pr-2">Tempo</th><th class="pr-2">Etapa</th><th class="pr-2">Visual</th><th class="pr-2">Fala</th><th>Tela</th></tr></thead>
                                        <tbody>
                                            <tr v-for="(cena, i) in c.briefing.roteiro ?? []" :key="i" class="border-t border-slate-200 align-top">
                                                <td class="py-1 pr-2 font-mono">{{ cena.de_s }}-{{ cena.ate_s }}s</td>
                                                <td class="pr-2 font-bold">{{ cena.etapa }}</td>
                                                <td class="pr-2">{{ cena.visual }}</td>
                                                <td class="pr-2">{{ cena.fala }}</td>
                                                <td>{{ cena.texto_tela }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">CTA</h3>
                                    <p class="mt-1"><b>Fala:</b> {{ c.briefing.cta?.fala }}</p>
                                    <p><b>Tela:</b> {{ c.briefing.cta?.texto_tela }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Retenção</h3>
                                    <p v-for="(r, i) in c.briefing.retencao ?? []" :key="i" class="mt-1">• {{ r }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Legenda</h3>
                                    <p class="mt-1">{{ c.briefing.legenda?.estilo }}</p>
                                    <p class="text-slate-500">{{ c.briefing.legenda?.posicao }} · {{ c.briefing.legenda?.palavras_por_tela }} palavras por tela</p>
                                    <p v-if="c.briefing.legenda?.destaques" class="text-slate-500">Destaques: {{ c.briefing.legenda.destaques }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Música</h3>
                                    <p class="mt-1">{{ c.briefing.musica?.estilo }} · {{ c.briefing.musica?.bpm }} BPM</p>
                                    <p class="text-slate-500">{{ c.briefing.musica?.volume }} · {{ c.briefing.musica?.onde_buscar }}</p>
                                </div>
                            </div>

                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Narração</h3>
                                    <p class="mt-1 text-xs text-slate-500">{{ c.briefing.narracao?.voz }} · {{ c.briefing.narracao?.tom }} · {{ c.briefing.narracao?.velocidade }}</p>
                                    <p class="mt-1 whitespace-pre-line">{{ c.briefing.narracao?.texto }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Texto do anúncio</h3>
                                    <p class="mt-1 whitespace-pre-line">{{ c.briefing.texto_anuncio?.principal }}</p>
                                    <p class="mt-1"><b>Título:</b> {{ c.briefing.texto_anuncio?.titulo }}</p>
                                    <p><b>Descrição:</b> {{ c.briefing.texto_anuncio?.descricao }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Takes reais necessários</h3>
                                    <p v-for="(t, i) in c.briefing.takes_necessarios ?? []" :key="i" class="mt-1">• {{ t }}</p>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-3">
                                    <h3 class="text-xs font-black uppercase text-orange-700">Checklist</h3>
                                    <p v-for="(t, i) in c.briefing.checklist ?? []" :key="i" class="mt-1">✓ {{ t }}</p>
                                    <p v-if="c.briefing.o_que_veio_da_referencia" class="mt-2 text-slate-500"><b>Da referência:</b> {{ c.briefing.o_que_veio_da_referencia }}</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </article>
            </div>
        </section>

        <!-- Criativos veiculados -->
        <h2 class="mt-6 text-lg font-black text-slate-900">Criativos veiculados ({{ anuncios.length }})</h2>
        <p v-if="!anuncios.length" class="mt-3 rounded-2xl bg-white p-6 text-sm text-slate-600 shadow">Nenhum criativo baixado desta oferta.</p>
        <section class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="a in anuncios" :key="a.id" class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-slate-100">
                <div v-for="(m, i) in a.midias" :key="i" class="bg-black">
                    <video v-if="m.tipo === 'video'" :src="m.url" :poster="m.poster || undefined" controls preload="none" playsinline class="max-h-[520px] w-full"></video>
                    <img v-else :src="m.url" alt="" loading="lazy" class="max-h-[520px] w-full object-contain" />
                </div>
                <p v-if="!a.midias.length" class="bg-slate-50 p-4 text-center text-xs text-slate-500">Mídia não baixada</p>
                <div class="flex flex-1 flex-col p-3">
                    <div class="flex flex-wrap gap-1.5 text-xs font-bold">
                        <span v-if="a.diasRodando !== null" class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{{ a.diasRodando }} dias · desde {{ a.iniciadoEm }}</span>
                        <span v-if="a.variacoes > 1" class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{{ a.variacoes }} anúncios usam este criativo</span>
                        <span v-if="a.alcanceUe" class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{{ numero(a.alcanceUe) }} alcance UE</span>
                    </div>
                    <p v-if="a.titulo" class="mt-2 text-sm font-black text-slate-900">{{ a.titulo }}</p>
                    <p v-if="a.texto" class="mt-1 line-clamp-6 whitespace-pre-line break-words text-sm leading-6 text-slate-600">{{ a.texto }}</p>
                    <p v-if="a.cta" class="mt-2 text-xs font-bold uppercase text-slate-500">Botão: {{ a.cta }}</p>
                    <p v-if="a.clones.length" class="mt-2 text-xs font-bold text-orange-700">Já clonado: {{ a.clones.join(', ') }}</p>
                    <div class="mt-auto flex flex-wrap gap-2 pt-3">
                        <button type="button" :disabled="clonando === a.id" @click="clonar(a)" class="rounded-xl bg-orange-600 px-3 py-2 text-xs font-black text-white disabled:opacity-60">
                            <i :class="clonando === a.id ? 'fas fa-spinner fa-spin' : 'fas fa-clone'"></i> Clonar
                        </button>
                        <a :href="a.link" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-black text-white">Ver na Biblioteca</a>
                    </div>
                </div>
            </article>
        </section>
    </AdminLayout>
</template>
