<script setup>
import { computed, ref } from 'vue';
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';

// Estratégia Shopee, botão Ação (tarefa 569, 2026-10-14). Vai em cada linha
// da lista de anúncios com prejuízo: abre o painel com o que o Cauê vai
// fazer (antes → depois), o Lira marca os passos e clica Aplicar (é o OK
// dele). Cada passo volta com ✅ conferido na Shopee, ❌ erro ou "simulado"
// (escrita desligada). Desfazer por 7 dias no histórico.
const props = defineProps({
    // { item_id, nome, imagem?, gasto?, roas_real?, roas_empate?, conversao?, decisao? }
    // decisao: texto ou lista com PAUSAR / META / PRECO / CAPA (régua do Cauê).
    produto: { type: Object, required: true },
});

const emit = defineEmits(['aplicado']);

const BASE = '/admin/publicidade/shopee/acoes';

const aberto = ref(false);
const carregando = ref(false);
const enviando = ref(false);
const erro = ref('');
const dados = ref(null);
const passos = ref([]);
const resultados = ref([]);
const forcar = ref(false);

const decisoes = computed(() => {
    const d = props.produto.decisao;
    return (Array.isArray(d) ? d : String(d ?? '').split(/[\s,+]+/)).map((x) => x.toUpperCase()).filter(Boolean);
});
const decide = (nome) => decisoes.value.some((d) => d.startsWith(nome));

