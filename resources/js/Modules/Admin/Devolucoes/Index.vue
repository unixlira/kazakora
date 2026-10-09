<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Controle de devoluções e reclamações (pedido do usuário 2026-10-06). Ver
// DevolucoesController: ML e Shopee vêm das APIs, TikTok/Amazon à mão.
const props = defineProps({
    casos: { type: Array, default: () => [] },
    filtros: { type: Object, default: () => ({}) },
    resumo: { type: Object, default: () => ({}) },
    canais: { type: Object, default: () => ({}) },
    situacoes: { type: Object, default: () => ({}) },
    vereditos: { type: Object, default: () => ({}) },
    casoAberto: { type: Number, default: null },
});

const { can } = usePermissions();
const podeEditar = computed(() => can('operacional.edit'));

const CANAL_CORES = { mercado_livre: '#eda100', shopee: '#eb6834', tiktok_shop: '#2a78d6', amazon: '#c98500', loja: '#e87ba4' };

const busca = ref(props.filtros.busca ?? '');
const filtrar = (extra = {}) => router.get('/admin/devolucoes', {
    aba: props.filtros.aba,
    canal: props.filtros.canal || undefined,
    busca: busca.value || undefined,
    ...extra,
}, { preserveState: true, preserveScroll: true });

const dataHora = (iso) => (iso ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).format(new Date(iso)) : '—');
const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);

// "vence em 5h" / "venceu há 2 dias" — o prazo é o que mais importa na linha.
const prazo = (iso) => {
    if (!iso) return null;
    const horas = (new Date(iso) - new Date()) / 36e5;
    const abs = Math.abs(horas);
    const texto = abs < 48 ? `${Math.round(abs)}h` : `${Math.round(abs / 24)} dias`;

    return { vencido: horas < 0, urgente: horas >= 0 && horas < 24, texto: horas < 0 ? `venceu há ${texto}` : `vence em ${texto}` };
};

const prazoDoCaso = (caso) => (caso.situacao === 'aguardando_resposta' ? prazo(caso.prazoResposta)
    : caso.situacao === 'entregue' && caso.prazoConferir ? prazo(caso.prazoConferir) : null);

const ENVIO = { pending: 'Aguardando o comprador postar', shipped: 'Postado / a caminho', delivered: 'Entregue', not_delivered: 'Não entregue', cancelled: 'Cancelado' };
const DINHEIRO = { retained: 'Retido pela plataforma', refunded: 'Devolvido ao comprador', available: 'Liberado pra loja' };

const cards = computed(() => [
    { chave: 'semProduto', titulo: 'Encerradas sem o produto voltar', icone: 'fas fa-triangle-exclamation', cor: 'var(--color-error)', valor: props.resumo.semProduto },
    { chave: 'prazo', titulo: 'Prazo vencendo ou vencido', icone: 'fas fa-hourglass-half', cor: 'var(--color-error)', valor: props.resumo.prazo },
    { chave: 'responder', titulo: 'Aguardando nossa resposta', icone: 'fas fa-reply', cor: 'var(--color-warning)', valor: props.resumo.responder },
    { chave: 'mediacao', titulo: 'Em mediação', icone: 'fas fa-scale-balanced', cor: 'var(--color-warning)', valor: props.resumo.mediacao },
    { chave: 'voltando', titulo: 'Produto voltando', icone: 'fas fa-truck-arrow-right', cor: 'var(--color-info)', valor: props.resumo.voltando },
    { chave: 'conferir', titulo: 'Chegou — falta conferir', icone: 'fas fa-box-open', cor: 'var(--color-primary)', valor: props.resumo.conferir },
]);

// ---- Detalhe -----------------------------------------------------------
const aberto = ref(props.casos.find((c) => c.id === props.casoAberto) ?? null);
const veredito = ref('ok');
const vereditoNota = ref('');
const nota = ref('');
const novaSituacao = ref('');

const abrir = (caso) => {
    aberto.value = caso;
    veredito.value = caso.veredito ?? 'ok';
    vereditoNota.value = caso.vereditoNota ?? '';
    nota.value = '';
    novaSituacao.value = caso.situacao;
};

