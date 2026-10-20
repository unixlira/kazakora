<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import AguardeModal from '@/Shared/Components/AguardeModal.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AnuncioModais from './AnuncioModais.vue';
import EspelhoMeta from './EspelhoMeta.vue';
import GerenciarModais from './GerenciarModais.vue';
import { formatarData, formatarNumero, formatarReais, STATUS_ESTILOS } from './metaAds';

const props = defineProps({
    campanha: { type: Object, required: true },
    conjuntos: { type: Array, default: () => [] },
    videos: { type: Array, default: () => [] },
    erroDesempenho: { type: String, default: null },
    config: { type: Object, required: true },
    problemas: { type: Array, default: () => [] },
    pode: { type: Object, required: true },
    linkGerenciador: { type: String, default: null },
    chamadas: { type: Object, required: true },
    generos: { type: Object, required: true },
    orcamentoTipos: { type: Object, required: true },
    periodo: { type: String, default: 'maximum' },
    periodos: { type: Object, default: () => ({}) },
    // Prop adiada (2026-10-18): a campanha como está na Meta, com conjuntos e
    // anúncios feitos direto no Gerenciador também.
    espelho: { type: Object, default: null },
    produtos: { type: Array, default: () => [] },
});

// Editar e excluir anúncio (2026-10-19): no card usa o que a Meta diz agora
// (nome, status, gasto no período, último ativo do conjunto), se já carregou.
const modais = ref(null);
const naMetaPorId = computed(() => {
    const mapa = {};
    (props.espelho?.campanhas ?? []).forEach((camp) => camp.conjuntos.forEach((s) => s.anuncios.forEach((a) => { mapa[a.id] = a; })));
    return mapa;
});
const alvoDoCard = (v) => {
    const a = v.meta_ad_id ? naMetaPorId.value[v.meta_ad_id] : null;
    return {
        local: v.editar,
        nome: a?.nome ?? v.editar.nome,
        status: a?.status ?? null,
        gasto: a?.metricas?.gasto ?? v.desempenho?.gasto ?? 0,
        ultimoAtivo: Boolean(a?.ultimo_ativo),
    };
};
const recarregarTudo = (mudanca) => (mudanca?.nivel === 'campanha' && mudanca?.acao === 'excluir'
    ? router.visit('/admin/publicidade/meta-ads/campanhas')
    : router.reload({ only: ['campanha', 'conjuntos', 'videos', 'pode', 'problemas', 'espelho'] }));

// Editar e excluir campanha e conjunto que já estão na Meta (2026-10-20), com
// o que a Meta diz agora (nome, status, orçamento, gasto no período).
const gerenciar = ref(null);
const conjuntoNaMetaPorId = computed(() => Object.fromEntries((naMeta.value?.conjuntos ?? []).map((s) => [s.id, s])));
const alvoDaCampanha = () => {
    const m = naMeta.value;
    const anuncios = m.conjuntos.reduce((t, s) => t + s.anuncios.length, 0);
    return {
        nivel: 'campanha', id: m.id, nome: m.nome, status: m.status, gasto: m.metricas?.gasto ?? 0,
        orcamento_diario: m.orcamento_nivel === 'campanha' ? m.orcamento_diario : null,
        dentro: `${plural(m.conjuntos.length, 'conjunto', 'conjuntos')} e ${plural(anuncios, 'anúncio', 'anúncios')}`,
    };
};
const alvoDoConjunto = (cj) => {
    const s = conjuntoNaMetaPorId.value[cj.meta_adset_id];
    return {
        nivel: 'conjunto', id: s.id, nome: s.nome, status: s.status, gasto: s.metricas?.gasto ?? 0,
        orcamento_diario: naMeta.value.orcamento_nivel === 'conjunto' ? s.orcamento_diario : null,
        dentro: s.anuncios.length ? plural(s.anuncios.length, 'anúncio', 'anúncios') : '',
        ultimoAtivo: s.status === 'ACTIVE' && naMeta.value.conjuntos.filter((x) => x.status === 'ACTIVE').length === 1,
    };
};
const gerenciaCampanha = computed(() => can('financeiro.create') && Boolean(naMeta.value) && c.value.status !== 'enviando');
const gerenciaConjunto = (cj) => can('financeiro.create') && Boolean(conjuntoNaMetaPorId.value[cj.meta_adset_id]) && !cj.enviando && c.value.status !== 'enviando';

