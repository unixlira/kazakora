<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { formatarData, formatarNumero, formatarReais, STATUS_ESTILOS } from './metaAds';

const props = defineProps({
    campanha: { type: Object, required: true },
    videos: { type: Array, default: () => [] },
    erroDesempenho: { type: String, default: null },
    config: { type: Object, required: true },
    problemas: { type: Array, default: () => [] },
    pode: { type: Object, required: true },
    linkGerenciador: { type: String, default: null },
    chamadas: { type: Object, required: true },
    generos: { type: Object, required: true },
});

const { can } = usePermissions();
const c = computed(() => props.campanha);
const base = computed(() => `/admin/publicidade/meta-ads/campanhas/${c.value.id}`);
const orcamento = computed(() => formatarReais(Number(c.value.orcamento_diario)));
const periodo = computed(() => (c.value.fim
    ? `${formatarData(c.value.inicio)} a ${formatarData(c.value.fim)}`
    : `a partir de ${formatarData(c.value.inicio)}, sem data de fim`));

const locais = computed(() => {
    const l = c.value.localizacoes ?? {};
    const nomes = [
        ...(l.regioes ?? []).map((r) => r.nome),
        ...(l.cidades ?? []).map((cid) => (cid.raio_km ? `${cid.nome} (+${cid.raio_km} km)` : cid.nome)),
    ];
    return nomes.length ? nomes.join(', ') : 'Brasil inteiro';
});

const processando = ref(false);
const opcoes = { preserveScroll: true, onStart: () => { processando.value = true; }, onFinish: () => { processando.value = false; } };

const enviar = () => router.post(`${base.value}/enviar`, {}, opcoes);

const modalAtivar = ref(false);
const confirmoGasto = ref(false);
const ativar = () => router.post(`${base.value}/ativar`, {
    confirmo_gasto: confirmoGasto.value,
    orcamento_diario: Number(c.value.orcamento_diario),
}, { ...opcoes, onSuccess: () => { modalAtivar.value = false; confirmoGasto.value = false; } });

const modalPausar = ref(false);
const pausar = () => router.post(`${base.value}/pausar`, {}, { ...opcoes, onSuccess: () => { modalPausar.value = false; } });

const excluir = async () => {
    if (await confirmDelete({ title: `Excluir o rascunho "${c.value.nome}"?` })) {
        router.delete(base.value);
    }
};

// Enquanto envia, a tela se atualiza sozinha a cada 5 s para mostrar o
// andamento de cada vídeo.
let timer = null;
const acompanhar = (status) => {
    clearInterval(timer);
    if (status === 'enviando') {
        timer = setInterval(() => router.reload({ only: ['campanha', 'videos', 'pode', 'problemas', 'linkGerenciador'] }), 5000);
    }
};
watch(() => c.value.status, acompanhar, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));

const enviados = computed(() => props.videos.filter((v) => v.enviado).length);
const comResultado = computed(() => props.videos.some((v) => v.desempenho));
const roas = (d) => (d && d.gasto > 0 && d.vendas > 0 ? `${(d.vendas / d.gasto).toFixed(2).replace('.', ',')}x` : '-');