const agir = (acao, dados = {}) => router.post(`/admin/devolucoes/${aberto.value.id}`, { acao, ...dados }, {
    preserveScroll: true,
    onSuccess: (page) => { aberto.value = page.props.casos.find((c) => c.id === aberto.value.id) ?? null; nota.value = ''; },
});

// ---- Evidências (fotos e vídeos) -----------------------------------------
// Pedido do usuário 2026-10-07: até 30 MB por arquivo e vídeo de até 1
// minuto. A conferência aqui só poupa o upload — o servidor mede de novo.
const MAX_MB = 30;
const MAX_SEGUNDOS = 60;
const enviandoEvidencia = ref(false);
const progressoEvidencia = ref(0);
const erroEvidencia = ref('');
const inputEvidencia = ref(null);

const tamanho = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

const duracaoDoVideo = (arquivo) => new Promise((resolve) => {
    const video = document.createElement('video');
    const url = URL.createObjectURL(arquivo);
    video.preload = 'metadata';
    video.onloadedmetadata = () => { URL.revokeObjectURL(url); resolve(Number.isFinite(video.duration) ? video.duration : null); };
    video.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
    video.src = url;
});

const anexarEvidencias = async (evento) => {
    const arquivos = Array.from(evento.target.files ?? []);
    evento.target.value = '';
    erroEvidencia.value = '';
    if (!arquivos.length) return;

    const duracoes = [];
    for (const arquivo of arquivos) {
        if (arquivo.size > MAX_MB * 1048576) {
            erroEvidencia.value = `${arquivo.name} tem ${tamanho(arquivo.size)} — o máximo é ${MAX_MB} MB.`;
            return;
        }
        const segundos = arquivo.type.startsWith('video/') ? await duracaoDoVideo(arquivo) : null;
        if (segundos !== null && segundos > MAX_SEGUNDOS + 0.5) {
            erroEvidencia.value = `${arquivo.name} tem ${Math.round(segundos)} segundos — o máximo é 1 minuto.`;
            return;
        }
        duracoes.push(segundos ?? '');
    }

    enviandoEvidencia.value = true;
    progressoEvidencia.value = 0;
    router.post(`/admin/devolucoes/${aberto.value.id}/evidencias`, { arquivos, duracoes }, {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (p) => { progressoEvidencia.value = p?.percentage ?? 0; },
        onSuccess: (page) => { aberto.value = page.props.casos.find((c) => c.id === aberto.value.id) ?? null; },
        onError: (erros) => { erroEvidencia.value = Object.values(erros)[0] ?? 'Não consegui enviar.'; },
        onFinish: () => { enviandoEvidencia.value = false; },
    });
};

const removerEvidencia = (evidencia) => {
    if (!confirm(`Remover ${evidencia.tipo === 'video' ? 'o vídeo' : 'a foto'} ${evidencia.nome ?? ''}?`)) return;
    router.delete(`/admin/devolucoes/${aberto.value.id}/evidencias/${evidencia.id}`, {
        preserveScroll: true,
        onSuccess: (page) => { aberto.value = page.props.casos.find((c) => c.id === aberto.value.id) ?? null; },
    });
};

// ---- Registro manual (TikTok/Amazon) ----------------------------------
const registrando = ref(false);
const form = ref({ channel: 'tiktok_shop', pedido: '', kind: 'devolucao', reason_label: '', situacao: 'aguardando_resposta', tracking_number: '', respond_due_at: '' });
const registrar = () => router.post('/admin/devolucoes', form.value, { onSuccess: () => { registrando.value = false; } });
</script>

