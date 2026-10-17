<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import SubNav from './SubNav.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Criar cupom do vendedor no Mercado Livre (pedido do Lira, 16/10/2026).
// A tela abre com o modelo validado e tudo é editável. O envio cria cupom
// de verdade no ML, então passa sempre pela confirmação com o resumo.
const props = defineProps({
    modelo: { type: Object, required: true },
    ticket: { type: Object, required: true },
    envioHabilitado: { type: Boolean, default: false },
    historico: { type: Array, default: () => [] },
});

const form = useForm({ ...props.modelo });

const reais = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const data = (v) => (v ? new Intl.DateTimeFormat('pt-BR').format(new Date(`${v}T00:00:00`)) : '');
const dataHora = (v) => (v ? new Date(v).toLocaleString('pt-BR') : '');

// Compra mínima acompanha o "% do ticket médio" enquanto ele for mexido;
// o valor em R$ continua editável à mão.
const recalcularMinima = () => {
    const pct = Number(form.compra_minima_pct_ticket) || 0;
    form.compra_minima = Math.max(1, Math.round(props.ticket.valor * pct) / 100);
};

const restaurar = () => {
    Object.assign(form, { ...props.modelo });
    form.clearErrors();
};

const dias = computed(() => {
    if (!form.inicio || !form.fim) return 0;
    return Math.round((new Date(`${form.fim}T00:00:00`) - new Date(`${form.inicio}T00:00:00`)) / 86400000) + 1;
});

// Regra do ML: percentual aplicado sobre a compra mínima tem que ficar
// abaixo do teto. Mostra antes de enviar para não descobrir pelo erro.
const limiteMinima = computed(() => (Number(form.percentual) > 0 ? Number(form.desconto_maximo) * 100 / Number(form.percentual) : 0));
const avisos = computed(() => {
    const a = [];
    if (Number(form.compra_minima) * Number(form.percentual) / 100 >= Number(form.desconto_maximo)) {
        a.push(`Com ${form.percentual}% e teto de ${reais(form.desconto_maximo)}, o ML só aceita compra mínima abaixo de ${reais(limiteMinima.value)}.`);
    }
    if (dias.value > 31) a.push('O Mercado Livre aceita no máximo 31 dias de cupom.');
    if (Number(form.orcamento) <= Number(form.desconto_maximo)) a.push('O orçamento precisa ser maior que o desconto máximo.');
    return a;
});

const cuponsNoOrcamento = computed(() => (Number(form.desconto_maximo) > 0 ? Math.floor(Number(form.orcamento) / Number(form.desconto_maximo)) : 0));

const confirmando = ref(false);
const abrirConfirmacao = () => { confirmando.value = true; };
const enviar = () => {
    form.post('/admin/integracoes/mercado-livre/cupom', {
        preserveScroll: true,
        onFinish: () => { confirmando.value = false; },
    });
};

const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
const rotulo = 'mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400';
</script>