const naMeta = computed(() => props.espelho?.campanhas?.[0] ?? null);
const carregandoMeta = ref(false);
const trocarPeriodo = (periodo) => router.get(base.value, periodo === 'maximum' ? {} : { periodo }, {
    only: ['espelho', 'periodo'],
    preserveScroll: true,
    preserveState: true,
    onStart: () => { carregandoMeta.value = true; },
    onFinish: () => { carregandoMeta.value = false; },
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
// onFinish: se o pedido de envio voltou sem começar (erro), o modal de espera fecha.
const opcoes = { preserveScroll: true, onStart: () => { processando.value = true; }, onFinish: () => { processando.value = false; preparando.value = false; } };

// Desde 2026-10-20 tudo nasce ativo: enviar já é começar a gastar, então
// pede a mesma confirmação que o Ativar pedia.
const modalEnviar = ref(false);
const confirmoEnvio = ref(false);
const abrirEnvio = () => { confirmoEnvio.value = false; modalEnviar.value = true; };
const enviar = () => router.post(`${base.value}/enviar`, {
    confirmo_gasto: confirmoEnvio.value,
    orcamento_diario: Number(c.value.orcamento_diario),
}, { ...opcoes, onStart: () => { processando.value = true; preparando.value = true; }, onSuccess: () => { modalEnviar.value = false; confirmoEnvio.value = false; } });
const totalPrevisto = computed(() => {
    if (!c.value.fim) return null;
    const dias = Math.round((new Date(`${c.value.fim}T00:00:00`) - new Date(`${c.value.inicio}T00:00:00`)) / 86400000) + 1;
    return dias > 0 ? dias * Number(c.value.orcamento_diario) : null;
});

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

// Enquanto envia (a campanha ou um conjunto novo), a tela se atualiza
// sozinha a cada 5 s para mostrar o andamento de cada vídeo.
let timer = null;
const conjuntoEnviando = computed(() => props.conjuntos.find((cj) => cj.enviando) ?? null);
const enviandoAlgo = computed(() => c.value.status === 'enviando' || Boolean(conjuntoEnviando.value) || props.videos.some((v) => v.editar?.trocando_arquivo));

// Modal único de espera (2026-10-20): abre no clique de enviar, mostra o
// andamento e fecha sozinho quando a Meta termina (ou quando dá erro, que
// aparece na tela). "Continuar usando a tela" esconde até o próximo envio.
const preparando = ref(false);
const escondido = ref(false);
const enviandoParaMeta = computed(() => c.value.status === 'enviando' || Boolean(conjuntoEnviando.value));
watch(enviandoParaMeta, (agora, antes) => {
    if (agora) preparando.value = false;
    if (antes && !agora) escondido.value = false;
});
const aguardeAberto = computed(() => (preparando.value || enviandoParaMeta.value) && !escondido.value);
const andamento = computed(() => {
    const lista = conjuntoEnviando.value ? novos(conjuntoEnviando.value) : props.videos;
    const prontos = lista.filter((v) => v.enviado).length;
    return { prontos, total: lista.length };
});
const aguardeDetalhe = computed(() => (enviandoParaMeta.value && andamento.value.total
    ? `${andamento.value.prontos} de ${plural(andamento.value.total, 'anúncio criado', 'anúncios criados')} na Meta`
    : 'Mandando para a Meta…'));
const aguardeProgresso = computed(() => (enviandoParaMeta.value && andamento.value.total
    ? (andamento.value.prontos / andamento.value.total) * 100
    : null));
const acompanhar = (enviando) => {
    clearInterval(timer);
    if (enviando) {
        timer = setInterval(() => router.reload({ only: ['campanha', 'conjuntos', 'videos', 'pode', 'problemas', 'linkGerenciador'] }), 5000);
    }
};
watch(enviandoAlgo, acompanhar, { immediate: true });
// Status mudado no Gerenciador: o espelho acerta o banco e a tela recarrega.
watch(() => props.espelho?.status_atualizado, (mudou) => {
    if (mudou) router.reload({ only: ['campanha', 'pode'] });
});

// Conjunto novo em campanha que já está na Meta (2026-10-19).
const conjuntoEmEnvio = ref(null);
const confirmoEnvioConjunto = ref(false);
const abrirEnvioConjunto = (cj) => { confirmoEnvioConjunto.value = false; conjuntoEmEnvio.value = cj; };
const gastoDoEnvio = computed(() => Number(porConjunto.value ? conjuntoEmEnvio.value?.orcamento_diario : c.value.orcamento_diario));
const enviarConjunto = () => router.post(`${base.value}/conjuntos/${conjuntoEmEnvio.value.id}/enviar`, {
    confirmo_gasto: confirmoEnvioConjunto.value,
    orcamento_diario: gastoDoEnvio.value,
}, { ...opcoes, onStart: () => { processando.value = true; preparando.value = true; }, onSuccess: () => { conjuntoEmEnvio.value = null; confirmoEnvioConjunto.value = false; } });
const conjuntoEmAtivacao = ref(null);
const confirmoGastoConjunto = ref(false);
const gastoDoConjunto = computed(() => Number(porConjunto.value ? conjuntoEmAtivacao.value?.orcamento_diario : c.value.orcamento_diario));
const ativarConjunto = () => router.post(`${base.value}/conjuntos/${conjuntoEmAtivacao.value.id}/ativar`, {
    confirmo_gasto: confirmoGastoConjunto.value,
    orcamento_diario: gastoDoConjunto.value,
}, { ...opcoes, onSuccess: () => { conjuntoEmAtivacao.value = null; confirmoGastoConjunto.value = false; } });
// Conjunto que ainda não está na Meta sobe inteiro; o que já está recebe só os anúncios novos.
const conjuntoNaMeta = (cj) => Boolean(cj?.meta_adset_id);
const ligaConjunto = (cj) => Boolean(cj?.adicionado_depois && !cj?.ativado_em);
const novos = (cj) => anunciosDe(cj).filter((v) => v.adicionado_depois || cj.adicionado_depois);
const excluirConjunto = async (cj) => {
    if (await confirmDelete({ title: `Excluir o conjunto novo "${cj.nome}"?`, text: 'Ele ainda não está na Meta. Os anúncios dele saem junto.' })) {
        router.delete(`${base.value}/conjuntos/${cj.id}`, { preserveScroll: true });
    }
};
onBeforeUnmount(() => clearInterval(timer));

const porConjunto = computed(() => c.value.orcamento_tipo === 'conjunto');
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;
const estrutura = computed(() => `${plural(props.conjuntos.length, 'conjunto', 'conjuntos')} · ${plural(props.videos.length, 'anúncio', 'anúncios')}`);
const divisao = computed(() => {
    if (props.conjuntos.length < 2) return '';
    return porConjunto.value ? ', somando os conjuntos' : `, a Meta distribui entre ${props.conjuntos.length} conjuntos`;
});
const anunciosDe = (cj) => props.videos.filter((v) => v.conjunto_id === cj.id);
const nomeDoPublico = (p) => {
    const l = p?.localizacoes ?? {};
    const lugares = [...(l.regioes ?? []).map((r) => r.nome), ...(l.cidades ?? []).map((x) => x.nome)];
    return `${p.idade_min} a ${p.idade_max} anos, ${props.generos[p.genero]?.toLowerCase()}, ${lugares.length ? lugares.join(', ') : 'Brasil inteiro'}`;
};
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
                <h1 class="flex items-center gap-3 text-2xl font-bold">
                    <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                    {{ c.nome }}
                </h1>
                <span class="rounded-full px-2.5 py-1 text-xs font-bold" :class="STATUS_ESTILOS[c.status]">{{ c.status_rotulo }}</span>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link v-if="pode.editar && can('financeiro.create')" :href="`${base}/editar`"
                    :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']">
                    <i class="fas fa-pen text-xs" aria-hidden="true"></i> Editar
                </Link>
                <button v-else-if="gerenciaCampanha" type="button"
                    :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="gerenciar.editar(alvoDaCampanha())">
                    <i class="fas fa-pen text-xs" aria-hidden="true"></i> Editar
                </button>
                <button v-if="pode.enviar && can('financeiro.create')" type="button" :disabled="processando || problemas.length > 0"
                    :class="[botao, 'bg-primary text-white hover:bg-primary-emphasis']" @click="abrirEnvio">
                    <i class="fas fa-paper-plane text-xs" aria-hidden="true"></i>
                    {{ c.status === 'erro' ? 'Tentar enviar de novo' : 'Enviar e ativar na Meta' }}
                </button>
                <button v-if="pode.ativar && can('financeiro.create')" type="button" :disabled="processando || !config.configurado"
                    :class="[botao, 'bg-emerald-600 text-white hover:bg-emerald-700']" @click="modalAtivar = true">
                    <i class="fas fa-play text-xs" aria-hidden="true"></i> Ativar na Meta
                </button>
                <button v-if="pode.pausar && can('financeiro.create')" type="button" :disabled="processando || !config.configurado"
                    :class="[botao, 'bg-amber-500 text-white hover:bg-amber-600']" @click="modalPausar = true">
                    <i class="fas fa-pause text-xs" aria-hidden="true"></i> Pausar
                </button>
                <Link v-if="pode.adicionarConjunto && can('financeiro.create')" :href="`${base}/conjuntos/novo`"
                    :class="[botao, 'border border-primary text-primary hover:bg-primary/10']">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i> Acrescentar novos conjuntos
                </Link>
                <button v-if="pode.excluir && can('financeiro.create')" type="button"
                    :class="[botao, 'border border-red-300 text-red-700 hover:bg-red-50']" @click="excluir">
                    <i class="fas fa-trash text-xs" aria-hidden="true"></i> Excluir
                </button>
                <button v-else-if="gerenciaCampanha" type="button"
                    :class="[botao, 'border border-red-300 text-red-700 hover:bg-red-50']" @click="gerenciar.excluir(alvoDaCampanha())">
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
            <span>Enviando para a Meta: {{ enviados }} de {{ videos.length }} anúncio(s) criados. A Meta leva alguns minutos para processar cada vídeo. Pode sair desta tela.</span>
        </div>
        <div v-if="c.status === 'enviada_pausada'" class="mb-4 rounded-xl border border-sky-300 bg-sky-50 p-4 text-sm text-sky-900" role="status">
            Campanha na Meta e <strong>pausada</strong>. Não gasta nada até você clicar em Ativar.
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <section class="space-y-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm lg:col-span-2">
                <h2 class="text-lg font-semibold">Resumo</h2>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-slate-500">Objetivo</dt><dd class="font-medium">{{ c.objetivo_rotulo }}</dd></div>
                    <div v-if="naMeta"><dt class="text-slate-500">Orçamento por dia (na Meta)</dt><dd class="font-medium">{{ formatarReais(naMeta.orcamento_diario) }}<span class="font-normal text-slate-500">{{ naMeta.orcamento_nivel === 'conjunto' ? ', somando os conjuntos ativos' : ', da campanha' }}</span></dd></div>
                    <div v-else><dt class="text-slate-500">Orçamento por dia</dt><dd class="font-medium">{{ orcamento }}<span class="font-normal text-slate-500">{{ divisao }}</span></dd></div>
                    <div v-if="naMeta"><dt class="text-slate-500">Estrutura (na Meta)</dt><dd class="font-medium">{{ plural(naMeta.conjuntos.length, 'conjunto', 'conjuntos') }} · {{ plural(naMeta.conjuntos.reduce((t, s) => t + s.anuncios.length, 0), 'anúncio', 'anúncios') }}</dd></div>
                    <div v-else><dt class="text-slate-500">Estrutura</dt><dd class="font-medium">{{ estrutura }} <span class="font-normal text-slate-500">({{ orcamentoTipos[c.orcamento_tipo] ?? c.orcamento_tipo }})</span></dd></div>
                    <div><dt class="text-slate-500">Período</dt><dd class="font-medium">{{ periodo }}</dd></div>
                    <div><dt class="text-slate-500">Público padrão</dt><dd class="font-medium">{{ c.idade_min }} a {{ c.idade_max }} anos, {{ generos[c.genero]?.toLowerCase() }}</dd></div>
                    <div><dt class="text-slate-500">Onde</dt><dd class="font-medium">{{ locais }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-slate-500">Destino padrão</dt>
                        <dd class="break-all font-medium"><a :href="c.link_destino" target="_blank" rel="noopener" class="text-primary hover:underline">{{ c.link_destino }}</a></dd></div>
                    <div v-if="c.produto"><dt class="text-slate-500">Produto</dt><dd class="font-medium">{{ c.produto.name }}</dd></div>
                    <div v-if="c.user"><dt class="text-slate-500">Criada por</dt><dd class="font-medium">{{ c.user.name }}</dd></div>
                </dl>

                <a v-if="linkGerenciador" :href="linkGerenciador" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
                    <i class="fab fa-meta" aria-hidden="true"></i> Abrir no Gerenciador de Anúncios
                </a>
            </section>

            <aside>
                <p class="mb-2 text-sm font-semibold text-slate-500">Texto padrão dos anúncios</p>
                <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <p class="whitespace-pre-line p-3 text-sm">{{ c.texto_principal || 'Sem texto padrão: cada anúncio usa o seu.' }}</p>
                    <div class="flex items-center justify-between gap-3 bg-slate-500/5 p-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ c.titulo || '-' }}</p>
                            <p v-if="c.descricao" class="truncate text-xs text-slate-500">{{ c.descricao }}</p>
                        </div>
                        <span class="shrink-0 rounded-md bg-slate-500/15 px-3 py-1.5 text-xs font-semibold">{{ chamadas[c.chamada] ?? c.chamada }}</span>
                    </div>
                </div>
            </aside>
        </div>

        <section v-if="c.meta_campaign_id && config.configurado" class="mt-6" aria-labelledby="na-meta-titulo">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="na-meta-titulo" class="text-lg font-bold">Na Meta agora</h2>
                    <p class="text-sm text-slate-500">Conjuntos e anúncios como estão no Gerenciador, inclusive os criados direto lá.</p>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <span class="text-slate-500">Período</span>
                    <select :value="periodo" :disabled="carregandoMeta"
                        class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm"
                        @change="trocarPeriodo($event.target.value)">
                        <option v-for="(rotulo, chave) in periodos" :key="chave" :value="chave">{{ rotulo }}</option>
                    </select>
                    <i v-if="carregandoMeta" class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i>
                </label>
            </div>
            <Deferred data="espelho">
                <template #fallback>
                    <div class="flex items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 text-sm text-slate-500" role="status" aria-live="polite">
                        <i class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i> Consultando a Meta…
                    </div>
                </template>
                <p v-if="espelho?.erro" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="alert">
                    Não deu para ler a Meta agora: {{ espelho.erro }}
                </p>
                <EspelhoMeta v-else :campanhas="espelho?.campanhas ?? []" abrir-tudo sem-link-local :editar-anuncios="can('financeiro.create')"
                    :produtos="produtos" :chamadas="chamadas" :class="{ 'opacity-60': carregandoMeta }" @mudou="recarregarTudo" />
            </Deferred>
            <p class="mt-2 text-xs text-slate-500">Resultado que a própria Meta atribui. O lucro de verdade fica em Resultado e margem.</p>
        </section>

        <h2 v-if="c.meta_campaign_id && videos.length" class="mt-8 text-lg font-bold">Arquivos enviados pelo KazaKora</h2>

        <p v-if="erroDesempenho" class="mt-6 text-xs text-amber-700">Não deu para buscar o resultado na Meta agora: {{ erroDesempenho }}</p>
        <p v-if="!videos.length" class="mt-6 text-sm text-slate-500">
            Nenhum anúncio ainda.
            <Link v-if="pode.editar" :href="`${base}/editar`" class="font-medium text-primary hover:underline">Adicionar vídeos ou imagens</Link>
        </p>

        <section v-for="cj in conjuntos" :key="cj.id" class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" :aria-label="`Conjunto ${cj.nome}`">
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold">
                    {{ cj.nome }}
                    <span class="text-sm font-normal text-slate-500">· {{ plural(anunciosDe(cj).length, 'anúncio', 'anúncios') }}<template v-if="porConjunto"> · {{ formatarReais(cj.orcamento_diario) }}/dia</template></span>
                </h2>
                <span class="text-xs text-slate-500">
                    <template v-if="cj.publico_proprio">Público próprio: </template>{{ nomeDoPublico(cj.publico) }}
                    <template v-if="cj.meta_adset_id"> · <i class="fas fa-circle-check text-emerald-600" aria-hidden="true"></i> conjunto criado na Meta</template>
                </span>
            </div>

            <div v-if="can('financeiro.create') && ((cj.pode.acrescentarAnuncios && !cj.pode.editar) || gerenciaConjunto(cj))" class="mb-3 flex flex-wrap gap-2">
                <Link v-if="cj.pode.acrescentarAnuncios && !cj.pode.editar" :href="`${base}/conjuntos/${cj.id}/anuncios`" :class="[botao, 'border border-primary text-primary hover:bg-primary/10']">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i> Acrescentar novos anúncios
                </Link>
                <template v-if="gerenciaConjunto(cj)">
                    <button type="button" :aria-label="`Editar conjunto ${cj.nome}`" :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="gerenciar.editar(alvoDoConjunto(cj))">
                        <i class="fas fa-pen text-xs" aria-hidden="true"></i> Editar conjunto
                    </button>
                    <button type="button" :aria-label="`Excluir conjunto ${cj.nome}`" :class="[botao, 'border border-red-300 text-red-700 hover:bg-red-50']" @click="gerenciar.excluir(alvoDoConjunto(cj))">
                        <i class="fas fa-trash text-xs" aria-hidden="true"></i> Excluir conjunto
                    </button>
                </template>
            </div>

            <div v-if="cj.pode.editar || cj.pode.enviar || cj.pode.ativar || cj.enviando"
                class="mb-3 space-y-2 rounded-lg border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900 dark:bg-sky-500/10 dark:text-sky-200">
                <p v-if="cj.enviando" class="flex items-center gap-2" role="status" aria-live="polite">
                    <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                    Enviando {{ conjuntoNaMeta(cj) ? 'os anúncios novos' : 'este conjunto' }} para a Meta, já ativo. O resto da campanha não muda. Pode sair desta tela.
                </p>
                <p v-else-if="cj.pode.ativar">{{ ligaConjunto(cj) ? 'Conjunto novo' : 'Anúncios novos' }} já na Meta e <strong>pausados</strong>. A campanha está rodando; ative quando quiser que comecem a rodar.</p>
                <p v-else-if="cj.pode.enviar && !conjuntoNaMeta(cj)">Conjunto novo, ainda <strong>não está na Meta</strong>. Ao enviar, ele e os anúncios dele nascem ativos dentro da campanha.</p>
                <p v-else-if="cj.pode.enviar">Anúncios novos, ainda <strong>não estão na Meta</strong>. Ao enviar, eles nascem ativos dentro deste conjunto; os que já rodam não mudam.</p>
                <ul v-if="cj.problemas?.length" class="ml-5 list-disc text-amber-800 dark:text-amber-300">
                    <li v-for="p in cj.problemas" :key="p">{{ p }}</li>
                </ul>
                <div v-if="can('financeiro.create')" class="flex flex-wrap gap-2">
                    <button v-if="cj.pode.enviar" type="button" :disabled="processando || cj.problemas?.length > 0 || !config.configurado"
                        :class="[botao, 'bg-primary text-white hover:bg-primary-emphasis']" @click="abrirEnvioConjunto(cj)">
                        <i class="fas fa-paper-plane text-xs" aria-hidden="true"></i> {{ cj.ultimo_erro ? 'Tentar enviar de novo' : (conjuntoNaMeta(cj) ? 'Enviar anúncios novos e ativar' : 'Enviar conjunto e ativar') }}
                    </button>
                    <button v-if="cj.pode.ativar" type="button" :disabled="processando || !config.configurado"
                        :class="[botao, 'bg-emerald-600 text-white hover:bg-emerald-700']" @click="conjuntoEmAtivacao = cj">
                        <i class="fas fa-play text-xs" aria-hidden="true"></i> {{ ligaConjunto(cj) ? 'Ativar este conjunto' : 'Ativar anúncios novos' }}
                    </button>
                    <Link v-if="cj.pode.acrescentarAnuncios && cj.pode.editar" :href="`${base}/conjuntos/${cj.id}/anuncios`"
                        :class="[botao, 'border border-primary bg-[var(--surface)] text-primary hover:bg-primary/10']">
                        <i class="fas fa-plus text-xs" aria-hidden="true"></i> Acrescentar novos anúncios
                    </Link>
                    <Link v-if="cj.pode.editar" :href="`${base}/conjuntos/${cj.id}/editar`"
                        :class="[botao, 'border border-[var(--surface-border)] bg-[var(--surface)] hover:bg-[var(--surface-muted)]']">
                        <i class="fas fa-pen text-xs" aria-hidden="true"></i> Editar
                    </Link>
                    <button v-if="cj.pode.editar" type="button" :class="[botao, 'border border-red-300 bg-[var(--surface)] text-red-700 hover:bg-red-50']" @click="excluirConjunto(cj)">
                        <i class="fas fa-trash text-xs" aria-hidden="true"></i> Excluir
                    </button>
                </div>
            </div>
            <p v-else-if="novos(cj).some((v) => v.enviado && !v.ativado_em) && c.status === 'enviada_pausada'" class="mb-3 text-xs text-slate-500">
                {{ cj.adicionado_depois ? 'Conjunto novo pausado' : 'Anúncios novos pausados' }}: ligam junto quando você ativar a campanha.
            </p>
            <p v-if="cj.ultimo_erro" class="mb-3 text-xs text-red-600">{{ cj.ultimo_erro }}</p>
            <p v-if="anunciosDe(cj).length > 6" class="mb-3 text-xs text-amber-700">Mais de 6 anúncios no mesmo conjunto dividem demais a entrega.</p>
            <ul class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <li v-for="v in anunciosDe(cj)" :key="v.id" class="overflow-hidden rounded-lg border" :class="v.ultimo_erro ? 'border-red-400' : 'border-[var(--surface-border)]'">
                    <img v-if="v.tipo === 'imagem'" :src="v.url" :alt="`Imagem do anúncio ${v.ordem + 1}`" class="aspect-[4/5] w-full bg-black object-contain" loading="lazy">
                    <video v-else :src="v.url" :poster="v.capa ?? undefined" class="aspect-[9/16] w-full bg-black object-contain" preload="none" controls muted></video>
                    <div class="space-y-1 p-3 text-sm">
                        <p class="truncate font-semibold" :title="v.nome">{{ v.ordem + 1 }}. {{ v.nome }}</p>
                        <span v-if="v.adicionado_depois" class="inline-block rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary">Novo</span>
                        <p class="text-sm font-medium">{{ v.criativo?.titulo || 'Sem título' }}</p>
                        <p v-if="v.criativo?.texto" class="line-clamp-3 whitespace-pre-line text-xs text-slate-600 dark:text-slate-300">{{ v.criativo.texto }}</p>
                        <a v-if="v.criativo?.link" :href="v.criativo.link" target="_blank" rel="noopener" class="block truncate text-xs text-primary hover:underline">{{ v.criativo.link }}</a>
                        <p class="text-xs text-slate-500">{{ v.tamanho_mb }} MB<template v-if="v.duracao_segundos"> · {{ Math.round(v.duracao_segundos) }} s</template><template v-if="v.formato"> · {{ v.formato }}</template></p>
                        <p v-if="v.vira_vertical" class="text-xs text-amber-700 dark:text-amber-400">Horizontal: sobe como 9:16 com as laterais desfocadas</p>
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
                        <p v-if="v.editar?.trocando_arquivo" class="flex items-center gap-1.5 text-xs text-sky-700 dark:text-sky-300" role="status" aria-live="polite">
                            <i class="fas fa-spinner fa-spin" aria-hidden="true"></i> A Meta está processando o arquivo novo
                        </p>
                    </div>
                    <div v-if="can('financeiro.create') && v.editar" class="flex flex-wrap gap-1.5 border-t border-[var(--surface-border)] px-3 py-2">
                        <button type="button" :disabled="processando || Boolean(v.editar.bloqueio)" :title="v.editar.bloqueio ?? undefined" :aria-label="`Editar anúncio ${alvoDoCard(v).nome}`"
                            class="inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-0.5 text-xs font-medium text-primary hover:bg-primary/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="modais.editar(alvoDoCard(v))">
                            <i class="fas fa-pen text-[10px]" aria-hidden="true"></i> Editar
                        </button>
                        <button type="button" :disabled="processando || Boolean(v.editar.bloqueio)" :title="v.editar.bloqueio ?? undefined" :aria-label="`Excluir anúncio ${alvoDoCard(v).nome}`"
                            class="inline-flex items-center gap-1 rounded-md border border-red-300 px-2 py-0.5 text-xs font-medium text-red-700 hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-300 disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-300 dark:hover:bg-red-500/10"
                            @click="modais.excluir(alvoDoCard(v))">
                            <i class="fas fa-trash text-[10px]" aria-hidden="true"></i> Excluir
                        </button>
                    </div>
                </li>
            </ul>
            <p v-if="!anunciosDe(cj).length" class="text-sm text-amber-700">Este conjunto não tem anúncio.</p>
        </section>
        <p v-if="comResultado" class="mt-3 text-xs text-slate-500">
            Resultado que a própria Meta atribui ao anúncio, desde o início. O lucro de verdade fica em Resultado e margem.
        </p>

        <ConfirmModal :open="modalEnviar" title="Enviar e ativar na Meta?" confirm-label="Enviar e começar a gastar" cancel-label="Voltar"
            :loading="processando" :confirm-disabled="!confirmoEnvio" @close="modalEnviar = false" @confirm="enviar">
            <p class="text-sm">
                Vamos criar 1 campanha, {{ estrutura.replace(' · ', ' e ') }}, tudo <strong>ativo</strong>. Assim que a Meta aprovar os anúncios, começa a gastar
                <strong>{{ orcamento }}/dia</strong>, {{ periodo }}{{ divisao }}<template v-if="totalPrevisto !== null">, até {{ formatarReais(totalPrevisto) }} no total</template>.
            </p>
            <p class="mt-2 text-sm text-slate-500">O valor é cobrado na conta de anúncio da Meta. Dá para pausar depois pelo botão Pausar.</p>
            <label class="mt-4 flex items-start gap-2 text-sm">
                <input v-model="confirmoEnvio" type="checkbox" class="mt-0.5">
                <span>Confirmo que a campanha vai gastar até {{ orcamento }} por dia.</span>
            </label>
        </ConfirmModal>

        <ConfirmModal :open="modalAtivar" title="Ativar campanha na Meta?" confirm-label="Ativar e começar a gastar"
            :loading="processando" :confirm-disabled="!confirmoGasto" @close="modalAtivar = false" @confirm="ativar">
            <p class="text-sm">
                Isso vai começar a gastar <strong>{{ orcamento }}/dia</strong>, {{ periodo }}{{ divisao }}.
            </p>
            <p class="mt-2 text-sm text-slate-500">O valor é cobrado na conta de anúncio da Meta.</p>
            <label class="mt-4 flex items-start gap-2 text-sm">
                <input v-model="confirmoGasto" type="checkbox" class="mt-0.5">
                <span>Confirmo que a campanha vai gastar até {{ orcamento }} por dia.</span>
            </label>
        </ConfirmModal>

        <ConfirmModal :open="conjuntoEmEnvio !== null" :title="conjuntoNaMeta(conjuntoEmEnvio) ? 'Enviar e ativar os anúncios novos?' : 'Enviar e ativar o conjunto novo?'" confirm-label="Enviar e ativar" cancel-label="Voltar"
            :loading="processando" :confirm-disabled="!confirmoEnvioConjunto" @close="conjuntoEmEnvio = null" @confirm="enviarConjunto">
            <p v-if="conjuntoNaMeta(conjuntoEmEnvio)" class="text-sm">
                Vamos criar {{ plural(novos(conjuntoEmEnvio).filter((v) => !v.enviado).length, 'anúncio novo', 'anúncios novos') }} dentro do conjunto
                <strong>{{ conjuntoEmEnvio?.nome }}</strong>, já ativos. Eles dividem o orçamento do conjunto ({{ formatarReais(gastoDoEnvio) }}/dia{{ porConjunto ? '' : ', da campanha' }}); os que já rodam não mudam.
            </p>
            <p v-else-if="porConjunto" class="text-sm">
                Vamos criar o conjunto <strong>{{ conjuntoEmEnvio?.nome }}</strong> com {{ conjuntoEmEnvio ? plural(anunciosDe(conjuntoEmEnvio).length, 'anúncio', 'anúncios') : '' }}
                dentro da campanha {{ c.nome }}, já ativo: <strong>{{ formatarReais(gastoDoEnvio) }}/dia</strong> além do que a campanha já gasta.
            </p>
            <p v-else class="text-sm">
                Vamos criar o conjunto <strong>{{ conjuntoEmEnvio?.nome }}</strong> com {{ conjuntoEmEnvio ? plural(anunciosDe(conjuntoEmEnvio).length, 'anúncio', 'anúncios') : '' }}
                dentro da campanha {{ c.nome }}, já ativo. Ele entra na divisão do orçamento da campanha, <strong>{{ formatarReais(gastoDoEnvio) }}/dia</strong> no total.
            </p>
            <p v-if="!['ativa', 'gerenciador'].includes(c.status)" class="mt-2 text-sm text-slate-500">A campanha está pausada: só gasta quando ela for ativada.</p>
            <label class="mt-4 flex items-start gap-2 text-sm">
                <input v-model="confirmoEnvioConjunto" type="checkbox" class="mt-0.5">
                <span v-if="conjuntoNaMeta(conjuntoEmEnvio) || !porConjunto">Confirmo que vai rodar dentro do orçamento de {{ formatarReais(gastoDoEnvio) }} por dia.</span>
                <span v-else>Confirmo que o conjunto vai gastar até {{ formatarReais(gastoDoEnvio) }} por dia.</span>
            </label>
        </ConfirmModal>

        <ConfirmModal :open="conjuntoEmAtivacao !== null" :title="ligaConjunto(conjuntoEmAtivacao) ? 'Ativar conjunto na Meta?' : 'Ativar anúncios novos na Meta?'" confirm-label="Ativar"
            :loading="processando" :confirm-disabled="!confirmoGastoConjunto" @close="conjuntoEmAtivacao = null; confirmoGastoConjunto = false" @confirm="ativarConjunto">
            <p v-if="!ligaConjunto(conjuntoEmAtivacao)" class="text-sm">
                Os anúncios novos entram no conjunto <strong>{{ conjuntoEmAtivacao?.nome }}</strong> e dividem o orçamento dele
                ({{ formatarReais(gastoDoConjunto) }}/dia{{ porConjunto ? '' : ', da campanha' }}). O gasto por dia não muda.
            </p>
            <p v-else-if="porConjunto" class="text-sm">
                O conjunto <strong>{{ conjuntoEmAtivacao?.nome }}</strong> vai começar a gastar <strong>{{ formatarReais(gastoDoConjunto) }}/dia</strong>, além do que a campanha já gasta.
            </p>
            <p v-else class="text-sm">
                O conjunto <strong>{{ conjuntoEmAtivacao?.nome }}</strong> entra na divisão do orçamento da campanha, <strong>{{ formatarReais(gastoDoConjunto) }}/dia</strong> no total. O total não muda.
            </p>
            <label class="mt-4 flex items-start gap-2 text-sm">
                <input v-model="confirmoGastoConjunto" type="checkbox" class="mt-0.5">
                <span v-if="!ligaConjunto(conjuntoEmAtivacao)">Confirmo que os anúncios novos vão rodar dentro do orçamento de {{ formatarReais(gastoDoConjunto) }} por dia.</span>
                <span v-else>{{ porConjunto ? `Confirmo que o conjunto vai gastar até ${formatarReais(gastoDoConjunto)} por dia.` : `Confirmo que o conjunto vai gastar parte dos ${formatarReais(gastoDoConjunto)} por dia da campanha.` }}</span>
            </label>
        </ConfirmModal>

        <ConfirmModal :open="modalPausar" title="Pausar campanha na Meta?" confirm-label="Pausar"
            :loading="processando" @close="modalPausar = false" @confirm="pausar">
            <p class="text-sm">A campanha para de gastar. Dá para ativar de novo depois.</p>
        </ConfirmModal>

        <AguardeModal :open="aguardeAberto" titulo="Aguarde enquanto o anúncio é preparado"
            mensagem="A Meta leva alguns minutos para processar cada vídeo. Esta janela fecha sozinha quando terminar."
            :progresso="aguardeProgresso" :detalhe="aguardeDetalhe" :pode-sair="enviandoParaMeta" @sair="escondido = true" />

        <AnuncioModais ref="modais" :produtos="produtos" :chamadas="chamadas" @mudou="recarregarTudo" />
        <GerenciarModais ref="gerenciar" @mudou="recarregarTudo" />
    </AdminLayout>
</template>
