<script setup>
// Admin > Suporte > Chamados (pedido 2026-10-11): lista à esquerda e conversa
// à direita, no jeito da caixa do WhatsApp. Com "Atendimento humano" ligado a
// Manuela para e o atendente responde; desligado, ela volta a responder.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    chamados: { type: Array, required: true },
    filtro: { type: String, default: 'ativos' },
    busca: { type: String, default: '' },
    contagem: { type: Object, default: () => ({}) },
});

const STATUS = {
    conversa: { label: 'Com a Manuela', cor: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' },
    aberto: { label: 'Aberto', cor: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
    em_atendimento: { label: 'Em atendimento', cor: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300' },
    resolvido: { label: 'Resolvido', cor: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
    fechado: { label: 'Fechado', cor: 'bg-slate-200 text-slate-600 dark:bg-slate-500/15 dark:text-slate-300' },
};
const PRIORIDADE = {
    baixa: 'text-slate-400', normal: 'text-sky-500', alta: 'text-amber-500', urgente: 'text-red-500',
};
const FILTROS = [['ativos', 'Ativos'], ...Object.entries(STATUS).map(([k, v]) => [k, v.label])];

const totalAtivos = computed(() => ['conversa', 'aberto', 'em_atendimento'].reduce((s, k) => s + Number(props.contagem[k] ?? 0), 0));
const contar = (k) => (k === 'ativos' ? totalAtivos.value : Number(props.contagem[k] ?? 0));

const termo = ref(props.busca);
const filtrar = (status) => router.get('/admin/suporte', { status, busca: termo.value || undefined }, { preserveState: true, preserveScroll: true, only: ['chamados', 'filtro', 'busca', 'contagem'] });

const hora = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString()
        ? d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
        : d.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
};
const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '');
const preco = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v ?? 0);

// ---- conversa aberta ----
const selecionadoId = ref(Number(new URLSearchParams(window.location.search).get('c')) || null);
const chamado = ref(null);
const mensagens = ref([]);
const texto = ref('');
const enviando = ref(false);
const erro = ref('');
const caixa = ref(null);
let timer = null;

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const chamar = async (url, metodo = 'GET', corpo = null) => {
    const r = await fetch(url, {
        method: metodo,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest' },
        body: corpo ? JSON.stringify(corpo) : null,
    });
    const dados = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(dados.message || 'Não foi possível concluir.');
    return dados;
};

const rolarParaFim = async () => { await nextTick(); if (caixa.value) caixa.value.scrollTop = caixa.value.scrollHeight; };
const aplicar = (dados) => {
    const novas = dados.mensagens.length !== mensagens.value.length;
    chamado.value = dados.chamado;
    mensagens.value = dados.mensagens;
    if (novas) rolarParaFim();
};

const carregar = async () => {
    if (!selecionadoId.value) return;
    try { aplicar(await chamar(`/admin/suporte/${selecionadoId.value}/mensagens`)); } catch { /* tenta de novo no próximo ciclo */ }
};
const abrir = async (id) => {
    selecionadoId.value = id;
    mensagens.value = [];
    erro.value = '';
    window.history.replaceState({}, '', `/admin/suporte?${new URLSearchParams({ ...(props.filtro !== 'ativos' ? { status: props.filtro } : {}), c: id })}`);
    await carregar();
};

const acao = async (fn) => {
    erro.value = '';
    try { aplicar(await fn()); router.reload({ only: ['chamados', 'contagem'] }); } catch (e) { erro.value = e.message; }
};
const enviar = async () => {
    const msg = texto.value.trim();
    if (!msg || enviando.value) return;
    enviando.value = true;
    await acao(() => chamar(`/admin/suporte/${selecionadoId.value}/mensagens`, 'POST', { mensagem: msg }));
    if (!erro.value) texto.value = '';
    enviando.value = false;
};
const alternarHumano = () => acao(() => chamar(`/admin/suporte/${selecionadoId.value}/humano`, 'POST'));
const atualizar = (campos) => acao(() => chamar(`/admin/suporte/${selecionadoId.value}`, 'PATCH', campos));
const excluir = async () => {
    if (!confirm(`Excluir o chamado ${chamado.value.numero} e todas as mensagens? Não dá para desfazer.`)) return;
    try {
        await chamar(`/admin/suporte/${selecionadoId.value}`, 'DELETE');
        selecionadoId.value = null; chamado.value = null; mensagens.value = [];
        router.reload({ only: ['chamados', 'contagem'] });
    } catch (e) { erro.value = e.message; }
};
const teclar = (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); enviar(); } };