const num = (v, casas = 1) => (v === null || v === undefined || v === '' ? '-' : Number(v).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas }));
const reais = (v) => (v === null || v === undefined ? '-' : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

const STATUS = { ongoing: 'Ativa', paused: 'Pausada', scheduled: 'Agendada', ended: 'Encerrada' };

function xsrf() {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function chamar(url, corpo = null) {
    const r = await fetch(url, {
        method: corpo ? 'POST' : 'GET',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: corpo ? JSON.stringify(corpo) : undefined,
    });
    const json = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(json.message ?? `Erro ${r.status}`);
    return json;
}

// Monta os passos de cada campanha; vêm marcados os que a decisão do Cauê pede.
function montarPassos() {
    const sugerida = dados.value.meta_sugerida;
    passos.value = dados.value.campanhas.flatMap((c) => {
        const lista = [];
        if (c.status === 'ongoing') {
            lista.push({ chave: `${c.campaign_id}-pausar`, campanha: c, acao: 'pausar', marcado: decide('PAUSAR'), texto: 'Pausar a campanha' });
        }
        if (c.status === 'paused') {
            lista.push({ chave: `${c.campaign_id}-retomar`, campanha: c, acao: 'retomar', marcado: false, texto: 'Retomar a campanha' });
        }
        if (c.pode_meta) {
            lista.push({
                chave: `${c.campaign_id}-meta`, campanha: c, acao: 'meta_roas',
                marcado: !decide('PAUSAR') && (decide('META') || decide('PRECO')) && !c.meta_travada_ate && sugerida !== null && (c.meta ?? 0) < sugerida,
                meta: sugerida ?? c.meta ?? '', texto: 'Mudar a meta de ROAS',
            });
        }
        return lista;
    });
}

async function abrir() {
    aberto.value = true;
    carregando.value = true;
    erro.value = '';
    resultados.value = [];
    forcar.value = false;
    try {
        const q = props.produto.roas_empate ? `?empate=${props.produto.roas_empate}` : '';
        dados.value = await chamar(`${BASE}/item/${props.produto.item_id}${q}`);
        montarPassos();
    } catch (e) {
        erro.value = e.message;
    } finally {
        carregando.value = false;
    }
}

const marcados = computed(() => passos.value.filter((p) => p.marcado));
const metaAbaixoDoEmpate = (p) => props.produto.roas_empate && Number(p.meta) < Number(props.produto.roas_empate);
const podeAplicar = computed(() => marcados.value.length > 0 && !marcados.value.some((p) => p.acao === 'meta_roas' && (!p.meta || metaAbaixoDoEmpate(p))));

async function aplicar() {
    enviando.value = true;
    resultados.value = [];
    for (const p of marcados.value) {
        try {
            const r = await chamar(BASE, {
                item_id: String(props.produto.item_id),
                campaign_id: p.campanha.campaign_id,
                acao: p.acao,
                meta: p.acao === 'meta_roas' ? Number(p.meta) : null,
                roas_empate: props.produto.roas_empate ?? null,
                forcar: forcar.value,
                nome_produto: props.produto.nome,
            });
            dados.value.historico = r.historico;
            resultados.value.push({ texto: `${p.texto} (${p.campanha.campaign_id})`, status: r.acao.status, erro: r.acao.erro });
        } catch (e) {
            resultados.value.push({ texto: `${p.texto} (${p.campanha.campaign_id})`, status: 'erro', erro: e.message });
        }
    }
    enviando.value = false;
    emit('aplicado', props.produto.item_id);
    // Relê as campanhas para o painel mostrar o estado novo.
    try {
        const q = props.produto.roas_empate ? `?empate=${props.produto.roas_empate}` : '';
        const historico = dados.value.historico;
        dados.value = await chamar(`${BASE}/item/${props.produto.item_id}${q}`);
        dados.value.historico ??= historico;
        montarPassos();
        passos.value.forEach((p) => (p.marcado = false));
    } catch {
        // mantém o que já está na tela
    }
}

async function acaoHistorico(linha, tipo) {
    if (tipo === 'desfazer' && !window.confirm(`Desfazer "${linha.descricao}" na Shopee?`)) return;
    try {
        const r = await chamar(`${BASE}/${linha.id}/${tipo}`, {});
        dados.value.historico = r.historico;
        resultados.value.push({ texto: r.acao.descricao, status: r.acao.status, erro: r.acao.erro });
    } catch (e) {
        resultados.value.push({ texto: linha.descricao, status: 'erro', erro: e.message });
    }
}

const SELO = {
    conferida: { icone: '✅', texto: 'conferido na Shopee', classe: 'text-success' },
    nao_conferida: { icone: '⚠️', texto: 'mandado, a Shopee ainda não mostra', classe: 'text-warning' },
    simulada: { icone: '🧪', texto: 'simulado (escrita desligada)', classe: 'text-slate-500' },
    erro: { icone: '❌', texto: 'erro', classe: 'text-error' },
};
</script>

<template>
    <button type="button"
        class="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-emphasis"
        @click="abrir">
        <i class="fas fa-bolt mr-1"></i>Ação
    </button>

    <ConfirmModal :open="aberto" :title="`Cauê: ${produto.nome}`" confirm-label="Aplicar" cancel-label="Fechar"
        :loading="enviando" :confirm-disabled="carregando || !podeAplicar" @close="aberto = false" @confirm="aplicar">
        <div class="space-y-4 text-sm">
            <div class="flex items-center gap-3">
                <img v-if="produto.imagem" :src="produto.imagem" alt="" class="h-14 w-14 rounded-lg object-cover">
                <div class="grid flex-1 grid-cols-2 gap-x-4 gap-y-0.5 text-xs">
                    <span>Gasto: <b>{{ reais(produto.gasto) }}</b></span>
                    <span>Conversão: <b>{{ num(produto.conversao, 2) }}%</b></span>
                    <span>ROAS real: <b :class="produto.roas_real < produto.roas_empate ? 'text-error' : ''">{{ num(produto.roas_real) }}</b></span>
                    <span>Empate: <b>{{ num(produto.roas_empate) }}</b></span>
                    <span v-if="decisoes.length" class="col-span-2">Decisão do Cauê: <b>{{ decisoes.join(' + ') }}</b></span>
                </div>
            </div>

            <div v-if="dados && !dados.escrita_ligada" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] p-2 text-xs">
                🧪 Modo simulação: nada vai para a Shopee. O Cauê grava o que mandaria. Para valer de verdade, a escrita precisa ser ligada no servidor (com OK do Lira).
            </div>

            <p v-if="carregando" class="text-slate-500"><i class="fas fa-spinner fa-spin mr-1"></i>Lendo as campanhas na Shopee...</p>
            <p v-if="erro" class="text-error">{{ erro }}</p>

            <template v-if="dados && !carregando">
                <p v-if="!dados.campanhas.length" class="text-slate-500">Nenhuma campanha de ADS por produto anuncia este item na Shopee.</p>

                <div v-for="c in dados.campanhas" :key="c.campaign_id" class="rounded-lg border border-[var(--surface-border)] p-3">
                    <div class="mb-2 flex flex-wrap justify-between gap-2 text-xs">
                        <span class="font-semibold">Campanha {{ c.campaign_id }}</span>
                        <span>{{ STATUS[c.status] ?? c.status }} · lance {{ c.lance === 'auto' ? 'automático' : 'manual' }} · meta hoje {{ c.meta ? num(c.meta) : 'nenhuma' }}</span>
                    </div>
                    <label v-for="p in passos.filter((x) => x.campanha.campaign_id === c.campaign_id)" :key="p.chave"
                        class="flex items-center gap-2 py-1">
                        <input v-model="p.marcado" type="checkbox" class="rounded">
                        <span>{{ p.texto }}</span>
                        <template v-if="p.acao === 'meta_roas'">
                            <span class="text-xs text-slate-500">{{ c.meta ? num(c.meta) : 'nenhuma' }} →</span>
                            <input v-model="p.meta" type="number" step="0.1" min="0.1" max="100"
                                class="w-20 rounded border border-[var(--surface-border)] bg-transparent px-2 py-0.5 text-sm">
                            <span v-if="dados.meta_sugerida" class="text-xs text-slate-500">(empate + 2 = {{ num(dados.meta_sugerida) }})</span>
                        </template>
                    </label>
                    <p v-if="passos.some((x) => x.campanha.campaign_id === c.campaign_id && x.acao === 'meta_roas' && metaAbaixoDoEmpate(x))" class="text-xs text-error">
                        Meta abaixo do empate: a campanha daria prejuízo.
                    </p>
                    <p v-if="c.meta_travada_ate" class="text-xs text-warning">
                        Meta mudada há menos de 7 dias (aprendizado até {{ c.meta_travada_ate }}). Só com "forçar".
                    </p>
                    <p v-if="!c.pode_meta" class="text-xs text-slate-500">Sem meta de ROAS por aqui (lance manual ou campanha automática da loja).</p>
                </div>

                <p v-if="marcados.some((p) => p.acao === 'meta_roas')" class="text-xs text-slate-500">
                    Mudar a meta reinicia o aprendizado da Shopee (cerca de 14 dias).
                </p>
                <label v-if="dados.campanhas.some((c) => c.meta_travada_ate)" class="flex items-center gap-2 text-xs">
                    <input v-model="forcar" type="checkbox" class="rounded"> Forçar troca de meta mesmo com menos de 7 dias
                </label>

                <ul v-if="resultados.length" class="space-y-1 rounded-lg bg-[var(--surface-muted)] p-2 text-xs">
                    <li v-for="(r, i) in resultados" :key="i" :class="SELO[r.status]?.classe">
                        {{ SELO[r.status]?.icone }} {{ r.texto }}: {{ SELO[r.status]?.texto ?? r.status }}<span v-if="r.erro"> ({{ r.erro }})</span>
                    </li>
                </ul>

                <div v-if="dados.historico?.length">
                    <p class="mb-1 text-xs font-semibold uppercase text-slate-500">Histórico</p>
                    <ul class="space-y-1 text-xs">
                        <li v-for="h in dados.historico" :key="h.id" class="flex flex-wrap items-center gap-2">
                            <span>{{ SELO[h.status]?.icone }}</span>
                            <span>{{ h.quando }} · {{ h.descricao }}<span v-if="h.desfaz"> (desfazer)</span> · {{ h.quem ?? 'sistema' }}</span>
                            <span v-if="h.desfeita" class="text-slate-500">desfeita</span>
                            <button v-if="h.status === 'nao_conferida'" type="button" class="text-primary underline" @click="acaoHistorico(h, 'conferir')">Conferir de novo</button>
                            <button v-if="h.pode_desfazer" type="button" class="text-primary underline" @click="acaoHistorico(h, 'desfazer')">Desfazer</button>
                        </li>
                    </ul>
                </div>
            </template>
        </div>
    </ConfirmModal>
</template>
