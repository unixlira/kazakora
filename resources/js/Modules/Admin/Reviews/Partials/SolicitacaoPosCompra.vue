<script setup>
// Pedido de avaliação pós-compra (pedido do Lira 2026-10-18): liga/desliga,
// texto do e-mail, cupom, prévia e teste. Busca e salva sozinho em
// /admin/avaliacoes/pos-compra, então funciona em qualquer tela de Avaliações.
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { notifyError, notifySuccess } from '@/Shared/notify';
import { computed, onMounted, ref } from 'vue';

const URL_BASE = '/admin/avaliacoes/pos-compra';

const { can } = usePermissions();
const podeEditar = computed(() => can('cadastros.edit'));

const carregando = ref(true);
const salvando = ref(false);
const enviandoTeste = ref(false);
const form = ref(null);
const salvo = ref(null);
const erros = ref({});
const dados = ref({ variaveis: {}, resumo: {}, recentes: [], horario: '', ativadoEm: null });
const previa = ref(null);
const campoEmFoco = ref('mensagem');

const alterado = computed(() => form.value && salvo.value && JSON.stringify(form.value) !== JSON.stringify(salvo.value));

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function chamar(metodo, caminho = '', corpo = null) {
    const resposta = await fetch(URL_BASE + caminho, {
        method: metodo,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: corpo ? JSON.stringify(corpo) : null,
    });
    const json = await resposta.json().catch(() => ({}));
    if (!resposta.ok) {
        erros.value = Object.fromEntries(Object.entries(json.errors ?? {}).map(([campo, msgs]) => [campo, msgs[0]]));
        throw new Error(json.message || 'Não deu certo. Tente de novo.');
    }
    erros.value = {};
    return json;
}

function aplicar(json) {
    dados.value = json;
    form.value = { ...json.config };
    salvo.value = { ...json.config };
}

async function carregar() {
    carregando.value = true;
    try {
        aplicar(await chamar('GET'));
    } catch (erro) {
        notifyError(erro.message);
    } finally {
        carregando.value = false;
    }
}

async function salvar() {
    salvando.value = true;
    try {
        const json = await chamar('PUT', '', form.value);
        aplicar(json);
        notifySuccess(json.message);
    } catch (erro) {
        notifyError(erro.message);
    } finally {
        salvando.value = false;
    }
}

async function alternarAtivo() {
    form.value.ativo = !form.value.ativo;
    await salvar();
}

async function verPrevia() {
    try {
        previa.value = await chamar('POST', '/previa', form.value);
    } catch (erro) {
        notifyError(erro.message);
    }
}

async function enviarTeste() {
    enviandoTeste.value = true;
    try {
        notifySuccess((await chamar('POST', '/teste', form.value)).message);
    } catch (erro) {
        notifyError(erro.message);
    } finally {
        enviandoTeste.value = false;
    }
}

function inserirVariavel(variavel) {
    const campo = ['assunto', 'titulo', 'mensagem', 'botao'].includes(campoEmFoco.value) ? campoEmFoco.value : 'mensagem';
    form.value[campo] = `${form.value[campo] ?? ''}${campo === 'mensagem' ? ' ' : ''}${variavel}`;
}

function restaurarTexto() {
    const padrao = salvo.value;
    if (!padrao) return;
    ['assunto', 'titulo', 'mensagem', 'botao'].forEach((campo) => { form.value[campo] = padrao[campo]; });
}

const descontoExemplo = computed(() => {
    if (!form.value?.cupom_ativo || !Number(form.value.cupom_valor)) return 'sem cupom';
    const valor = Number(form.value.cupom_valor).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
    return form.value.cupom_tipo === 'percentage' ? `${valor}% de desconto` : `R$ ${Number(form.value.cupom_valor).toLocaleString('pt-BR', { minimumFractionDigits: 2 })} de desconto`;
});

const STATUS = {
    enviado: { tone: 'green', label: 'Enviado' },
    enviando: { tone: 'blue', label: 'Enviando' },
    falhou: { tone: 'red', label: 'Falhou' },
    pulado: { tone: 'gray', label: 'Não enviado' },
};

