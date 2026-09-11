<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

/**
 * Envios Flex (pedido explícito 2026-09-11) — o que saiu com o entregador,
 * o que voltou e o que ficou no meio do caminho. A regra de cada alerta
 * mora em FlexControlService (backend); esta tela só mostra e registra a
 * conferência de quem foi olhar a prateleira.
 */
const props = defineProps({
    linhas: { type: Array, default: () => [] },
    resumo: { type: Object, required: true },
    filtros: { type: Object, required: true },
    resolucoes: { type: Array, default: () => [] },
    retencaoDias: { type: Number, default: 180 },
    horasAlertaRota: { type: Number, default: 2 },
});

const { can } = usePermissions();
const podeEditar = computed(() => can('operacional.edit'));

const quando = (iso) => (iso
    ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' })
    : null);
const dinheiro = (valor) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(valor ?? 0);

const CORES_SITUACAO = {
    cancelada: 'bg-lighterror text-error',
    devolvida: 'bg-lightwarning text-warning',
    entregue: 'bg-lightsuccess text-success',
    nao_entregue: 'bg-lighterror text-error',
    em_rota: 'bg-lightinfo text-info',
    com_entregador: 'bg-lightwarning text-warning',
    pronta: 'bg-lightsecondary text-secondary',
    na_loja: 'bg-[var(--surface-muted)] text-slate-500',
};

// --- Filtros ---------------------------------------------------------------
const mes = ref(props.filtros.mes);
const busca = ref(props.filtros.busca ?? '');