<template>
    <Head title="Devoluções" />

    <AdminLayout>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Devoluções e reclamações</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Mercado Livre e Shopee atualizam sozinhos a cada 30 min · TikTok e Amazon são registrados aqui</p>
            </div>
            <div v-if="podeEditar" class="flex gap-2">
                <button type="button" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm font-semibold hover:bg-[var(--surface-muted)]"
                    @click="router.post('/admin/devolucoes/sincronizar', {}, { preserveScroll: true })">
                    <i class="fas fa-rotate me-1"></i> Atualizar agora
                </button>
                <button type="button" class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:opacity-90" @click="registrando = true">
                    <i class="fas fa-plus me-1"></i> Registrar devolução
                </button>
            </div>
        </div>

        <!-- Resumo: o que pede ação agora -->
        <div class="mt-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <div v-for="card in cards" :key="card.chave" class="rounded-xl border border-[var(--surface-border)] p-3 shadow-sm"
                :style="{ borderTop: `3px solid ${card.cor}`, background: `linear-gradient(135deg, color-mix(in oklab, ${card.cor} ${card.valor ? 12 : 4}%, var(--surface)) 0%, var(--surface) 75%)` }">
                <div class="flex items-center justify-between">
                    <span class="text-2xl font-bold">{{ card.valor ?? 0 }}</span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-full text-sm text-white" :style="{ background: card.cor, opacity: card.valor ? 1 : 0.45 }"><i :class="card.icone"></i></span>
                </div>
                <p class="mt-1 text-xs leading-tight text-slate-500 dark:text-slate-400">{{ card.titulo }}</p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="mt-5 flex flex-wrap items-center gap-2">
            <div class="flex rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-1 text-sm">
                <button v-for="aba in [['pendentes', 'Pendentes'], ['todas', 'Todas (180 dias)']]" :key="aba[0]" type="button"
                    class="rounded-md px-3 py-1.5 font-semibold" :class="filtros.aba === aba[0] ? 'bg-primary text-white' : 'text-slate-500'"
                    @click="filtrar({ aba: aba[0] })">{{ aba[1] }}</button>
            </div>
            <select :value="filtros.canal ?? ''" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"
                @change="filtrar({ canal: $event.target.value || undefined })">
                <option value="">Todas as plataformas</option>
                <option v-for="(nome, chave) in canais" :key="chave" :value="chave">{{ nome }}</option>
            </select>
            <form class="flex min-w-[220px] flex-1" @submit.prevent="filtrar()">
                <input v-model="busca" type="search" placeholder="Pedido, nº da devolução ou rastreio"
                    class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" />
            </form>
        </div>

        <!-- Lista -->
        <div class="mt-4 overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <p v-if="casos.length === 0" class="p-6 text-center text-sm text-slate-500">
                {{ filtros.aba === 'pendentes' ? 'Nenhuma devolução ou reclamação pendente. 🎉' : 'Nenhum caso encontrado.' }}
            </p>
            <ul v-else class="divide-y divide-[var(--surface-border)]">
                <li v-for="caso in casos" :key="caso.id" class="cursor-pointer px-4 py-3 transition hover:bg-[var(--surface-muted)]" @click="abrir(caso)">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="inline-flex items-center gap-1.5 font-semibold">
                                    <span class="h-2.5 w-2.5 rounded-full" :style="{ background: CANAL_CORES[caso.canal] ?? '#999' }"></span>
                                    {{ canais[caso.canal] ?? caso.canal }}
                                </span>
                                <span class="text-slate-400">·</span>
                                <span>{{ caso.pedidoId ? `Pedido #${caso.pedidoId}` : `Pedido ${caso.pedidoExterno ?? '—'}` }}</span>
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="caso.tipo === 'reclamacao' ? 'bg-lightwarning text-warning' : 'bg-lightinfo text-info'">
                                    {{ caso.tipo === 'reclamacao' ? 'Reclamação' : 'Devolução' }}
                                </span>
                                <StatusBadge :status="caso.situacao" context="return" :label="situacoes[caso.situacao] ?? null" />
                            </div>
                            <p class="mt-1 truncate text-sm">
                                <span class="font-medium">{{ caso.motivo ?? 'Motivo não informado' }}</span>
                                <span v-if="caso.cliente" class="text-slate-500"> · {{ caso.cliente }}</span>
                            </p>
                            <p v-if="caso.produtos" class="truncate text-xs text-slate-400">{{ caso.produtos }}</p>
                            <div v-if="caso.alertas.length" class="mt-1.5 flex flex-wrap gap-1.5">
                                <span v-for="alerta in caso.alertas" :key="alerta.chave" class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="alerta.nivel === 'erro' ? 'bg-lighterror text-error' : 'bg-lightwarning text-warning'">
                                    <i class="fas fa-circle-exclamation text-[10px]"></i> {{ alerta.texto }}
                                </span>
                            </div>
                        </div>
                        <div class="shrink-0 text-right text-xs">
                            <p v-if="prazoDoCaso(caso)" class="font-bold" :class="prazoDoCaso(caso).vencido ? 'text-error' : prazoDoCaso(caso).urgente ? 'text-warning' : 'text-slate-500'">
                                <i class="fas fa-clock me-1"></i>{{ prazoDoCaso(caso).texto }}
                            </p>
                            <p class="text-slate-400">aberta {{ dataHora(caso.abertaEm) }}</p>
                            <p v-if="caso.rastreio" class="text-slate-400">rastreio {{ caso.rastreio }}</p>
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <!-- Detalhe do caso -->
        <div v-if="aberto" class="fixed inset-0 z-50 flex justify-end bg-black/40" @click.self="aberto = null">
            <aside class="h-full w-full max-w-xl overflow-y-auto bg-[var(--surface)] p-5 shadow-xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-400">{{ canais[aberto.canal] }} · {{ aberto.tipo === 'reclamacao' ? 'Reclamação' : 'Devolução' }} {{ aberto.manual ? '(registrada à mão)' : aberto.externo }}</p>
                        <h2 class="text-lg font-bold">{{ aberto.motivo ?? 'Motivo não informado' }}</h2>
                        <StatusBadge class="mt-1" :status="aberto.situacao" context="return" :label="situacoes[aberto.situacao] ?? null" />
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600" @click="aberto = null"><i class="fas fa-xmark text-lg"></i></button>
                </div>

                <div v-if="aberto.alertas.length" class="mt-3 space-y-1.5">
                    <p v-for="alerta in aberto.alertas" :key="alerta.chave" class="rounded-lg px-3 py-2 text-sm font-semibold"
                        :class="alerta.nivel === 'erro' ? 'bg-lighterror text-error' : 'bg-lightwarning text-warning'">
                        <i class="fas fa-circle-exclamation me-1"></i> {{ alerta.texto }}
                    </p>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt class="text-slate-500">Pedido</dt>
                    <dd><Link v-if="aberto.pedidoId" :href="`/admin/pedidos/${aberto.pedidoId}`" class="text-primary hover:underline">#{{ aberto.pedidoId }}</Link><span v-else>{{ aberto.pedidoExterno ?? '—' }}</span></dd>
                    <dt class="text-slate-500">Cliente</dt><dd>{{ aberto.cliente ?? '—' }}</dd>
                    <dt class="text-slate-500">Produtos</dt><dd>{{ aberto.produtos || '—' }}</dd>
                    <dt class="text-slate-500">Aberta em</dt><dd>{{ dataHora(aberto.abertaEm) }}</dd>
                    <template v-if="aberto.prazoResposta"><dt class="text-slate-500">Prazo de resposta</dt><dd>{{ dataHora(aberto.prazoResposta) }}</dd></template>
                    <template v-if="aberto.prazoConferir"><dt class="text-slate-500">Prazo pra conferir</dt><dd>{{ dataHora(aberto.prazoConferir) }}</dd></template>
                    <template v-if="aberto.statusEnvio"><dt class="text-slate-500">Envio de volta</dt><dd><StatusBadge :status="aberto.statusEnvio" :label="ENVIO[aberto.statusEnvio] ?? null" /></dd></template>
                    <template v-if="aberto.rastreio"><dt class="text-slate-500">Rastreio</dt><dd class="font-mono">{{ aberto.rastreio }}</dd></template>
                    <template v-if="aberto.dinheiro"><dt class="text-slate-500">Dinheiro</dt><dd>{{ DINHEIRO[aberto.dinheiro] ?? aberto.dinheiro }}</dd></template>
                    <template v-if="aberto.valor !== null"><dt class="text-slate-500">Valor do reembolso</dt><dd>{{ dinheiro(aberto.valor) }}</dd></template>
                    <template v-if="aberto.resolucao"><dt class="text-slate-500">Desfecho</dt><dd>{{ aberto.resolucao }}</dd></template>
                    <template v-if="aberto.statusPlataforma"><dt class="text-slate-500">Status na plataforma</dt><dd class="text-slate-400">{{ aberto.statusPlataforma }}</dd></template>
                </dl>

                <p v-if="aberto.estornoNoEnvio" class="mt-3 rounded-lg bg-lightwarning px-3 py-2 text-xs text-warning">
                    <i class="fas fa-circle-info me-1"></i> O comprador é reembolsado já na postagem — se o produto não chegar, a plataforma pode encerrar sem ele voltar. Acompanhe o rastreio.
                </p>

                <!-- Ações da equipe -->
                <div v-if="podeEditar" class="mt-5 space-y-4 rounded-xl border border-[var(--surface-border)] p-4">
                    <div v-if="aberto.recebidoEm || aberto.veredito" class="text-sm">
                        <p v-if="aberto.recebidoEm"><i class="fas fa-box text-success me-1"></i> Recebido em {{ dataHora(aberto.recebidoEm) }} {{ aberto.recebidoPor ? `por ${aberto.recebidoPor}` : '' }}</p>
                        <p v-if="aberto.veredito"><i class="fas fa-clipboard-check text-success me-1"></i> {{ vereditos[aberto.veredito] }} — {{ dataHora(aberto.vereditoEm) }} {{ aberto.vereditoPor ? `por ${aberto.vereditoPor}` : '' }}</p>
                        <p v-if="aberto.vereditoNota" class="text-slate-500">{{ aberto.vereditoNota }}</p>
                    </div>

                    <button v-if="!aberto.recebidoEm" type="button" class="w-full rounded-lg bg-success px-3 py-2 text-sm font-semibold text-white hover:opacity-90" @click="agir('receber')">
                        <i class="fas fa-box me-1"></i> O produto chegou aqui
                    </button>

                    <div>
                        <p class="mb-1 text-sm font-semibold">Veredito da conferência</p>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <select v-model="veredito" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <option v-for="(nome, chave) in vereditos" :key="chave" :value="chave">{{ nome }}</option>
                            </select>
                            <input v-model="vereditoNota" type="text" placeholder="O que foi encontrado (opcional)" class="flex-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" />
                        </div>
                        <button type="button" class="mt-2 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:opacity-90" @click="agir('veredito', { verdict: veredito, verdict_note: vereditoNota })">Salvar veredito</button>
                    </div>

                    <button v-if="aberto.pedidoId && !aberto.estoqueDevolvidoEm && aberto.veredito === 'ok'" type="button"
                        class="w-full rounded-lg border border-success px-3 py-2 text-sm font-semibold text-success hover:bg-lightsuccess" @click="agir('estoque')">
                        <i class="fas fa-warehouse me-1"></i> Devolver ao estoque
                    </button>
                    <p v-else-if="aberto.estoqueDevolvidoEm" class="text-xs text-slate-500"><i class="fas fa-warehouse me-1"></i> Estoque devolvido em {{ dataHora(aberto.estoqueDevolvidoEm) }}</p>

                    <div v-if="aberto.manual">
                        <p class="mb-1 text-sm font-semibold">Situação</p>
                        <div class="flex gap-2">
                            <select v-model="novaSituacao" class="flex-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <option v-for="(nome, chave) in situacoes" :key="chave" :value="chave">{{ nome }}</option>
                            </select>
                            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-semibold" @click="agir('situacao', { situacao: novaSituacao })">Salvar</button>
                        </div>
                    </div>

                    <form class="flex gap-2" @submit.prevent="nota && agir('nota', { nota })">
                        <input v-model="nota" type="text" placeholder="Anotar algo no histórico" class="flex-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" />
                        <button type="submit" class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-semibold">Anotar</button>
                    </form>
                </div>

                <!-- Evidências: foto e vídeo do que chegou -->
                <h3 class="mt-5 text-sm font-semibold">Evidências <span class="font-normal text-slate-400">· foto ou vídeo, até {{ MAX_MB }} MB, vídeo até 1 min</span></h3>
                <div v-if="aberto.evidencias?.length" class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <div v-for="evidencia in aberto.evidencias" :key="evidencia.id" class="group relative overflow-hidden rounded-lg border border-[var(--surface-border)] bg-black/5">
                        <video v-if="evidencia.tipo === 'video'" :src="evidencia.url" controls preload="metadata" class="aspect-square w-full bg-black object-contain"></video>
                        <a v-else :href="evidencia.url" target="_blank" rel="noopener">
                            <img :src="evidencia.url" :alt="evidencia.nome ?? 'Evidência'" loading="lazy" class="aspect-square w-full object-cover" />
                        </a>
                        <p class="truncate px-2 py-1 text-[11px] text-slate-500" :title="evidencia.nome">
                            <i :class="evidencia.tipo === 'video' ? 'fas fa-video' : 'fas fa-image'" class="me-1"></i>
                            {{ evidencia.duracao ? `${evidencia.duracao}s · ` : '' }}{{ tamanho(evidencia.tamanho) }}{{ evidencia.quem ? ` · ${evidencia.quem}` : '' }}
                        </p>
                        <button v-if="podeEditar" type="button" title="Remover"
                            class="absolute right-1 top-1 rounded-full bg-black/60 px-2 py-1 text-xs text-white opacity-80 hover:opacity-100" @click="removerEvidencia(evidencia)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <p v-else class="mt-2 text-sm text-slate-400">Nenhuma evidência anexada.</p>

                <div v-if="podeEditar" class="mt-2">
                    <input ref="inputEvidencia" type="file" accept="image/*,video/*" multiple class="hidden" @change="anexarEvidencias" />
                    <button type="button" :disabled="enviandoEvidencia"
                        class="w-full rounded-lg border border-dashed border-[var(--surface-border)] px-3 py-3 text-sm font-semibold hover:bg-black/5 disabled:opacity-60"
                        @click="inputEvidencia?.click()">
                        <template v-if="enviandoEvidencia"><i class="fas fa-spinner fa-spin me-1"></i> Enviando… {{ progressoEvidencia }}%</template>
                        <template v-else><i class="fas fa-camera me-1"></i> Anexar fotos ou vídeos</template>
                    </button>
                    <p v-if="erroEvidencia" class="mt-1 text-xs text-error"><i class="fas fa-circle-exclamation me-1"></i>{{ erroEvidencia }}</p>
                </div>

                <!-- Histórico: nenhum status se perde -->
                <h3 class="mt-5 text-sm font-semibold">Histórico</h3>
                <ol class="mt-2 space-y-2 border-l-2 border-[var(--surface-border)] pl-4">
                    <li v-for="(evento, i) in aberto.historico" :key="i" class="text-sm">
                        <p>{{ evento.texto }}</p>
                        <p class="text-xs text-slate-400">{{ dataHora(evento.quando) }}{{ evento.quem ? ` · ${evento.quem}` : '' }}</p>
                    </li>
                    <li v-if="!aberto.historico.length" class="text-sm text-slate-400">Sem registros ainda.</li>
                </ol>
            </aside>
        </div>

        <!-- Registro manual -->
        <div v-if="registrando" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="registrando = false">
            <form class="w-full max-w-lg space-y-3 rounded-xl bg-[var(--surface)] p-5 shadow-xl" @submit.prevent="registrar">
                <h2 class="text-lg font-bold">Registrar devolução ou reclamação</h2>
                <p class="text-xs text-slate-500">Para plataformas sem integração de devolução (TikTok, Amazon) ou caso que não apareceu sozinho.</p>
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm">Plataforma
                        <select v-model="form.channel" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2">
                            <option v-for="(nome, chave) in canais" :key="chave" :value="chave">{{ nome }}</option>
                        </select>
                    </label>
                    <label class="text-sm">Tipo
                        <select v-model="form.kind" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2">
                            <option value="devolucao">Devolução</option>
                            <option value="reclamacao">Reclamação</option>
                        </select>
                    </label>
                </div>
                <label class="block text-sm">Pedido (nº do Kazakora ou da plataforma)
                    <input v-model="form.pedido" required type="text" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2" />
                </label>
                <label class="block text-sm">Motivo
                    <input v-model="form.reason_label" required type="text" placeholder="Ex.: produto com defeito" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2" />
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm">Situação
                        <select v-model="form.situacao" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2">
                            <option v-for="chave in ['aguardando_resposta', 'em_mediacao', 'aguardando_envio', 'em_transito', 'entregue']" :key="chave" :value="chave">{{ situacoes[chave] }}</option>
                        </select>
                    </label>
                    <label class="text-sm">Prazo de resposta
                        <input v-model="form.respond_due_at" type="datetime-local" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2" />
                    </label>
                </div>
                <label class="block text-sm">Rastreio do envio de volta (se houver)
                    <input v-model="form.tracking_number" type="text" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2" />
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm" @click="registrando = false">Cancelar</button>
                    <button type="submit" class="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white">Registrar</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
