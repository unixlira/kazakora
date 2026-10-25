<script setup>
// Regras de frete por planilha (pedido 2026-10-25): o Lira manda um .xlsx ou
// .csv com cidade, distrito, CEP, valor e prazo; a tela mostra o que vai
// acontecer em cada linha e só grava depois do botão. Nada é apagado: o que
// não está na planilha fica como está.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const emit = defineEmits(['fechar', 'importado']);

const arquivo = ref(null);
const campoArquivo = ref(null);
const lendo = ref(false);
const gravando = ref(false);
const erro = ref('');
const previa = ref(null);
const gravadas = ref(null);
const filtro = ref('todas');

const dinheiro = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v);
const cep = (v) => (v ? `${v.slice(0, 5)}-${v.slice(5)}` : '');

const ACOES = {
    criar: { rotulo: 'Nova', classe: 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' },
    atualizar: { rotulo: 'Muda', classe: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' },
    igual: { rotulo: 'Igual', classe: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
    erro: { rotulo: 'Erro', classe: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' },
};

function getCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

async function enviar(url) {
    const corpo = new FormData();
    corpo.append('arquivo', arquivo.value);
    const r = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        body: corpo,
    });
    const dados = await r.json().catch(() => ({}));
    if (r.status === 422) throw new Error(Object.values(dados.errors ?? {}).flat().join(' ') || 'Planilha inválida.');
    if (!r.ok) throw new Error('Não deu certo agora. Tente de novo em instantes.');
    if (dados.erro) throw new Error(dados.erro);

    return dados;
}

async function escolher(e) {
    arquivo.value = e.target.files?.[0] ?? null;
    previa.value = null;
    gravadas.value = null;
    erro.value = '';
    filtro.value = 'todas';
    if (!arquivo.value) return;
    lendo.value = true;
    try {
        previa.value = await enviar('/admin/logistica/fretes-por-estado/importar/previa');
        if (previa.value.resumo.erro) filtro.value = 'erro';
    } catch (err) {
        erro.value = err.message;
    }
    lendo.value = false;
}

const paraGravar = computed(() => (previa.value ? previa.value.resumo.criar + previa.value.resumo.atualizar : 0));

async function gravar() {
    if (!paraGravar.value) return;
    gravando.value = true;
    erro.value = '';
    try {
        const dados = await enviar('/admin/logistica/fretes-por-estado/importar');
        previa.value = dados;
        gravadas.value = dados.gravadas;
        emit('importado');
    } catch (err) {
        erro.value = err.message;
    }
    gravando.value = false;
}

function outra() {
    arquivo.value = null;
    previa.value = null;
    gravadas.value = null;
    if (campoArquivo.value) campoArquivo.value.value = '';
    campoArquivo.value?.click();
}

const itens = computed(() => {
    const lista = previa.value?.itens ?? [];

    return filtro.value === 'todas' ? lista : lista.filter((i) => i.acao === filtro.value);
});

function descrever(r) {
    if (!r || !r.tipo_preco) return '—';
    const v = dinheiro(r.valor);
    const texto = {
        fixo: r.valor === 0 ? 'Frete grátis' : `Fixo ${v}`,
        acrescimo: `Correios + ${v}`,
        desconto: `Correios − ${v}`,
        teto: `Correios até ${v}`,
    }[r.tipo_preco] ?? r.tipo_preco;
    const prazo = r.prazo_dias ? ` · ${r.prazo_dias} ${r.prazo_dias === 1 ? 'dia útil' : 'dias úteis'}` : '';

    return `${texto}${prazo}${r.ativo === false ? ' · desligada' : ''}`;
}

function faixa(i) {
    if (!i.cep_inicio) return '';
    return i.cep_fim && i.cep_fim !== i.cep_inicio ? `${cep(i.cep_inicio)} a ${cep(i.cep_fim)}` : cep(i.cep_inicio);
}

function aoTeclar(e) {
    if (e.key === 'Escape' && !gravando.value) emit('fechar');
}
onMounted(() => window.addEventListener('keydown', aoTeclar));
onBeforeUnmount(() => window.removeEventListener('keydown', aoTeclar));
</script>

<template>
    <Teleport to="body">
        <!-- admin-shell: fora do layout (Teleport) a janela perde --surface e fica transparente. -->
        <div class="admin-shell fixed inset-0 z-[90] flex items-center justify-center bg-black/60 p-4 text-[var(--surface-foreground)]" role="dialog" aria-modal="true" aria-labelledby="importar-titulo" @click.self="!gravando && emit('fechar')">
            <div class="flex max-h-[90vh] w-full max-w-5xl flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface,#ffffff)] shadow-2xl">
                <header class="flex items-start justify-between gap-3 border-b border-[var(--surface-border)] px-5 py-4">
                    <div>
                        <h2 id="importar-titulo" class="text-lg font-bold">
                            <i class="fas fa-file-import mr-2 text-primary" aria-hidden="true"></i>Importar regras de frete
                        </h2>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Uma linha por cidade ou distrito. Primeiro aparece a prévia; nada muda até clicar em Gravar. Regra que não está na planilha fica como está.
                        </p>
                    </div>
                    <button type="button" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Fechar" :disabled="gravando" @click="emit('fechar')">
                        <i class="fas fa-xmark text-lg" aria-hidden="true"></i>
                    </button>
                </header>

                <div class="border-b border-[var(--surface-border)] px-5 py-3 text-sm">
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-emphasis">
                            <i :class="lendo ? 'fas fa-spinner fa-spin' : 'fas fa-upload'" aria-hidden="true"></i>
                            {{ lendo ? 'Lendo a planilha…' : arquivo ? 'Trocar planilha' : 'Escolher planilha (.xlsx ou .csv)' }}
                            <input ref="campoArquivo" type="file" accept=".xlsx,.csv,.txt" class="sr-only" :disabled="lendo || gravando" @change="escolher">
                        </label>
                        <span v-if="arquivo" class="text-slate-600 dark:text-slate-300">{{ arquivo.name }}</span>
                        <a href="/admin/logistica/fretes-por-estado/importar/modelo" class="ml-auto text-xs font-semibold text-primary hover:underline">
                            <i class="fas fa-download mr-1" aria-hidden="true"></i>Baixar planilha modelo
                        </a>
                    </div>
                    <details class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                        <summary class="cursor-pointer font-medium">Colunas da planilha</summary>
                        <ul class="mt-1 list-disc space-y-0.5 pl-5">
                            <li><b>uf</b> e <b>cidade</b> (ou <b>ibge</b>, o código do município). Só com o <b>cep</b> também acha a cidade.</li>
                            <li><b>distrito</b>: vazio = regra da cidade inteira; preenchido (ex.: Serra Pelada) = regra só do distrito, que ganha da cidade.</li>
                            <li><b>cep</b> (ou <b>cep_inicio</b> e <b>cep_fim</b> para faixa): o CEP do distrito. Conferido no ViaCEP; CEP de outra cidade é recusado.</li>
                            <li><b>tipo</b>: gratis, fixo, acrescimo (Correios + valor), desconto (Correios − valor) ou teto (Correios até o valor). Vazio = fixo.</li>
                            <li><b>valor</b> em reais (19,90) e <b>prazo</b> em dias úteis (vazio = o dos Correios). <b>ativo</b> opcional (sim/não).</li>
                        </ul>
                    </details>
                </div>

                <div v-if="erro" role="alert" class="mx-5 mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    {{ erro }}
                </div>

                <template v-if="previa">
                    <div v-if="gravadas !== null" role="status" class="mx-5 mt-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                        <i class="fas fa-circle-check mr-1" aria-hidden="true"></i>
                        {{ gravadas }} {{ gravadas === 1 ? 'regra gravada' : 'regras gravadas' }}. Já valem no preço da loja.
                    </div>
                    <div class="flex flex-wrap items-center gap-2 px-5 py-3 text-xs">
                        <button
                            v-for="f in [['todas', `Todas (${previa.resumo.total})`], ['criar', `Novas (${previa.resumo.criar})`], ['atualizar', `Mudam (${previa.resumo.atualizar})`], ['igual', `Iguais (${previa.resumo.igual})`], ['erro', `Com erro (${previa.resumo.erro})`]]"
                            :key="f[0]"
                            type="button"
                            class="rounded-full border px-3 py-1 font-semibold"
                            :class="filtro === f[0] ? 'border-primary bg-lightprimary text-primary' : 'border-[var(--surface-border)] text-slate-600 dark:text-slate-300'"
                            :aria-pressed="filtro === f[0]"
                            @click="filtro = f[0]"
                        >{{ f[1] }}</button>
                    </div>
                    <div class="min-h-0 flex-1 overflow-auto border-t border-[var(--surface-border)]">
                        <table class="w-full text-left text-sm">
                            <thead class="sticky top-0 bg-[var(--surface,#ffffff)] text-xs uppercase text-slate-500 dark:text-slate-400">
                                <tr>
                                    <th class="px-3 py-2">Linha</th>
                                    <th class="px-3 py-2">Lugar</th>
                                    <th class="px-3 py-2">CEP</th>
                                    <th class="px-3 py-2">Regra</th>
                                    <th class="px-3 py-2">O que acontece</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in itens" :key="i.linha" class="border-t border-[var(--surface-border)] align-top">
                                    <td class="px-3 py-2 tabular-nums text-slate-500">{{ i.linha }}</td>
                                    <td class="px-3 py-2">
                                        <span class="font-medium">{{ i.distrito || i.cidade || '—' }}</span>
                                        <span v-if="i.distrito" class="block text-xs text-slate-500 dark:text-slate-400">distrito de {{ i.cidade || '?' }}{{ i.uf ? ` - ${i.uf}` : '' }}</span>
                                        <span v-else-if="i.uf" class="block text-xs text-slate-500 dark:text-slate-400">cidade · {{ i.uf }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ faixa(i) || '—' }}</td>
                                    <td class="px-3 py-2">
                                        {{ descrever(i) }}
                                        <span v-if="i.acao === 'atualizar'" class="block text-xs text-slate-500 dark:text-slate-400">antes: {{ descrever(i.antes) }}</span>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="ACOES[i.acao]?.classe">{{ ACOES[i.acao]?.rotulo ?? i.acao }}</span>
                                        <span v-for="(e, k) in i.erros" :key="`e${k}`" class="mt-1 block text-xs text-red-700 dark:text-red-300">{{ e }}</span>
                                        <span v-for="(a, k) in i.avisos" :key="`a${k}`" class="mt-1 block text-xs text-amber-700 dark:text-amber-300">{{ a }}</span>
                                    </td>
                                </tr>
                                <tr v-if="!itens.length">
                                    <td colspan="5" class="px-3 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Nenhuma linha neste filtro.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--surface-border)] px-5 py-3 text-sm">
                        <span class="text-slate-500 dark:text-slate-400">
                            <template v-if="gravadas === null">
                                {{ paraGravar }} {{ paraGravar === 1 ? 'regra vai ser gravada' : 'regras vão ser gravadas' }}<template v-if="previa.resumo.erro">; {{ previa.resumo.erro }} com erro {{ previa.resumo.erro === 1 ? 'fica' : 'ficam' }} de fora</template>.
                            </template>
                        </span>
                        <span class="flex gap-2">
                            <button v-if="gravadas !== null" type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 font-medium" @click="outra">Importar outra</button>
                            <button
                                v-else
                                type="button"
                                :disabled="gravando || !paraGravar"
                                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                                @click="gravar"
                            >
                                <i :class="gravando ? 'fas fa-spinner fa-spin' : 'fas fa-floppy-disk'" aria-hidden="true"></i>
                                {{ gravando ? 'Gravando…' : `Gravar ${paraGravar} ${paraGravar === 1 ? 'regra' : 'regras'}` }}
                            </button>
                        </span>
                    </footer>
                </template>
            </div>
        </div>
    </Teleport>
</template>