onMounted(() => {
    carregar();
    timer = setInterval(() => { carregar(); router.reload({ only: ['chamados', 'contagem'] }); }, 5000);
});
onUnmounted(() => clearInterval(timer));

// ---- novo chamado ----
const novoAberto = ref(false);
const novo = useForm({ email: '', pedido: '', assunto: '', mensagem: '', prioridade: 'normal' });
const criar = () => novo.post('/admin/suporte', {
    preserveScroll: true,
    onSuccess: () => { novoAberto.value = false; novo.reset(); const c = Number(new URLSearchParams(window.location.search).get('c')); if (c) abrir(c); },
});

const AUTOR = {
    cliente: { lado: 'justify-start', balao: 'bg-white text-slate-800 dark:bg-slate-800 dark:text-slate-100 rounded-tl-sm', nome: null },
    manuela: { lado: 'justify-end', balao: 'bg-[#d9fdd3] text-slate-800 dark:bg-emerald-900/60 dark:text-emerald-50 rounded-tr-sm', nome: 'Manuela (IA)' },
    atendente: { lado: 'justify-end', balao: 'bg-[#cfe9ff] text-slate-800 dark:bg-sky-900/60 dark:text-sky-50 rounded-tr-sm', nome: 'Atendente' },
};
</script>

<template>
    <Head title="Suporte" />

    <AdminLayout>
        <div class="min-w-0 space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 font-display text-2xl font-semibold"><i class="fas fa-headset text-[#F27A2A]"></i> Suporte</h1>
                    <p class="text-sm text-slate-500">Conversas dos clientes com a Manuela e os chamados abertos por elas.</p>
                </div>
                <button type="button" class="rounded-xl bg-[#F27A2A] px-4 py-2 text-sm font-semibold text-white shadow hover:brightness-95" @click="novoAberto = true">
                    <i class="fas fa-plus mr-1"></i> Novo chamado
                </button>
            </div>

            <div class="grid h-[calc(100vh-11rem)] min-h-[520px] overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm md:grid-cols-[340px_1fr]">
                <!-- Lista -->
                <aside class="flex min-h-0 flex-col border-r border-[var(--surface-border)]" :class="selecionadoId ? 'hidden md:flex' : 'flex'">
                    <div class="space-y-2 border-b border-[var(--surface-border)] p-3">
                        <form class="relative" @submit.prevent="filtrar(filtro)">
                            <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                            <input v-model="termo" type="search" placeholder="Buscar cliente, pedido ou chamado" class="w-full rounded-full border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-8 pr-3 text-sm">
                        </form>
                        <div class="flex gap-1.5 overflow-x-auto pb-1">
                            <button v-for="[k, rotulo] in FILTROS" :key="k" type="button"
                                class="whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold"
                                :class="filtro === k ? 'bg-emerald-600 text-white' : 'bg-[var(--surface-muted)] text-slate-600 dark:text-slate-300'"
                                @click="filtrar(k)">
                                {{ rotulo }} <span class="opacity-70">{{ contar(k) }}</span>
                            </button>
                        </div>
                    </div>

                    <ul class="min-h-0 flex-1 overflow-y-auto">
                        <li v-if="!chamados.length" class="p-6 text-center text-sm text-slate-500">Nenhum chamado aqui.</li>
                        <li v-for="c in chamados" :key="c.id">
                            <button type="button" class="flex w-full gap-3 border-b border-[var(--surface-border)] px-3 py-3 text-left hover:bg-[var(--surface-muted)]"
                                :class="c.id === selecionadoId ? 'bg-[var(--surface-muted)]' : ''" @click="abrir(c.id)">
                                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#F27A2A] to-[#e0480f] text-sm font-bold uppercase text-white">
                                    {{ (c.cliente?.nome ?? '?').slice(0, 2) }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center justify-between gap-2">
                                        <span class="truncate text-sm font-semibold">{{ c.cliente?.nome ?? 'Cliente removido' }}</span>
                                        <span class="shrink-0 text-[11px]" :class="c.nao_lidas ? 'font-bold text-emerald-600' : 'text-slate-400'">{{ hora(c.ultima_mensagem_em ?? c.criado_em) }}</span>
                                    </span>
                                    <span class="mt-0.5 flex items-center justify-between gap-2">
                                        <span class="truncate text-xs text-slate-500">
                                            <i v-if="c.humano_ativo" class="fas fa-user-tie mr-1 text-violet-500" title="Atendimento humano"></i>{{ c.previa || c.assunto }}
                                        </span>
                                        <span v-if="c.nao_lidas" class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[11px] font-bold text-white">{{ c.nao_lidas }}</span>
                                    </span>
                                    <span class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="STATUS[c.status]?.cor">{{ STATUS[c.status]?.label }}</span>
                                        <i class="fas fa-flag text-[10px]" :class="PRIORIDADE[c.prioridade]" :title="`Prioridade ${c.prioridade}`"></i>
                                        <span v-if="c.pedido" class="text-[10px] font-bold italic text-[#F27A2A]">{{ c.pedido.numero }}</span>
                                    </span>
                                </span>
                            </button>
                        </li>
                    </ul>
                </aside>

                <!-- Conversa -->
                <section v-if="chamado" class="flex min-h-0 flex-col">
                    <header class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] bg-[var(--surface-muted)] px-4 py-2.5">
                        <button type="button" class="md:hidden" aria-label="Voltar" @click="selecionadoId = null; chamado = null"><i class="fas fa-arrow-left"></i></button>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ chamado.cliente?.nome }} <span class="text-xs font-normal text-slate-500">{{ chamado.cliente?.email }}</span></p>
                            <p class="truncate text-xs text-slate-500">
                                <b class="font-mono">{{ chamado.numero }}</b> · {{ chamado.assunto || 'Sem assunto' }}
                                <template v-if="chamado.pedido"> · pedido <a :href="`/admin/pedidos/${chamado.pedido.id}`" class="font-bold italic text-[#F27A2A]">{{ chamado.pedido.numero }}</a> ({{ preco(chamado.pedido.total) }})</template>
                            </p>
                        </div>
                        <label class="flex cursor-pointer items-center gap-2 rounded-full border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-xs font-semibold">
                            <span>Atendimento humano</span>
                            <button type="button" role="switch" :aria-checked="chamado.humano_ativo"
                                class="relative h-5 w-9 rounded-full transition" :class="chamado.humano_ativo ? 'bg-violet-600' : 'bg-slate-300 dark:bg-slate-600'" @click="alternarHumano">
                                <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all" :class="chamado.humano_ativo ? 'left-[18px]' : 'left-0.5'"></span>
                            </button>
                        </label>
                        <select :value="chamado.status" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-xs" @change="atualizar({ status: $event.target.value })">
                            <option v-for="(s, k) in STATUS" :key="k" :value="k">{{ s.label }}</option>
                        </select>
                        <select :value="chamado.prioridade" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-xs" @change="atualizar({ prioridade: $event.target.value })">
                            <option v-for="p in Object.keys(PRIORIDADE)" :key="p" :value="p">Prioridade {{ p }}</option>
                        </select>
                        <button type="button" class="rounded-lg px-2 py-1.5 text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Excluir chamado" @click="excluir"><i class="fas fa-trash"></i></button>
                    </header>

                    <p v-if="chamado.resumo" class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <i class="fas fa-robot mr-1"></i> <b>Resumo da Manuela:</b> {{ chamado.resumo }}
                    </p>

                    <div ref="caixa" class="chat-fundo min-h-0 flex-1 space-y-2 overflow-y-auto px-4 py-4 sm:px-10">
                        <template v-for="m in mensagens" :key="m.id">
                            <div v-if="m.autor === 'sistema'" class="flex justify-center">
                                <span class="rounded-lg bg-[#fff5c4] px-3 py-1 text-center text-[11px] text-slate-700 shadow-sm dark:bg-amber-900/50 dark:text-amber-100">{{ m.conteudo }}</span>
                            </div>
                            <div v-else class="flex" :class="AUTOR[m.autor]?.lado">
                                <div class="max-w-[78%] rounded-xl px-3 py-1.5 text-sm shadow-sm" :class="AUTOR[m.autor]?.balao">
                                    <p v-if="AUTOR[m.autor]?.nome" class="text-[11px] font-bold" :class="m.autor === 'manuela' ? 'text-emerald-700 dark:text-emerald-300' : 'text-sky-700 dark:text-sky-300'">{{ AUTOR[m.autor].nome }}</p>
                                    <p class="whitespace-pre-line break-words">{{ m.conteudo }}</p>
                                    <p class="mt-0.5 text-right text-[10px] opacity-60">{{ dataHora(m.enviada_em) }}</p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <footer class="border-t border-[var(--surface-border)] bg-[var(--surface-muted)] p-3">
                        <p v-if="erro" class="mb-2 text-xs text-red-600">{{ erro }}</p>
                        <div v-if="!chamado.humano_ativo" class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-[var(--surface)] px-4 py-3 text-sm text-slate-500">
                            <span><i class="fas fa-robot mr-1 text-emerald-600"></i> A Manuela está respondendo esta conversa.</span>
                            <button type="button" class="rounded-full bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:brightness-95" @click="alternarHumano">Assumir atendimento</button>
                        </div>
                        <form v-else class="flex items-end gap-2" @submit.prevent="enviar">
                            <textarea v-model="texto" rows="1" maxlength="2000" placeholder="Digite a resposta" class="max-h-32 min-h-[42px] flex-1 resize-none rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] px-4 py-2.5 text-sm" @keydown="teclar"></textarea>
                            <button type="submit" :disabled="enviando || !texto.trim()" class="flex h-[42px] w-[42px] items-center justify-center rounded-full bg-emerald-600 text-white disabled:opacity-50" aria-label="Enviar">
                                <i class="fas" :class="enviando ? 'fa-circle-notch animate-spin' : 'fa-paper-plane'"></i>
                            </button>
                        </form>
                    </footer>
                </section>
                <section v-else class="hidden flex-col items-center justify-center gap-2 text-center text-slate-500 md:flex">
                    <i class="fas fa-comments text-5xl text-emerald-500/60"></i>
                    <p class="font-semibold">Escolha uma conversa</p>
                    <p class="max-w-xs text-sm">Ligue "Atendimento humano" para responder; desligado, a Manuela continua atendendo.</p>
                </section>
            </div>
        </div>

        <!-- Novo chamado -->
        <div v-if="novoAberto" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="novoAberto = false">
            <form class="w-full max-w-lg space-y-3 rounded-2xl bg-[var(--surface)] p-6 shadow-xl" @submit.prevent="criar">
                <h2 class="text-lg font-semibold">Novo chamado</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">E-mail do cliente
                        <input v-model="novo.email" type="email" required class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] px-3 py-2">
                        <span v-if="novo.errors.email" class="text-xs text-red-600">{{ novo.errors.email }}</span>
                    </label>
                    <label class="text-sm">Pedido (opcional)
                        <input v-model="novo.pedido" type="text" placeholder="261011K7Q2M9XD" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] px-3 py-2 uppercase">
                    </label>
                </div>
                <label class="block text-sm">Assunto
                    <input v-model="novo.assunto" type="text" required maxlength="160" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] px-3 py-2">
                </label>
                <label class="block text-sm">Primeira mensagem para o cliente
                    <textarea v-model="novo.mensagem" rows="3" required maxlength="2000" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] px-3 py-2"></textarea>
                </label>
                <label class="block text-sm">Prioridade
                    <select v-model="novo.prioridade" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] px-3 py-2">
                        <option v-for="p in Object.keys(PRIORIDADE)" :key="p" :value="p">{{ p }}</option>
                    </select>
                </label>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="rounded-lg px-4 py-2 text-sm" @click="novoAberto = false">Cancelar</button>
                    <button type="submit" :disabled="novo.processing" class="rounded-lg bg-[#F27A2A] px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Criar chamado</button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>

<style scoped>
.chat-fundo { background-color:#efeae2; background-image:radial-gradient(rgba(0,0,0,.035) 1px, transparent 1px); background-size:14px 14px; }
:global(.dark) .chat-fundo { background-color:#0b141a; background-image:radial-gradient(rgba(255,255,255,.03) 1px, transparent 1px); }
</style>