<template>
    <Head title="Criar cupom — Mercado Livre" />

    <AdminLayout>
        <SubNav />

        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">Criar cupom</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Cupom do vendedor no Mercado Livre. Abre com o modelo validado; mude o que quiser antes de enviar.
            </p>
        </div>

        <div v-if="!envioHabilitado" class="mb-6 rounded-xl border border-warning/40 bg-warning/10 p-4 text-sm" role="status">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            Envio desligado neste ambiente: o homolog usa a mesma conta do Mercado Livre da loja. A tela funciona para conferir, mas o botão não cria cupom aqui.
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <form class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm lg:col-span-2" @submit.prevent="abrirConfirmacao">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="cupom-percentual" :class="rotulo">Desconto (%)</label>
                        <input id="cupom-percentual" v-model="form.percentual" type="number" step="1" min="5" max="80" :class="campo" />
                        <p v-if="form.errors.percentual" class="mt-1 text-xs text-error">{{ form.errors.percentual }}</p>
                    </div>
                    <div>
                        <label for="cupom-teto" :class="rotulo">Desconto máximo (R$)</label>
                        <input id="cupom-teto" v-model="form.desconto_maximo" type="number" step="0.01" min="5.01" :class="campo" />
                        <p v-if="form.errors.desconto_maximo" class="mt-1 text-xs text-error">{{ form.errors.desconto_maximo }}</p>
                    </div>
                    <div>
                        <label for="cupom-pct-ticket" :class="rotulo">Compra mínima (% do ticket médio)</label>
                        <input id="cupom-pct-ticket" v-model="form.compra_minima_pct_ticket" type="number" step="0.5" min="0" :class="campo" @input="recalcularMinima" />
                        <p class="mt-1 text-xs text-slate-400">Ticket médio do ML ({{ ticket.dias }} dias, {{ ticket.pedidos }} pedidos): {{ reais(ticket.valor) }}</p>
                    </div>
                    <div>
                        <label for="cupom-minima" :class="rotulo">Compra mínima (R$)</label>
                        <input id="cupom-minima" v-model="form.compra_minima" type="number" step="0.01" min="0.01" :class="campo" />
                        <p v-if="form.errors.compra_minima" class="mt-1 text-xs text-error">{{ form.errors.compra_minima }}</p>
                    </div>
                    <div>
                        <label for="cupom-orcamento" :class="rotulo">Orçamento total (R$)</label>
                        <input id="cupom-orcamento" v-model="form.orcamento" type="number" step="0.01" min="0.01" :class="campo" />
                        <p class="mt-1 text-xs text-slate-400">Acabou o orçamento, o ML encerra o cupom. Dá para cerca de {{ cuponsNoOrcamento }} usos no teto.</p>
                        <p v-if="form.errors.orcamento" class="mt-1 text-xs text-error">{{ form.errors.orcamento }}</p>
                    </div>
                    <div>
                        <label for="cupom-codigo" :class="rotulo">Código (opcional)</label>
                        <input id="cupom-codigo" v-model="form.codigo" type="text" maxlength="5" autocomplete="off" spellcheck="false" placeholder="Vazio = vale para todos" :class="campo" />
                        <p class="mt-1 text-xs text-slate-400">Sem código, o ML mostra o cupom nos anúncios para todo comprador.</p>
                        <p v-if="form.errors.codigo" class="mt-1 text-xs text-error">{{ form.errors.codigo }}</p>
                    </div>
                    <div>
                        <label for="cupom-inicio" :class="rotulo">Início</label>
                        <input id="cupom-inicio" v-model="form.inicio" type="date" :class="campo" />
                        <p v-if="form.errors.inicio" class="mt-1 text-xs text-error">{{ form.errors.inicio }}</p>
                    </div>
                    <div>
                        <label for="cupom-fim" :class="rotulo">Fim ({{ dias }} dias, máx. 31)</label>
                        <input id="cupom-fim" v-model="form.fim" type="date" :class="campo" />
                        <p v-if="form.errors.fim" class="mt-1 text-xs text-error">{{ form.errors.fim }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <label for="cupom-nome" :class="rotulo">Nome (só a loja vê)</label>
                        <input id="cupom-nome" v-model="form.nome" type="text" maxlength="60" :class="campo" />
                        <p v-if="form.errors.nome" class="mt-1 text-xs text-error">{{ form.errors.nome }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm sm:col-span-2">
                        <input v-model="form.incluir_anuncios" type="checkbox" class="rounded" />
                        Incluir todos os anúncios elegíveis no cupom
                    </label>
                </div>

                <ul v-if="avisos.length" class="mt-4 space-y-1 text-xs text-error" role="alert">
                    <li v-for="aviso in avisos" :key="aviso"><i class="fas fa-circle-exclamation mr-1"></i>{{ aviso }}</li>
                </ul>

                <div class="mt-6 flex flex-wrap items-center gap-3">
                    <button type="submit" :disabled="form.processing || avisos.length > 0"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:opacity-50">
                        Enviar modelo pré-selecionado
                    </button>
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-lightprimary dark:text-slate-400" @click="restaurar">
                        Voltar ao modelo
                    </button>
                </div>
            </form>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 text-sm shadow-sm">
                <h3 class="mb-3 text-base font-semibold">Como fica para o comprador</h3>
                <p class="mb-2">{{ form.percentual }}% de desconto no carrinho, limitado a {{ reais(form.desconto_maximo) }} por compra.</p>
                <p class="mb-2">Vale a partir de {{ reais(form.compra_minima) }} em produtos participantes.</p>
                <p class="mb-2">O desconto máximo é atingido em compras de {{ reais(limiteMinima) }} ou mais.</p>
                <p class="text-slate-500 dark:text-slate-400">Cada comprador usa uma vez. O cupom soma com a promoção ativa do anúncio e não muda o preço dele.</p>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] px-4 py-4">
                <h3 class="text-base font-semibold">Cupons enviados por aqui</h3>
            </div>
            <div v-if="historico.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-400">
                            <th class="px-4 py-3 font-medium">Enviado</th>
                            <th class="px-4 py-3 font-medium">Cupom</th>
                            <th class="px-4 py-3 font-medium">Regra</th>
                            <th class="px-4 py-3 font-medium">Período</th>
                            <th class="px-4 py-3 font-medium">Anúncios</th>
                            <th class="px-4 py-3 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in historico" :key="c.id" class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="px-4 py-3 whitespace-nowrap">{{ dataHora(c.criadoEm) }}<div class="text-xs text-slate-400">{{ c.usuario }}</div></td>
                            <td class="px-4 py-3">{{ c.nome }}<div class="text-xs text-slate-400">{{ c.mlId }}<span v-if="c.codigo"> · {{ c.codigo }}</span></div></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ c.percentual }}% até {{ reais(c.descontoMaximo) }}<div class="text-xs text-slate-400">mín. {{ reais(c.compraMinima) }} · orç. {{ reais(c.orcamento) }}</div></td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ data(c.inicio) }} a {{ data(c.fim) }}</td>
                            <td class="px-4 py-3">{{ c.itensAdicionados }}<span v-if="c.itensComErro" class="text-error"> ({{ c.itensComErro }} com erro)</span></td>
                            <td class="px-4 py-3">
                                <span :class="c.status === 'criado' ? 'text-success' : 'text-error'">{{ c.status === 'criado' ? 'Criado' : 'Erro' }}</span>
                                <div v-if="c.erro" class="max-w-xs truncate text-xs text-slate-400" :title="c.erro">{{ c.erro }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="px-4 py-6 text-sm text-slate-400">Nenhum cupom enviado por esta tela ainda.</p>
        </div>

        <ConfirmModal :open="confirmando" title="Criar este cupom no Mercado Livre?" confirm-label="Criar cupom" cancel-label="Voltar"
            :loading="form.processing" :confirm-disabled="!envioHabilitado" @close="confirmando = false" @confirm="enviar">
            <div class="space-y-3 text-sm">
                <p>Isso cria um cupom de verdade na conta do Mercado Livre da loja, pago pela loja.</p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1">
                    <dt class="text-slate-500 dark:text-slate-400">Desconto</dt><dd class="font-semibold">{{ form.percentual }}%</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Desconto máximo</dt><dd class="font-semibold">{{ reais(form.desconto_maximo) }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Compra mínima</dt><dd class="font-semibold">{{ reais(form.compra_minima) }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Orçamento</dt><dd class="font-semibold">{{ reais(form.orcamento) }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Período</dt><dd class="font-semibold">{{ data(form.inicio) }} a {{ data(form.fim) }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Código</dt><dd class="font-semibold">{{ form.codigo ? form.codigo.toUpperCase() : 'sem código (todos)' }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Anúncios</dt><dd class="font-semibold">{{ form.incluir_anuncios ? 'todos os elegíveis' : 'nenhum por enquanto' }}</dd>
                    <dt class="text-slate-500 dark:text-slate-400">Nome</dt><dd class="font-semibold">{{ form.nome }}</dd>
                </dl>
                <p v-if="!envioHabilitado" class="text-error">Envio desligado neste ambiente.</p>
            </div>
        </ConfirmModal>
    </AdminLayout>
</template>