const dataHora = (valor) => (valor ? new Date(valor).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—');

const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm disabled:opacity-60';

onMounted(carregar);
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-2xl">
                <h2 class="flex items-center gap-2 text-lg font-bold">
                    <i class="fas fa-envelope-open-text text-amber-500"></i>
                    Pedido de avaliação pós-compra
                    <StatusBadge v-if="form" :tone="salvo?.ativo ? 'green' : 'gray'" :label="salvo?.ativo ? 'Ligada' : 'Desligada'" />
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    Quando um pedido do kazakora.com é entregue, o cliente recebe um e-mail pedindo a avaliação de cada produto,
                    com um cupom só dele para a próxima compra. Envia de hora em hora, das {{ dados.horario || '9h às 20h' }}, um e-mail por pedido.
                    O cupom vai para todos, sem depender da nota.
                </p>
            </div>
            <button v-if="form && podeEditar" type="button" :disabled="salvando" @click="alternarAtivo"
                class="rounded-lg px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                :class="salvo?.ativo ? 'bg-slate-500 hover:bg-slate-600' : 'bg-primary hover:bg-primary-emphasis'">
                <i class="fas" :class="salvo?.ativo ? 'fa-pause' : 'fa-play'"></i>
                {{ salvo?.ativo ? 'Desligar rotina' : 'Ligar rotina' }}
            </button>
        </div>

        <p v-if="carregando" class="mt-4 text-sm text-slate-500"><i class="fas fa-spinner fa-spin"></i> Carregando...</p>

        <template v-else-if="form">
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="card in [
                    { rotulo: 'E-mails enviados', valor: dados.resumo.enviados, cor: '#16a34a' },
                    { rotulo: 'Avaliaram', valor: dados.resumo.avaliaram, cor: '#f59e0b' },
                    { rotulo: 'Cupons usados', valor: dados.resumo.cupons_usados, cor: '#2563eb' },
                    { rotulo: 'Na hora de enviar', valor: dados.resumo.na_fila, cor: '#7c3aed' },
                    { rotulo: 'Não enviados', valor: dados.resumo.pulados, cor: '#64748b' },
                    { rotulo: 'Falhas', valor: dados.resumo.falhas, cor: '#dc2626' },
                ]" :key="card.rotulo" class="rounded-xl border border-[var(--surface-border)] p-3">
                    <span class="block text-xs font-semibold" :style="{ color: card.cor }">{{ card.rotulo }}</span>
                    <span class="block text-2xl font-bold">{{ card.valor ?? 0 }}</span>
                </div>
            </div>

            <form class="mt-5 grid gap-5 lg:grid-cols-2" @submit.prevent="salvar">
                <fieldset :disabled="!podeEditar" class="space-y-3 rounded-xl border border-[var(--surface-border)] p-4">
                    <legend class="px-1 font-semibold">E-mail</legend>

                    <label class="block text-sm">
                        <span class="font-medium">Enviar quantos dias depois da entrega</span>
                        <input v-model.number="form.dias_apos_entrega" type="number" min="0" max="60" :class="campo" class="mt-1 max-w-[120px]">
                        <span class="mt-1 block text-xs text-slate-500">0 = na primeira rodada depois da entrega. Pedido entregue antes de ligar a rotina não recebe.</span>
                        <span v-if="erros.dias_apos_entrega" class="block text-xs text-red-600">{{ erros.dias_apos_entrega }}</span>
                    </label>

                    <label class="block text-sm">
                        <span class="font-medium">Assunto</span>
                        <input v-model="form.assunto" type="text" maxlength="150" :class="campo" class="mt-1" @focus="campoEmFoco = 'assunto'">
                        <span v-if="erros.assunto" class="block text-xs text-red-600">{{ erros.assunto }}</span>
                    </label>

                    <label class="block text-sm">
                        <span class="font-medium">Título</span>
                        <input v-model="form.titulo" type="text" maxlength="120" :class="campo" class="mt-1" @focus="campoEmFoco = 'titulo'">
                        <span v-if="erros.titulo" class="block text-xs text-red-600">{{ erros.titulo }}</span>
                    </label>

                    <label class="block text-sm">
                        <span class="font-medium">Mensagem</span>
                        <textarea v-model="form.mensagem" rows="7" maxlength="3000" :class="campo" class="mt-1" @focus="campoEmFoco = 'mensagem'"></textarea>
                        <span class="block text-xs text-slate-500">Linha em branco separa parágrafos. Os produtos com o botão "Avaliar" e o cupom entram depois do texto.</span>
                        <span v-if="erros.mensagem" class="block text-xs text-red-600">{{ erros.mensagem }}</span>
                    </label>

                    <label class="block text-sm">
                        <span class="font-medium">Texto do botão</span>
                        <input v-model="form.botao" type="text" maxlength="40" :class="campo" class="mt-1 max-w-xs" @focus="campoEmFoco = 'botao'">
                        <span v-if="erros.botao" class="block text-xs text-red-600">{{ erros.botao }}</span>
                    </label>

                    <div class="text-xs text-slate-500">
                        <span class="font-medium">Variáveis</span> (clique para inserir no campo selecionado):
                        <div class="mt-1 flex flex-wrap gap-1.5">
                            <button v-for="(descricao, variavel) in dados.variaveis" :key="variavel" type="button" :title="descricao"
                                class="rounded-md border border-[var(--surface-border)] px-2 py-0.5 font-mono hover:border-slate-400" @click="inserirVariavel(variavel)">
                                {{ variavel }}
                            </button>
                        </div>
                    </div>
                </fieldset>

                <div class="space-y-5">
                    <fieldset :disabled="!podeEditar" class="space-y-3 rounded-xl border border-[var(--surface-border)] p-4">
                        <legend class="px-1 font-semibold">Cupom para a próxima compra</legend>

                        <label class="flex items-center gap-2 text-sm">
                            <input v-model="form.cupom_ativo" type="checkbox" class="h-4 w-4 rounded">
                            <span class="font-medium">Mandar cupom no e-mail</span>
                        </label>

                        <template v-if="form.cupom_ativo">
                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-sm">
                                    <span class="font-medium">Tipo</span>
                                    <select v-model="form.cupom_tipo" :class="campo" class="mt-1">
                                        <option value="percentage">Porcentagem (%)</option>
                                        <option value="fixed">Valor fixo (R$)</option>
                                    </select>
                                </label>
                                <label class="block text-sm">
                                    <span class="font-medium">Desconto</span>
                                    <input v-model.number="form.cupom_valor" type="number" min="0" :max="form.cupom_tipo === 'percentage' ? 50 : 999" step="0.5" :class="campo" class="mt-1">
                                    <span v-if="erros.cupom_valor" class="block text-xs text-red-600">{{ erros.cupom_valor }}</span>
                                </label>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="sugestao in [5, 10, 15]" :key="sugestao" type="button"
                                    class="rounded-md border px-2.5 py-1 text-xs font-semibold"
                                    :class="form.cupom_tipo === 'percentage' && Number(form.cupom_valor) === sugestao ? 'border-amber-500 text-amber-600' : 'border-[var(--surface-border)] hover:border-slate-400'"
                                    @click="form.cupom_tipo = 'percentage'; form.cupom_valor = sugestao">
                                    {{ sugestao }}%
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="block text-sm">
                                    <span class="font-medium">Validade (dias)</span>
                                    <input v-model.number="form.cupom_validade_dias" type="number" min="1" max="365" :class="campo" class="mt-1">
                                    <span v-if="erros.cupom_validade_dias" class="block text-xs text-red-600">{{ erros.cupom_validade_dias }}</span>
                                </label>
                                <label class="block text-sm">
                                    <span class="font-medium">Compra mínima (R$)</span>
                                    <input v-model.number="form.cupom_pedido_minimo" type="number" min="0" step="0.01" placeholder="Sem mínimo" :class="campo" class="mt-1">
                                </label>
                            </div>
                            <label class="block text-sm">
                                <span class="font-medium">Começo do código</span>
                                <input v-model.trim="form.cupom_prefixo" type="text" maxlength="12" :class="campo" class="mt-1 max-w-[180px] font-mono uppercase">
                                <span class="mt-1 block text-xs text-slate-500">Cada cliente ganha um código próprio, de uso único: ex. {{ (form.cupom_prefixo || 'AVALIE').toUpperCase() }}K7P2QX.</span>
                                <span v-if="erros.cupom_prefixo" class="block text-xs text-red-600">{{ erros.cupom_prefixo }}</span>
                            </label>
                        </template>
                        <p class="text-sm"><span class="text-slate-500">No e-mail:</span> <strong>{{ descontoExemplo }}</strong></p>
                    </fieldset>

                    <div class="flex flex-wrap gap-2">
                        <button v-if="podeEditar" type="submit" :disabled="salvando || !alterado"
                            class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                            {{ salvando ? 'Salvando...' : 'Salvar' }}
                        </button>
                        <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:border-slate-400" @click="verPrevia">
                            <i class="far fa-eye"></i> Ver prévia
                        </button>
                        <button v-if="podeEditar" type="button" :disabled="enviandoTeste"
                            class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:border-slate-400 disabled:opacity-50" @click="enviarTeste">
                            <i class="far fa-paper-plane"></i> {{ enviandoTeste ? 'Enviando...' : 'Enviar teste para mim' }}
                        </button>
                        <button v-if="podeEditar && alterado" type="button" class="px-2 py-2 text-sm text-slate-500 hover:underline" @click="restaurarTexto">
                            Desfazer texto
                        </button>
                    </div>
                    <p v-if="alterado" class="text-xs text-amber-600">Há mudanças sem salvar. A prévia e o teste já usam o que está na tela.</p>
                </div>
            </form>

            <div class="mt-6">
                <h3 class="mb-2 font-semibold">Últimos envios</h3>
                <p v-if="!dados.recentes.length" class="text-sm text-slate-500">Nenhum e-mail ainda.</p>
                <div v-else class="overflow-x-auto rounded-xl border border-[var(--surface-border)]">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Pedido</th>
                                <th class="px-3 py-2">Cliente</th>
                                <th class="px-3 py-2">Situação</th>
                                <th class="px-3 py-2">Enviado</th>
                                <th class="px-3 py-2">Cupom</th>
                                <th class="px-3 py-2">Avaliou</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in dados.recentes" :key="item.id" class="border-t border-[var(--surface-border)]">
                                <td class="px-3 py-2"><a :href="`/admin/pedidos/${item.order_id}`" class="font-mono text-primary hover:underline">#{{ item.pedido }}</a></td>
                                <td class="px-3 py-2">{{ item.cliente || '—' }}<span class="block text-xs text-slate-500">{{ item.email || 'sem e-mail' }}</span></td>
                                <td class="px-3 py-2">
                                    <StatusBadge :tone="STATUS[item.status]?.tone ?? 'gray'" :label="STATUS[item.status]?.label ?? item.status" />
                                    <span v-if="item.erro" class="block max-w-[260px] text-xs text-slate-500" :title="item.erro">{{ item.erro }}</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ dataHora(item.enviado_em) }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="item.cupom" class="font-mono">{{ item.cupom }}</span><span v-else>—</span>
                                    <span v-if="item.cupom_usado" class="block text-xs font-semibold text-green-600">usado</span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span v-if="item.avaliado_em" class="text-amber-600"><i class="fas fa-star"></i> {{ dataHora(item.avaliado_em) }}</span>
                                    <span v-else class="text-slate-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>

        <Teleport to="body">
            <div v-if="previa" class="fixed inset-0 z-[100] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Prévia do e-mail">
                <div class="absolute inset-0 bg-black/50" @click="previa = null"></div>
                <div class="relative flex h-[90vh] w-full max-w-[720px] flex-col overflow-hidden rounded-2xl bg-[var(--surface)] shadow-xl">
                    <div class="flex items-center justify-between gap-3 border-b border-[var(--surface-border)] px-4 py-3">
                        <div class="min-w-0 text-sm"><span class="text-slate-500">Assunto:</span> <strong>{{ previa.assunto }}</strong></div>
                        <button type="button" class="h-8 w-8 shrink-0 rounded-full hover:bg-slate-500/10" aria-label="Fechar" @click="previa = null"><i class="fas fa-xmark"></i></button>
                    </div>
                    <iframe :srcdoc="previa.html" title="Prévia do e-mail" class="w-full flex-1 bg-white" sandbox=""></iframe>
                </div>
            </div>
        </Teleport>
    </section>
</template>
