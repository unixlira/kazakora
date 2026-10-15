<script setup>
// Ofertas Relâmpago por empresa (pedido 2026-10-14): desconto % num produto,
// por 24h a partir do início escolhido (pedido 2026-10-15; dá para escolher
// outra duração ou um fim exato), só no site escolhido (KazaKora, FerraKora, Beauty
// Kora). A empresa fica na URL (?site=). Prévia com o lucro estimado por
// unidade (mesma conta da Oferta do Dia); prejuízo avisa, não bloqueia.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    site: { type: String, default: 'kazakora' },
    sites: { type: Array, default: () => [] },
    ofertas: { type: Array, default: () => [] },
    agora: { type: String, default: '' },
});

const { can } = usePermissions();
const nomeDoSite = computed(() => props.sites.find((s) => s.id === props.site)?.nome ?? props.site);

const trocarSite = (site) => {
    router.get('/admin/ofertas-relampago', { site }, { preserveScroll: true });
};

// datetime-local no fuso do navegador (o admin roda em America/Sao_Paulo).
const pad = (n) => String(n).padStart(2, '0');
const paraInput = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
const deInput = (v) => (v ? new Date(v) : new Date());

// Duração: 24h é o padrão (pedido 2026-10-15); 'exato' mostra o campo Fim.
const DURACOES = [2, 6, 12, 24, 48, 72];
const DURACAO_PADRAO = 24;
const vazio = () => ({ product_id: null, desconto_pct: 10, inicio_em: '', duracao: DURACAO_PADRAO, fim_em: '' });
const form = useForm(vazio());
const editandoId = ref(null);
const produtoEscolhido = ref(null);

// Busca de produto (só os ligados à empresa).
const busca = ref('');
const resultados = ref([]);
const buscando = ref(false);
let temporizador = null;
const buscarProdutos = () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(async () => {
        buscando.value = true;
        try {
            const r = await fetch(`/admin/ofertas-relampago/produtos?site=${props.site}&q=${encodeURIComponent(busca.value.trim())}`, { headers: { Accept: 'application/json' } });
            resultados.value = r.ok ? (await r.json()).produtos : [];
        } finally {
            buscando.value = false;
        }
    }, 250);
};
const escolher = (produto) => {
    produtoEscolhido.value = produto;
    form.product_id = produto.id;
    resultados.value = [];
    busca.value = '';
};

// Prévia do servidor: preço atual → preço na oferta e lucro por unidade.
const previa = ref(null);
let temporizadorPrevia = null;
watch(() => [form.product_id, form.desconto_pct], () => {
    clearTimeout(temporizadorPrevia);
    if (!form.product_id || form.desconto_pct === '' || form.desconto_pct === null) {
        previa.value = null;
        return;
    }
    temporizadorPrevia = setTimeout(async () => {
        const r = await fetch(`/admin/ofertas-relampago/previa?product_id=${form.product_id}&desconto_pct=${encodeURIComponent(form.desconto_pct)}`, { headers: { Accept: 'application/json' } });
        previa.value = r.ok ? (await r.json()).produto : null;
    }, 250);
});

// Atalhos de início.
const inicioAgora = () => { form.inicio_em = paraInput(new Date()); };
const inicioAmanha = (hora) => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    d.setHours(hora, 0, 0, 0);
    form.inicio_em = paraInput(d);
};

// Fim calculado (o servidor faz a mesma conta): início + duração ou o fim exato.
const fimPrevisto = computed(() => {
    if (form.duracao === 'exato') return form.fim_em || '';
    if (!form.inicio_em) return '';
    return paraInput(new Date(deInput(form.inicio_em).getTime() + Number(form.duracao) * 3600 * 1000));
});

