<script setup>
// Frete por distrito (pedido 2026-10-25): dentro da cidade, cada distrito
// (lista do IBGE + CEP descoberto no ViaCEP) pode ter regra própria, com CEP
// ou faixa de CEP. A regra do distrito ganha da regra da cidade; distrito sem
// regra segue a da cidade (ou os Correios).
import { onMounted, reactive, ref } from 'vue';
import { TIPOS, camposDaRegra, corpoDaRegra, descrever, formatarCep, pedir } from './regraDeFrete';

const props = defineProps({
    cidade: { type: Object, required: true },
});
const emit = defineEmits(['contagem']);

const carregando = ref(true);
const erro = ref('');
const cepGeral = ref(null);
const regraCidade = ref(null);
const distritos = ref([]);
const editando = ref(null);
const salvando = ref(false);
const erroForm = ref('');
const form = reactive({ tipo: 'gratis', valor: 0, prazo_dias: '', nome: '', cep_inicio: '', cep_fim: '' });

const chave = (d) => d.id ?? `nome:${d.nome}`;
const NOVO = '__novo__';

async function carregar() {
    carregando.value = true;
    erro.value = '';
    try {
        const dados = await pedir(`/admin/logistica/fretes-por-estado/cidades/${props.cidade.id}/distritos`);
        cepGeral.value = dados.cep_geral;
        regraCidade.value = dados.cidade.regra;
        distritos.value = dados.distritos;
    } catch (e) {
        erro.value = e.message;
    }
    carregando.value = false;
}

const contar = () => emit('contagem', distritos.value.filter((d) => d.regra).length);

function editar(d) {
    Object.assign(form, camposDaRegra(d?.regra), {
        nome: d?.nome ?? '',
        cep_inicio: formatarCep(d?.regra?.cep_inicio ?? d?.cep_sugerido ?? ''),
        cep_fim: formatarCep(d?.regra?.cep_fim ?? ''),
    });
    erroForm.value = '';
    editando.value = d ? chave(d) : NOVO;
}

async function salvar(d) {
    salvando.value = true;
    erroForm.value = '';
    try {
        const dados = await pedir(`/admin/logistica/fretes-por-estado/cidades/${props.cidade.id}/distritos`, 'PUT', {
            ...corpoDaRegra(form),
            distrito: d ? d.nome : form.nome.trim(),
            distrito_id: d?.id ?? null,
            cep_inicio: form.cep_inicio || null,
            cep_fim: form.cep_fim || null,
        });
        if (d) {
            d.regra = dados.regra;
        } else {
            distritos.value.push({ id: null, nome: form.nome.trim(), cep_sugerido: null, regra: dados.regra });
        }
        editando.value = null;
        contar();
    } catch (e) {
        erroForm.value = e.message;
    }
    salvando.value = false;
}

async function remover(d) {
    if (!window.confirm(`Tirar a regra de ${d.nome}? O distrito volta para o frete da cidade.`)) return;
    salvando.value = true;
    erroForm.value = '';
    try {
        await pedir(`/admin/logistica/fretes-por-estado/cidades/${props.cidade.id}/distritos/${d.regra.id}`, 'DELETE');
        d.regra = null;
        editando.value = null;
        contar();
    } catch (e) {
        erroForm.value = e.message;
    }
    salvando.value = false;
}

function cepDoDistrito(d) {
    if (d.regra?.cep_inicio) {
        return d.regra.cep_fim ? `${formatarCep(d.regra.cep_inicio)} a ${formatarCep(d.regra.cep_fim)}` : formatarCep(d.regra.cep_inicio);
    }

    return d.cep_sugerido ? formatarCep(d.cep_sugerido) : 'CEP não encontrado';
}

onMounted(carregar);
</script>