const filtrar = (extra = {}) => {
    router.get('/admin/envios-flex', {
        aba: props.filtros.aba,
        mes: mes.value || undefined,
        busca: busca.value || undefined,
        ...extra,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const trocarAba = (aba) => filtrar({ aba, busca: undefined });

// --- Detalhe ---------------------------------------------------------------
// Guarda o id e busca a linha nas props a cada recarga: depois de resolver
// ou atualizar com o ML, o detalhe mostra o dado novo. O snapshot segura o
// modal aberto se a linha sair da aba (ex.: alerta resolvido).
const selecionadoId = ref(null);
const snapshot = ref(null);
const selecionado = computed(() => props.linhas.find((l) => l.id === selecionadoId.value) ?? snapshot.value);
watch(selecionado, (linha) => { if (linha) snapshot.value = linha; });

const mostrarImagens = ref(false);
const resolucaoTipo = ref('');
const resolucaoNota = ref('');
const motivoRetencao = ref('');
const enviando = ref(false);

const abrir = (linha) => {
    selecionadoId.value = linha.id;
    snapshot.value = linha;
    mostrarImagens.value = false;
    resolucaoTipo.value = '';
    resolucaoNota.value = '';
    motivoRetencao.value = '';
};
const fechar = () => { selecionadoId.value = null; snapshot.value = null; };

const acao = (metodo, url, dados = {}) => {
    enviando.value = true;
    const opcoes = {
        preserveScroll: true,
        preserveState: true,
        // Resolveu e a linha saiu da aba Alertas: fecha em vez de deixar o
        // detalhe velho (ainda com o formulário) na tela.
        onSuccess: () => { if (!props.linhas.some((l) => l.id === selecionadoId.value)) fechar(); },
        onFinish: () => { enviando.value = false; },
    };

    if (metodo === 'delete') router.delete(url, opcoes);
    else router[metodo](url, dados, opcoes);
};

const resolver = () => {
    if (!resolucaoTipo.value) return;
    acao('post', `/admin/envios-flex/${selecionado.value.id}/resolver`, { tipo: resolucaoTipo.value, nota: resolucaoNota.value || null });
};
const desfazerResolucao = () => {
    if (!window.confirm('Desfazer a conferência? Os alertas deste pedido voltam a ficar abertos.')) return;
    acao('delete', `/admin/envios-flex/${selecionado.value.id}/resolver`);
};
const sincronizar = () => acao('post', `/admin/envios-flex/${selecionado.value.id}/sincronizar`);
const reter = () => {
    if (!motivoRetencao.value.trim()) return;
    acao('post', `/admin/envios-flex/recibos/${selecionado.value.recibo.id}/reter`, { motivo: motivoRetencao.value });
};
const liberar = () => {
    if (!window.confirm(`Liberar a retenção? As imagens voltam a ser apagadas ${props.retencaoDias} dias após a retirada.`)) return;
    acao('delete', `/admin/envios-flex/recibos/${selecionado.value.recibo.id}/reter`);
};

const passosLoja = (linha) => [
    ['Vendida', linha.linhaDoTempo.vendidaEm],
    ['Etiqueta impressa', linha.linhaDoTempo.etiquetaImpressaEm],
    ['Separada', linha.linhaDoTempo.separadaEm],
    ['Bipada no KoraFlex', linha.linhaDoTempo.bipadaEm],
    ['Entregue ao entregador', linha.linhaDoTempo.entregueAoEntregadorEm],
];
const passosCanal = (linha) => [
    ['Rota iniciada', linha.linhaDoTempo.rotaIniciadaEm],
    ['1ª visita ao comprador', linha.linhaDoTempo.primeiraVisitaEm],
    ['Entregue ao comprador', linha.linhaDoTempo.entregueEm],
    ['Não entregue', linha.linhaDoTempo.naoEntregueEm],
    ['Devolvido', linha.linhaDoTempo.devolvidoEm],
    ['Cancelado', linha.linhaDoTempo.canceladoEm],
    ['Estoque devolvido no sistema', linha.linhaDoTempo.estoqueDevolvidoEm],
];

const resumoItens = (linha) => linha.itens.map((i) => `${i.qtd}x ${i.sku ?? i.nome}`).join(' · ');
const alertasVisiveis = (linha) => linha.alertas.filter((a) => a.nivel !== 'info' && !a.resolvido);
</script>

<template>
    <Head title="Envios Flex" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Envios Flex</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Tudo que saiu com o entregador do Flex: comprovante de retirada, o que o Mercado Livre registrou e o que precisa voltar.
                </p>
            </div>
            <Link href="/admin/integracoes/mercado-livre/flex" class="text-sm text-primary hover:underline">Custo do Flex →</Link>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
            <CardStats stat-subtitle="VENDAS FLEX NO MÊS" :stat-title="String(resumo.vendas)" stat-icon-name="fas fa-bag-shopping" variant="primary" />
            <CardStats stat-subtitle="SAÍRAM DAQUI" :stat-title="String(resumo.sairam)" stat-icon-name="fas fa-motorcycle" variant="info" />
            <CardStats stat-subtitle="EM ROTA / COM ENTREGADOR" :stat-title="String(resumo.emRota)" stat-icon-name="fas fa-route" variant="warning" />
            <CardStats stat-subtitle="ENTREGUES" :stat-title="String(resumo.entregues)" stat-icon-name="fas fa-circle-check" variant="success" />
            <CardStats stat-subtitle="RETIRADAS COM FOTO" :stat-title="String(resumo.comComprovante)" stat-icon-name="fas fa-camera" variant="secondary" />
            <CardStats stat-subtitle="ALERTAS ABERTOS" :stat-title="String(resumo.alertas)" stat-icon-name="fas fa-triangle-exclamation" :variant="resumo.alertas ? 'error' : 'success'" />
        </div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-4">
                <div class="flex gap-2">
                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :class="filtros.aba === 'alertas' ? 'bg-error text-white' : 'text-slate-500 hover:bg-lighterror'"
                        @click="trocarAba('alertas')">
                        <i class="fas fa-triangle-exclamation mr-1"></i> Alertas ({{ resumo.alertas }})
                    </button>
                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm font-medium"
                        :class="filtros.aba === 'todos' ? 'bg-primary text-white' : 'text-slate-500 hover:bg-lightprimary'"
                        @click="trocarAba('todos')">
                        Todos do mês
                    </button>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div v-if="filtros.aba === 'todos'">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Mês</label>
                        <input v-model="mes" type="month" class="rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm" @change="filtrar()" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Buscar</label>
                        <input v-model="busca" type="text" placeholder="Pedido, venda ML, envio, cliente ou entregador"
                            class="w-72 rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm" @keyup.enter="filtrar()" />
                    </div>
                    <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" @click="filtrar()">Filtrar</button>
                    <button v-if="filtros.busca" type="button" class="px-2 py-2 text-sm text-slate-400 hover:text-primary" @click="busca = ''; filtrar({ busca: undefined })">Limpar</button>
                </div>
            </div>

            <div v-if="linhas.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-4 py-3 font-medium">Situação</th>
                            <th class="px-4 py-3 font-medium">Pedido</th>
                            <th class="px-4 py-3 font-medium">Cliente</th>
                            <th class="px-4 py-3 font-medium">Produtos</th>
                            <th class="px-4 py-3 font-medium">Saiu daqui</th>
                            <th class="px-4 py-3 font-medium">Retirada</th>
                            <th class="px-4 py-3 font-medium">Alertas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="linha in linhas" :key="linha.id"
                            class="cursor-pointer border-b border-[var(--surface-border)] last:border-0 hover:bg-lightprimary"
                            :class="{ 'bg-lighterror': linha.alertasAbertos > 0 }"
                            @click="abrir(linha)">
                            <td class="px-4 py-3">
                                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold" :class="CORES_SITUACAO[linha.situacao.codigo]">
                                    {{ linha.situacao.rotulo }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                #{{ linha.pedido }}
                                <span class="block text-xs text-slate-400">{{ quando(linha.linhaDoTempo.vendidaEm) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                {{ linha.cliente ?? '—' }}
                                <span class="block text-xs text-slate-400">{{ linha.cidade }}</span>
                            </td>
                            <td class="max-w-[16rem] truncate px-4 py-3 text-xs" :title="resumoItens(linha)">{{ resumoItens(linha) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs">
                                {{ quando(linha.linhaDoTempo.entregueAoEntregadorEm) ?? '—' }}
                                <span v-if="linha.linhaDoTempo.rotaIniciadaEm" class="block text-slate-400">rota {{ quando(linha.linhaDoTempo.rotaIniciadaEm) }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <template v-if="linha.recibo">
                                    <i class="fas fa-camera mr-1" :class="linha.recibo.foto ? 'text-success' : 'text-slate-300'" :title="linha.recibo.foto ? 'Com foto' : 'Sem foto'"></i>
                                    <i class="fas fa-signature mr-1" :class="linha.recibo.assinatura ? 'text-success' : 'text-slate-300'" :title="linha.recibo.assinatura ? 'Com assinatura' : 'Sem assinatura'"></i>
                                    <i v-if="linha.recibo.retido" class="fas fa-scale-balanced text-warning" title="Retido como prova"></i>
                                </template>
                                <span v-else class="text-slate-400">—</span>
                            </td>
                            <td class="px-4 py-3">
                                <span v-for="alerta in alertasVisiveis(linha).slice(0, 2)" :key="alerta.codigo"
                                    class="mb-1 block rounded px-2 py-0.5 text-xs font-medium"
                                    :class="alerta.nivel === 'erro' ? 'bg-lighterror text-error' : 'bg-lightwarning text-warning'">
                                    {{ alerta.titulo }}
                                </span>
                                <span v-if="!alertasVisiveis(linha).length && linha.resolucao" class="text-xs text-success">
                                    <i class="fas fa-check"></i> {{ linha.resolucao.rotulo }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="p-6 text-sm text-slate-400">
                <template v-if="filtros.busca">Nenhum envio Flex encontrado pra "{{ filtros.busca }}".</template>
                <template v-else-if="filtros.aba === 'alertas'">Nenhum alerta aberto. Tudo que saiu está conferido.</template>
                <template v-else>Nenhuma venda Flex nesse mês.</template>
            </p>
        </div>

        <!-- Detalhe. Sem Teleport de propósito: fora do .admin-shell as
             variáveis de cor (--surface) não existem — ver o comentário do
             mesmo bug em Integracoes/MercadoLivre/Flex.vue. -->
        <div v-if="selecionado" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50" @click="fechar"></div>
            <div class="relative w-full max-w-4xl overflow-y-auto rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-xl" style="max-height: 92vh;">
                <button type="button" class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-[var(--surface-muted)]" aria-label="Fechar" @click="fechar">
                    <i class="fas fa-xmark"></i>
                </button>

                <div class="mb-4 flex flex-wrap items-start justify-between gap-3 pr-10">
                    <div>
                        <h3 class="text-lg font-semibold">
                            Pedido #{{ selecionado.pedido }}
                            <span class="ml-2 rounded-full px-2 py-0.5 align-middle text-xs font-semibold" :class="CORES_SITUACAO[selecionado.situacao.codigo]">{{ selecionado.situacao.rotulo }}</span>
                        </h3>
                        <p class="text-xs text-slate-400">
                            Venda ML {{ selecionado.venda ?? '—' }} · envio {{ selecionado.envio ?? '—' }} · {{ dinheiro(selecionado.total) }}
                        </p>
                    </div>
                    <div class="flex gap-2">
                        <button v-if="podeEditar" type="button" :disabled="enviando"
                            class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-lightprimary disabled:opacity-50"
                            title="Consulta o envio no Mercado Livre agora (só leitura)" @click="sincronizar">
                            <i class="fas fa-rotate mr-1"></i> Atualizar com o ML
                        </button>
                        <Link :href="`/admin/pedidos/${selecionado.pedido}`" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-lightprimary">
                            Ver pedido
                        </Link>
                    </div>
                </div>

                <!-- Alertas -->
                <div v-if="selecionado.alertas.length" class="mb-5 space-y-2">
                    <div v-for="alerta in selecionado.alertas" :key="alerta.codigo" class="rounded-lg border px-3 py-2 text-sm"
                        :class="alerta.resolvido
                            ? 'border-[var(--surface-border)] opacity-60'
                            : alerta.nivel === 'erro' ? 'border-error bg-lighterror' : alerta.nivel === 'aviso' ? 'border-warning bg-lightwarning' : 'border-[var(--surface-border)] bg-[var(--surface-muted)]'">
                        <p class="font-semibold" :class="alerta.resolvido ? '' : alerta.nivel === 'erro' ? 'text-error' : alerta.nivel === 'aviso' ? 'text-warning' : ''">
                            <i class="fas mr-1" :class="alerta.resolvido ? 'fa-check' : alerta.nivel === 'info' ? 'fa-circle-info' : 'fa-triangle-exclamation'"></i>
                            {{ alerta.titulo }}<span v-if="alerta.resolvido" class="font-normal"> — conferido</span>
                        </p>
                        <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-300">{{ alerta.detalhe }}</p>
                    </div>
                </div>

                <!-- Conferência -->
                <div class="mb-5 rounded-lg border border-[var(--surface-border)] p-4">
                    <template v-if="selecionado.resolucao">
                        <p class="text-sm">
                            <i class="fas fa-clipboard-check mr-1 text-success"></i>
                            <strong>{{ selecionado.resolucao.rotulo }}</strong>
                            <span class="text-slate-400"> — {{ selecionado.resolucao.por ?? 'alguém' }} em {{ quando(selecionado.resolucao.em) }}</span>
                        </p>
                        <p v-if="selecionado.resolucao.nota" class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ selecionado.resolucao.nota }}</p>
                        <button v-if="podeEditar" type="button" class="mt-2 text-xs text-slate-400 hover:text-error" :disabled="enviando" @click="desfazerResolucao">Desfazer conferência</button>
                    </template>
                    <template v-else-if="alertasVisiveis(selecionado).length && podeEditar">
                        <p class="mb-2 text-sm font-semibold">Registrar conferência</p>
                        <div class="mb-2 flex flex-wrap gap-2">
                            <label v-for="opcao in resolucoes" :key="opcao.tipo"
                                class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-sm"
                                :class="resolucaoTipo === opcao.tipo ? 'border-primary bg-lightprimary' : 'border-[var(--surface-border)]'">
                                <input v-model="resolucaoTipo" type="radio" :value="opcao.tipo" class="accent-primary" />
                                {{ opcao.rotulo }}
                            </label>
                        </div>
                        <textarea v-model="resolucaoNota" rows="2" maxlength="1000"
                            placeholder="O que foi feito? Ex.: bicicleta voltou com o entregador João em 12/09, conferida sem avaria."
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm"></textarea>
                        <button type="button" :disabled="!resolucaoTipo || enviando"
                            class="mt-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50" @click="resolver">
                            Registrar
                        </button>
                    </template>
                    <p v-else class="text-sm text-slate-400">Nenhuma pendência pra conferir.</p>
                </div>

                <!-- Linha do tempo: loja x canal -->
                <div class="mb-5 grid gap-4 md:grid-cols-2">
                    <div>
                        <h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Aqui na loja</h4>
                        <ul class="space-y-1 text-sm">
                            <li v-for="[rotulo, data] in passosLoja(selecionado)" :key="rotulo" class="flex justify-between gap-3">
                                <span :class="data ? '' : 'text-slate-400'">{{ rotulo }}</span>
                                <span class="whitespace-nowrap" :class="data ? 'font-medium' : 'text-slate-300'">{{ quando(data) ?? '—' }}</span>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">
                            Mercado Livre
                            <span class="font-normal normal-case">
                                · {{ selecionado.canal.status ?? 'sem consulta' }}{{ selecionado.canal.substatus ? ` / ${selecionado.canal.substatus}` : '' }}
                                <template v-if="selecionado.canal.conferidoEm">· conferido {{ quando(selecionado.canal.conferidoEm) }}</template>
                            </span>
                        </h4>
                        <ul class="space-y-1 text-sm">
                            <li v-for="[rotulo, data] in passosCanal(selecionado)" :key="rotulo" class="flex justify-between gap-3">
                                <span :class="data ? '' : 'text-slate-400'">{{ rotulo }}</span>
                                <span class="whitespace-nowrap" :class="data ? 'font-medium' : 'text-slate-300'">{{ quando(data) ?? '—' }}</span>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Comprovante de retirada -->
                <div class="mb-5 rounded-lg border border-[var(--surface-border)] p-4">
                    <h4 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Comprovante de retirada</h4>
                    <template v-if="selecionado.recibo">
                        <p class="text-sm">
                            <strong>{{ selecionado.recibo.entregador ?? 'Entregador sem nome informado' }}</strong>
                            <span class="text-slate-400"> · retirou {{ selecionado.recibo.caixas }} caixa(s) em {{ quando(selecionado.recibo.retiradoEm) }}</span>
                        </p>
                        <p class="mt-1 text-xs text-slate-400">
                            {{ selecionado.recibo.consentidoEm ? `Consentimento registrado em ${quando(selecionado.recibo.consentidoEm)}` : 'Sem consentimento — retirada sem imagens' }}
                        </p>

                        <p class="mt-3 rounded bg-lightwarning px-3 py-2 text-xs text-warning">
                            <i class="fas fa-lock mr-1"></i>
                            Uso restrito: comprovação da entrega e defesa em processo. Não compartilhe fora disso nem publique. Cada visualização fica registrada na auditoria.
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button v-if="(selecionado.recibo.foto || selecionado.recibo.assinatura) && !mostrarImagens" type="button"
                                class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-lightprimary" @click="mostrarImagens = true">
                                <i class="fas fa-eye mr-1"></i> Mostrar foto e assinatura
                            </button>
                            <a :href="`/admin/envios-flex/recibos/${selecionado.recibo.id}`" target="_blank" rel="noopener"
                                class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-lightprimary">
                                <i class="fas fa-print mr-1"></i> Comprovante pra imprimir / PDF
                            </a>
                        </div>

                        <div v-if="mostrarImagens" class="mt-3 grid gap-3 sm:grid-cols-2">
                            <figure v-if="selecionado.recibo.foto">
                                <img :src="`/admin/envios-flex/recibos/${selecionado.recibo.id}/foto`" alt="Foto de quem retirou" class="w-full rounded-lg border border-[var(--surface-border)]" />
                                <figcaption class="mt-1 text-xs text-slate-400">Foto tirada no envio</figcaption>
                            </figure>
                            <figure v-if="selecionado.recibo.assinatura">
                                <img :src="`/admin/envios-flex/recibos/${selecionado.recibo.id}/assinatura`" alt="Assinatura" class="w-full rounded-lg border border-[var(--surface-border)] bg-white" />
                                <figcaption class="mt-1 text-xs text-slate-400">Assinatura</figcaption>
                            </figure>
                        </div>

                        <div class="mt-4 border-t border-[var(--surface-border)] pt-3">
                            <template v-if="selecionado.recibo.retido">
                                <p class="text-sm">
                                    <i class="fas fa-scale-balanced mr-1 text-warning"></i>
                                    Retido como prova desde {{ quando(selecionado.recibo.retidoEm) }} — {{ selecionado.recibo.motivoRetencao }}
                                </p>
                                <button v-if="podeEditar" type="button" class="mt-1 text-xs text-slate-400 hover:text-error" :disabled="enviando" @click="liberar">Liberar retenção</button>
                            </template>
                            <template v-else-if="podeEditar">
                                <p class="mb-2 text-xs text-slate-400">
                                    As imagens são apagadas {{ retencaoDias }} dias após a retirada, menos quando algum pedido do recibo tem pendência. Pra guardar de vez (ex.: processo), retenha:
                                </p>
                                <div class="flex flex-wrap gap-2">
                                    <input v-model="motivoRetencao" type="text" maxlength="255" placeholder="Motivo (ex.: ação contra a transportadora)"
                                        class="min-w-[16rem] flex-1 rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm" />
                                    <button type="button" :disabled="!motivoRetencao.trim() || enviando"
                                        class="rounded-lg bg-warning px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50" @click="reter">
                                        Reter como prova
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>
                    <p v-else class="text-sm text-slate-400">
                        {{ selecionado.linhaDoTempo.entregueAoEntregadorEm ? 'Entregue ao entregador sem comprovante (baixa simples).' : 'Ainda não foi entregue ao entregador pelo KoraFlex.' }}
                    </p>
                </div>

                <!-- Pedido -->
                <div class="grid gap-4 text-sm md:grid-cols-2">
                    <div>
                        <h4 class="mb-1 text-xs font-bold uppercase tracking-wide text-slate-400">Cliente</h4>
                        <p>{{ selecionado.cliente ?? '—' }}</p>
                        <p class="text-xs text-slate-400">{{ selecionado.endereco }} {{ selecionado.cidade }}</p>
                    </div>
                    <div>
                        <h4 class="mb-1 text-xs font-bold uppercase tracking-wide text-slate-400">Produtos</h4>
                        <ul>
                            <li v-for="(item, i) in selecionado.itens" :key="i">{{ item.qtd }}x {{ item.nome }} <span v-if="item.sku" class="text-xs text-slate-400">({{ item.sku }})</span></li>
                        </ul>
                    </div>
                    <div v-if="selecionado.reclamacoes.length" class="md:col-span-2">
                        <h4 class="mb-1 text-xs font-bold uppercase tracking-wide text-slate-400">Reclamações no Mercado Livre</h4>
                        <ul>
                            <li v-for="r in selecionado.reclamacoes" :key="r.id">{{ r.tipo }} #{{ r.id }} — {{ r.status }} (aberta {{ quando(r.abertaEm) }})</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