const editar = (oferta) => {
    editandoId.value = oferta.id;
    produtoEscolhido.value = oferta.produto;
    const horas = (deInput(oferta.fim_em) - deInput(oferta.inicio_em)) / 3600000;
    const duracao = DURACOES.includes(horas) ? horas : 'exato';
    form.defaults({ product_id: oferta.product_id, desconto_pct: oferta.desconto_pct, inicio_em: oferta.inicio_em, duracao, fim_em: oferta.fim_em });
    form.reset();
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cancelar = () => {
    editandoId.value = null;
    produtoEscolhido.value = null;
    previa.value = null;
    form.defaults(vazio());
    form.reset();
    form.clearErrors();
};

const salvar = () => {
    const opcoes = { preserveScroll: true, onSuccess: cancelar };
    const corpo = ({ duracao, fim_em, ...data }) => (duracao === 'exato'
        ? { ...data, fim_em }
        : { ...data, duracao_horas: Number(duracao) });
    if (editandoId.value) {
        form.transform(corpo).put(`/admin/ofertas-relampago/${editandoId.value}`, opcoes);
    } else {
        form.transform((data) => ({ ...corpo(data), site: props.site })).post('/admin/ofertas-relampago', opcoes);
    }
};

const alternar = (oferta) => router.patch(`/admin/ofertas-relampago/${oferta.id}/ativo`, {}, { preserveScroll: true });

const excluir = async (oferta) => {
    if (await confirmDelete({ title: `Excluir a oferta de ${oferta.produto?.nome ?? 'produto'}?` })) {
        router.delete(`/admin/ofertas-relampago/${oferta.id}`, { preserveScroll: true });
    }
};

const SITUACAO = {
    agendada: { label: 'Agendada', tone: 'blue' },
    no_ar: { label: 'No ar', tone: 'green' },
    encerrada: { label: 'Encerrada', tone: 'gray' },
    pausada: { label: 'Pausada', tone: 'yellow' },
};

const preco = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const dataHora = (v) => {
    if (!v) return '—';
    const [d, h] = v.split('T');
    return `${d.split('-').reverse().join('/')} ${h}`;
};
const pct = (v) => `${Number(v).toLocaleString('pt-BR', { maximumFractionDigits: 2 })}%`;
const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
const atalho = 'rounded-md border border-[var(--surface-border)] px-2 py-0.5 text-xs hover:bg-slate-50 dark:hover:bg-slate-800';
</script>

<template>
    <Head title="Ofertas Relâmpago" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div class="max-w-2xl">
                <h1 class="mb-1 text-2xl font-bold">Ofertas Relâmpago</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Desconto num produto por 24 horas (ou a duração que você escolher), só no site da empresa escolhida. Vale para todas as variações e não soma com cupom nem com desconto por quantidade.
                </p>
            </div>
            <div role="tablist" aria-label="Empresa" class="inline-flex rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-1 shadow-sm">
                <button v-for="s in props.sites" :key="s.id" type="button" role="tab" :aria-selected="s.id === props.site"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                    :class="s.id === props.site ? 'bg-primary text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                    @click="trocarSite(s.id)">
                    {{ s.nome }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <form v-if="can(editandoId ? 'cadastros.edit' : 'cadastros.create')" @submit.prevent="salvar"
                class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm xl:col-span-1">
                <h2 class="mb-4 text-lg font-semibold">{{ editandoId ? 'Editar oferta' : `Nova oferta na ${nomeDoSite}` }}</h2>

                <div class="mb-4">
                    <label for="or-busca" class="mb-1 block text-sm font-medium">Produto *</label>
                    <div v-if="produtoEscolhido" class="flex items-center gap-3 rounded-lg border border-[var(--surface-border)] p-2">
                        <img v-if="produtoEscolhido.foto" :src="produtoEscolhido.foto" alt="" class="h-10 w-10 rounded object-cover">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ produtoEscolhido.nome }}</div>
                            <div class="font-mono text-xs text-slate-500">{{ produtoEscolhido.sku }}</div>
                        </div>
                        <button v-if="!editandoId" type="button" class="text-xs text-primary hover:underline" @click="produtoEscolhido = null; form.product_id = null">Trocar</button>
                    </div>
                    <div v-else class="relative">
                        <input id="or-busca" v-model="busca" type="search" autocomplete="off" :class="campo"
                            :placeholder="`Nome ou SKU de um produto da ${nomeDoSite}…`" @input="buscarProdutos" @focus="buscarProdutos">
                        <ul v-if="resultados.length" class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] shadow-lg">
                            <li v-for="p in resultados" :key="p.id">
                                <button type="button" class="flex w-full items-center gap-3 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800" @click="escolher(p)">
                                    <img v-if="p.foto" :src="p.foto" alt="" class="h-8 w-8 rounded object-cover">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm">{{ p.nome }}</span>
                                        <span class="block font-mono text-xs text-slate-500">{{ p.sku }} · {{ preco(p.preco_atual) }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                        <p v-if="buscando" class="mt-1 text-xs text-slate-400">Buscando…</p>
                    </div>
                    <InputError :message="form.errors.product_id" />
                </div>

                <div class="mb-4">
                    <label for="or-desconto" class="mb-1 block text-sm font-medium">Desconto (%) *</label>
                    <input id="or-desconto" v-model="form.desconto_pct" type="number" step="0.01" min="1" max="90" :class="campo">
                    <InputError :message="form.errors.desconto_pct" />
                </div>

                <div class="mb-2">
                    <label for="or-inicio" class="mb-1 block text-sm font-medium">Começa em *</label>
                    <input id="or-inicio" v-model="form.inicio_em" type="datetime-local" :class="campo">
                    <InputError :message="form.errors.inicio_em" />
                </div>
                <div class="mb-4 flex flex-wrap gap-1.5">
                    <button type="button" :class="atalho" @click="inicioAgora">Agora</button>
                    <button type="button" :class="atalho" @click="inicioAmanha(0)">Amanhã 00:00</button>
                    <button type="button" :class="atalho" @click="inicioAmanha(8)">Amanhã 08:00</button>
                </div>

                <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                    <div>
                        <label for="or-duracao" class="mb-1 block text-sm font-medium">Duração *</label>
                        <select id="or-duracao" v-model="form.duracao" :class="campo">
                            <option v-for="h in DURACOES" :key="h" :value="h">{{ h }} horas{{ h === DURACAO_PADRAO ? ' (padrão)' : '' }}</option>
                            <option value="exato">Até um horário exato</option>
                        </select>
                        <InputError :message="form.errors.duracao_horas" />
                    </div>
                    <div v-if="form.duracao === 'exato'">
                        <label for="or-fim" class="mb-1 block text-sm font-medium">Termina em *</label>
                        <input id="or-fim" v-model="form.fim_em" type="datetime-local" :class="campo">
                        <InputError :message="form.errors.fim_em" />
                    </div>
                    <div v-else class="self-end pb-2 text-sm text-slate-500" aria-live="polite">
                        Termina em <strong class="text-slate-700 dark:text-slate-200">{{ fimPrevisto ? dataHora(fimPrevisto) : '—' }}</strong>
                    </div>
                </div>

                <div v-if="previa" class="mb-4 rounded-lg border border-[var(--surface-border)] p-3 text-sm" aria-live="polite">
                    <div class="flex justify-between"><span class="text-slate-500">Preço atual (anúncio, sem frete)</span><span>{{ preco(previa.preco_atual) }}</span></div>
                    <div class="flex justify-between font-semibold"><span>Preço na oferta</span><span>{{ preco(previa.preco_oferta) }}</span></div>
                    <div class="mt-1 flex justify-between">
                        <span class="text-slate-500">Lucro estimado por unidade</span>
                        <span v-if="previa.lucro_oferta === null" class="text-slate-400">sem custo cadastrado</span>
                        <span v-else :class="previa.lucro_oferta < 0 ? 'font-semibold text-red-600' : 'text-emerald-600'">{{ preco(previa.lucro_oferta) }}</span>
                    </div>
                    <p v-if="previa.lucro_oferta !== null && previa.lucro_oferta < 0" class="mt-2 rounded-md bg-red-50 px-2 py-1.5 text-xs font-medium text-red-700 dark:bg-red-900/30 dark:text-red-300">
                        <i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i>Com esse desconto o produto dá prejuízo (pior caso entre cartão e Pix, com o frete).
                    </p>
                </div>

                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing || !form.product_id || !form.inicio_em"
                        class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-bolt'" aria-hidden="true"></i>
                        {{ editandoId ? 'Salvar alterações' : 'Criar oferta' }}
                    </button>
                    <button v-if="editandoId" type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm" @click="cancelar">Cancelar</button>
                </div>
            </form>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm xl:col-span-2">
                <h2 class="p-5 pb-0 text-lg font-semibold">Ofertas da {{ nomeDoSite }}</h2>
                <div class="overflow-x-auto p-5">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase text-slate-400">
                                <th scope="col" class="pb-2 pr-4">Produto</th>
                                <th scope="col" class="pb-2 pr-4">Desconto</th>
                                <th scope="col" class="pb-2 pr-4">Preço</th>
                                <th scope="col" class="pb-2 pr-4">Horário</th>
                                <th scope="col" class="pb-2 pr-4">Situação</th>
                                <th scope="col" class="pb-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.ofertas.length === 0">
                                <td colspan="6" class="py-8 text-center text-slate-400">Nenhuma Oferta Relâmpago na {{ nomeDoSite }}. Crie a primeira ao lado.</td>
                            </tr>
                            <tr v-for="oferta in props.ofertas" :key="oferta.id" class="border-b border-[var(--surface-border)] align-top last:border-0"
                                :class="{ 'opacity-60': oferta.situacao === 'encerrada' }">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-3">
                                        <img v-if="oferta.produto?.foto" :src="oferta.produto.foto" alt="" class="h-10 w-10 shrink-0 rounded object-cover">
                                        <div class="min-w-0">
                                            <div class="max-w-xs truncate font-medium">{{ oferta.produto?.nome ?? 'Produto removido' }}</div>
                                            <div class="font-mono text-xs text-slate-500">{{ oferta.produto?.sku }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4 font-semibold">{{ pct(oferta.desconto_pct) }}</td>
                                <td class="whitespace-nowrap py-3 pr-4">
                                    <span class="text-slate-400 line-through">{{ preco(oferta.produto?.preco_atual) }}</span>
                                    <i class="fas fa-arrow-right mx-1 text-xs text-slate-400" aria-hidden="true"></i>
                                    <span class="font-semibold">{{ preco(oferta.produto?.preco_oferta) }}</span>
                                    <div v-if="oferta.produto?.lucro_oferta !== null && oferta.produto?.lucro_oferta !== undefined" class="text-xs"
                                        :class="oferta.produto.lucro_oferta < 0 ? 'font-semibold text-red-600' : 'text-slate-500'">
                                        Lucro {{ preco(oferta.produto.lucro_oferta) }}/un.
                                    </div>
                                </td>
                                <td class="whitespace-nowrap py-3 pr-4 text-xs">
                                    <div>{{ dataHora(oferta.inicio_em) }}</div>
                                    <div class="text-slate-500">até {{ dataHora(oferta.fim_em) }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <StatusBadge :tone="SITUACAO[oferta.situacao].tone" :label="SITUACAO[oferta.situacao].label" />
                                </td>
                                <td class="py-3">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" title="Editar" aria-label="Editar" @click="editar(oferta)">
                                            <i class="fas fa-pen" aria-hidden="true"></i>
                                        </button>
                                        <button v-if="can('cadastros.edit') && oferta.situacao !== 'encerrada'" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs"
                                            :title="oferta.ativo ? 'Pausar' : 'Retomar'" :aria-label="oferta.ativo ? 'Pausar' : 'Retomar'" @click="alternar(oferta)">
                                            <i class="fas" :class="oferta.ativo ? 'fa-pause text-amber-600' : 'fa-play text-emerald-600'" aria-hidden="true"></i>
                                        </button>
                                        <button v-if="can('cadastros.delete')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs text-red-600" title="Excluir" aria-label="Excluir" @click="excluir(oferta)">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