const botao = 'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <Head :title="`Campanha Meta: ${c.nome}`" />

    <AdminLayout>
        <div class="mb-2">
            <Link href="/admin/publicidade/meta-ads/campanhas" class="text-sm font-medium text-primary hover:underline">Voltar para a lista</Link>
        </div>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold">{{ c.nome }}</h1>
                <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="STATUS_ESTILOS[c.status]">{{ c.status_rotulo }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link v-if="pode.editar && can('financeiro.create')" :href="`${base}/editar`"
                    :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']">
                    <i class="fas fa-pen text-xs" aria-hidden="true"></i> Editar
                </Link>
                <button v-if="pode.enviar && can('financeiro.create')" type="button" :disabled="processando || problemas.length > 0"
                    :class="[botao, 'bg-primary text-white hover:bg-primary-emphasis']" @click="enviar">
                    <i class="fas fa-paper-plane text-xs" aria-hidden="true"></i>
                    {{ c.status === 'erro' ? 'Tentar enviar de novo' : 'Enviar para a Meta (pausada)' }}
                </button>
                <button v-if="pode.ativar && can('financeiro.create')" type="button" :disabled="processando || !config.configurado"
                    :class="[botao, 'bg-emerald-600 text-white hover:bg-emerald-700']" @click="modalAtivar = true">
                    <i class="fas fa-play text-xs" aria-hidden="true"></i> Ativar na Meta
                </button>
                <button v-if="pode.pausar && can('financeiro.create')" type="button" :disabled="processando || !config.configurado"
                    :class="[botao, 'bg-amber-500 text-white hover:bg-amber-600']" @click="modalPausar = true">
                    <i class="fas fa-pause text-xs" aria-hidden="true"></i> Pausar
                </button>
                <button v-if="pode.excluir && can('financeiro.create')" type="button"
                    :class="[botao, 'border border-red-300 text-red-700 hover:bg-red-50']" @click="excluir">
                    <i class="fas fa-trash text-xs" aria-hidden="true"></i> Excluir
                </button>
            </div>
        </div>

        <div v-if="!config.configurado" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <strong>Meta não configurada.</strong> Faltam no servidor: {{ config.faltando.join(', ') }}.
        </div>
        <div v-else-if="problemas.length" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <strong>Antes de enviar:</strong>
            <ul class="ml-5 list-disc"><li v-for="p in problemas" :key="p">{{ p }}</li></ul>
        </div>
        <div v-if="c.ultimo_erro" class="mb-4 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-800" role="alert">
            <strong>Erro da Meta:</strong> {{ c.ultimo_erro }}
            <p v-if="c.status === 'erro'" class="mt-1 text-xs">Ao tentar de novo, o envio continua do passo que faltou. O que já foi criado não é duplicado.</p>
        </div>
        <div v-if="c.status === 'enviando'" class="mb-4 flex items-center gap-3 rounded-xl border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900" role="status" aria-live="polite">
            <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
            <span>Enviando para a Meta: {{ enviados }} de {{ videos.length }} vídeo(s) prontos. A Meta leva alguns minutos para processar cada vídeo. Pode sair desta tela.</span>
        </div>
        <div v-if="c.status === 'enviada_pausada'" class="mb-4 rounded-xl border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900" role="status">
            Campanha na Meta e <strong>pausada</strong>. Não gasta nada até você clicar em Ativar.
            Antes, dá para conferir os anúncios no Gerenciador.
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section class="space-y-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm lg:col-span-2">
                <h2 class="text-lg font-semibold">Resumo</h2>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Objetivo</dt><dd class="font-medium">{{ c.objetivo_rotulo }}</dd></div>
                    <div><dt class="text-slate-500">Orçamento por dia</dt><dd class="font-medium">{{ orcamento }}<span v-if="videos.length > 1" class="font-normal text-slate-500"> dividido entre {{ videos.length }} vídeos</span></dd></div>
                    <div><dt class="text-slate-500">Período</dt><dd class="font-medium">{{ periodo }}</dd></div>
                    <div><dt class="text-slate-500">Público</dt><dd class="font-medium">{{ c.idade_min }} a {{ c.idade_max }} anos, {{ generos[c.genero]?.toLowerCase() }}</dd></div>
                    <div><dt class="text-slate-500">Onde</dt><dd class="font-medium">{{ locais }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">Destino</dt>
                        <dd class="break-all font-medium"><a :href="c.link_destino" target="_blank" rel="noopener" class="text-primary hover:underline">{{ c.link_destino }}</a></dd></div>
                    <div v-if="c.produto"><dt class="text-slate-500">Produto</dt><dd class="font-medium">{{ c.produto.name }}</dd></div>
                    <div v-if="c.user"><dt class="text-slate-500">Criada por</dt><dd class="font-medium">{{ c.user.name }}</dd></div>
                </dl>

                <a v-if="linkGerenciador" :href="linkGerenciador" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
                    <i class="fab fa-meta" aria-hidden="true"></i> Abrir no Gerenciador de Anúncios
                </a>
            </section>

            <aside>
                <p class="mb-2 text-sm font-semibold text-slate-500">Texto dos anúncios</p>
                <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <p class="whitespace-pre-line p-3 text-sm">{{ c.texto_principal }}</p>
                    <div class="flex items-center justify-between gap-3 bg-slate-500/5 p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ c.titulo }}</p>
                            <p v-if="c.descricao" class="truncate text-xs text-slate-500">{{ c.descricao }}</p>
                        </div>
                        <span class="shrink-0 rounded-md bg-slate-500/15 px-3 py-1.5 text-xs font-semibold">{{ chamadas[c.chamada] ?? c.chamada }}</span>
                    </div>
                </div>
            </aside>
        </div>

        <section class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="videos-titulo">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="videos-titulo" class="text-lg font-semibold">Vídeos ({{ videos.length }})</h2>
                <span class="text-xs text-slate-500">Cada vídeo é um anúncio com a sua parte do orçamento.</span>
            </div>
            <p v-if="!videos.length" class="text-sm text-slate-500">
                Nenhum vídeo ainda.
                <Link v-if="pode.editar" :href="`${base}/editar`" class="font-medium text-primary hover:underline">Enviar vídeos</Link>
            </p>
            <p v-if="erroDesempenho" class="mb-3 text-xs text-amber-700">Não deu para buscar o resultado na Meta agora: {{ erroDesempenho }}</p>
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <li v-for="v in videos" :key="v.id" class="overflow-hidden rounded-lg border" :class="v.ultimo_erro ? 'border-red-400' : 'border-[var(--surface-border)]'">
                    <video :src="v.url" :poster="v.capa ?? undefined" class="aspect-[9/16] w-full bg-black object-contain" preload="none" controls muted></video>
                    <div class="space-y-1 p-3 text-sm">
                        <p class="truncate font-semibold" :title="v.nome">{{ v.ordem + 1 }}. {{ v.nome }}</p>
                        <p class="text-xs text-slate-500">{{ formatarReais(v.orcamento_diario) }}/dia · {{ v.tamanho_mb }} MB<template v-if="v.duracao_segundos"> · {{ Math.round(v.duracao_segundos) }} s</template></p>
                        <p class="flex items-center gap-1.5 text-xs" :class="v.enviado ? 'text-emerald-700 dark:text-emerald-300' : (v.ultimo_erro ? 'text-red-600' : 'text-slate-500')">
                            <i :class="v.enviado ? 'fas fa-circle-check' : (v.ultimo_erro ? 'fas fa-circle-exclamation' : 'far fa-clock')" aria-hidden="true"></i>
                            {{ v.etapa }}
                        </p>
                        <p v-if="v.ultimo_erro" class="text-xs text-red-600">{{ v.ultimo_erro }}</p>
                        <dl v-if="v.desempenho" class="grid grid-cols-2 gap-x-3 gap-y-0.5 border-t border-[var(--surface-border)] pt-2 text-xs">
                            <dt class="text-slate-500">Gasto</dt><dd class="text-right font-medium">{{ formatarReais(v.desempenho.gasto) }}</dd>
                            <dt class="text-slate-500">Impressões</dt><dd class="text-right">{{ formatarNumero(v.desempenho.impressoes) }}</dd>
                            <dt class="text-slate-500">Cliques</dt><dd class="text-right">{{ formatarNumero(v.desempenho.cliques) }}</dd>
                            <dt class="text-slate-500">Compras</dt><dd class="text-right">{{ formatarNumero(v.desempenho.compras) }}</dd>
                            <dt class="text-slate-500">ROAS</dt><dd class="text-right font-medium">{{ roas(v.desempenho) }}</dd>
                        </dl>
                    </div>
                </li>
            </ul>
            <p v-if="comResultado" class="mt-3 text-xs text-slate-500">
                Resultado que a própria Meta atribui ao anúncio, desde o início. O lucro de verdade fica em Resultado e margem.
            </p>
        </section>

        <ConfirmModal :open="modalAtivar" title="Ativar campanha na Meta?" confirm-label="Ativar e começar a gastar"
            :loading="processando" :confirm-disabled="!confirmoGasto" @close="modalAtivar = false" @confirm="ativar">
            <p class="text-sm">
                Isso vai começar a gastar <strong>{{ orcamento }}/dia</strong>, {{ periodo }},
                dividido entre {{ videos.length }} vídeo(s) ({{ formatarReais(videos[0]?.orcamento_diario) }} cada).
            </p>
            <p class="mt-2 text-sm text-slate-500">O valor é cobrado na conta de anúncio da Meta.</p>
            <label class="mt-4 flex items-start gap-2 text-sm">
                <input v-model="confirmoGasto" type="checkbox" class="mt-0.5">
                <span>Confirmo que a campanha vai gastar até {{ orcamento }} por dia.</span>
            </label>
        </ConfirmModal>

        <ConfirmModal :open="modalPausar" title="Pausar campanha na Meta?" confirm-label="Pausar"
            :loading="processando" @close="modalPausar = false" @confirm="pausar">
            <p class="text-sm">A campanha para de gastar. Dá para ativar de novo depois.</p>
        </ConfirmModal>
    </AdminLayout>
</template>