<template>
    <div class="mx-5 mb-3 rounded-xl border border-[var(--surface-border)] bg-slate-50 p-3 text-sm dark:bg-slate-900/40">
        <div v-if="carregando" class="py-3 text-center text-slate-500 dark:text-slate-400">
            <i class="fas fa-spinner fa-spin mr-2" aria-hidden="true"></i>Procurando os distritos e os CEPs…
        </div>
        <div v-else-if="erro" role="alert" class="text-red-700 dark:text-red-300">{{ erro }}</div>
        <template v-else>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                <template v-if="cepGeral">CEP geral de {{ cidade.nome }}: <span class="font-semibold tabular-nums">{{ formatarCep(cepGeral) }}</span> ({{ descrever(regraCidade, 'Correios normal') }}). </template>
                Distrito com regra própria ganha da regra da cidade. Sem regra, segue a da cidade.
            </p>

            <ul class="mt-2">
                <li v-for="d in distritos" :key="chave(d)" class="border-t border-[var(--surface-border)] first:border-t-0">
                    <div class="flex flex-wrap items-center justify-between gap-2 py-1.5">
                        <span>
                            <span class="font-medium">{{ d.nome }}</span>
                            <span class="ml-2 text-xs tabular-nums" :class="d.regra?.cep_inicio || d.cep_sugerido ? 'text-slate-500 dark:text-slate-400' : 'text-amber-700 dark:text-amber-300'">{{ cepDoDistrito(d) }}</span>
                        </span>
                        <span class="flex items-center gap-3">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                :class="d.regra ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' : 'text-slate-500 dark:text-slate-400'"
                            >{{ descrever(d.regra, 'Igual à cidade') }}</span>
                            <button
                                v-if="editando !== chave(d)"
                                type="button"
                                class="whitespace-nowrap text-xs font-semibold text-primary hover:underline"
                                @click="editar(d)"
                            >{{ d.regra ? 'editar' : 'criar regra' }}</button>
                        </span>
                    </div>
                    <form v-if="editando === chave(d)" class="mb-2 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3" @submit.prevent="salvar(d)">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                            <label class="block">
                                <span class="font-medium">Regra</span>
                                <select v-model="form.tipo" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                                    <option v-for="t in TIPOS" :key="t.chave" :value="t.chave">{{ t.rotulo }}</option>
                                </select>
                            </label>
                            <label class="block" :class="form.tipo === 'gratis' ? 'opacity-40' : ''">
                                <span class="font-medium">Valor (R$)</span>
                                <input v-model.number="form.valor" type="number" min="0" max="1000" step="0.01" :disabled="form.tipo === 'gratis'" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                            </label>
                            <label class="block">
                                <span class="font-medium">Prazo (dias úteis)</span>
                                <input v-model="form.prazo_dias" type="number" min="1" max="60" placeholder="o dos Correios" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                            </label>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="font-medium">CEP do distrito</span>
                                <input v-model="form.cep_inicio" inputmode="numeric" maxlength="9" placeholder="00000-000" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                            </label>
                            <label class="block">
                                <span class="font-medium">Até o CEP <span class="font-normal text-slate-500 dark:text-slate-400">(só se for faixa)</span></span>
                                <input v-model="form.cep_fim" inputmode="numeric" maxlength="9" placeholder="opcional" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                            </label>
                        </div>
                        <p v-if="erroForm" role="alert" class="mt-2 text-red-700 dark:text-red-300">{{ erroForm }}</p>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <button type="submit" :disabled="salvando" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                                <i :class="salvando ? 'fas fa-spinner fa-spin' : 'fas fa-check'" aria-hidden="true"></i>Salvar
                            </button>
                            <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="editando = null">Cancelar</button>
                            <button v-if="d.regra" type="button" :disabled="salvando" class="ml-auto rounded-lg px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50 dark:text-red-300 dark:hover:bg-red-950" @click="remover(d)">Tirar regra</button>
                        </div>
                    </form>
                </li>
                <li v-if="!distritos.length" class="py-2 text-slate-500 dark:text-slate-400">O IBGE não lista distritos para {{ cidade.nome }}. Dá para cadastrar um pelo nome e CEP abaixo.</li>
            </ul>

            <button v-if="editando !== NOVO" type="button" class="mt-2 text-xs font-semibold text-primary hover:underline" @click="editar(null)">
                <i class="fas fa-plus mr-1" aria-hidden="true"></i>Outro distrito ou povoado
            </button>
            <form v-else class="mt-2 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3" @submit.prevent="salvar(null)">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label class="block">
                        <span class="font-medium">Nome do distrito</span>
                        <input v-model="form.nome" required maxlength="120" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                    </label>
                    <label class="block">
                        <span class="font-medium">CEP</span>
                        <input v-model="form.cep_inicio" inputmode="numeric" maxlength="9" placeholder="00000-000" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                    </label>
                    <label class="block">
                        <span class="font-medium">Até o CEP <span class="font-normal text-slate-500 dark:text-slate-400">(faixa)</span></span>
                        <input v-model="form.cep_fim" inputmode="numeric" maxlength="9" placeholder="opcional" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                    </label>
                </div>
                <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)] sm:items-end">
                    <label class="block">
                        <span class="font-medium">Regra</span>
                        <select v-model="form.tipo" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
                            <option v-for="t in TIPOS" :key="t.chave" :value="t.chave">{{ t.rotulo }}</option>
                        </select>
                    </label>
                    <label class="block" :class="form.tipo === 'gratis' ? 'opacity-40' : ''">
                        <span class="font-medium">Valor (R$)</span>
                        <input v-model.number="form.valor" type="number" min="0" max="1000" step="0.01" :disabled="form.tipo === 'gratis'" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                    </label>
                    <label class="block">
                        <span class="font-medium">Prazo (dias úteis)</span>
                        <input v-model="form.prazo_dias" type="number" min="1" max="60" placeholder="o dos Correios" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm tabular-nums">
                    </label>
                </div>
                <p v-if="erroForm" role="alert" class="mt-2 text-red-700 dark:text-red-300">{{ erroForm }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="submit" :disabled="salvando" class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        <i :class="salvando ? 'fas fa-spinner fa-spin' : 'fas fa-check'" aria-hidden="true"></i>Salvar
                    </button>
                    <button type="button" class="rounded-lg px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800" @click="editando = null">Cancelar</button>
                </div>
            </form>
        </template>
    </div>
</template>
