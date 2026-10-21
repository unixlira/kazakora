<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

// Validação de criativos (2026-10-21): a equipe sobe os vídeos de anúncio
// e o Lira assiste aqui mesmo e aprova, pede ajuste ou reprova. O vídeo vai
// em partes (tamanhoParte) porque o servidor recusa requisição acima de 64 MB.
const props = defineProps({
    criativos: { type: Object, default: () => ({ data: [], links: [] }) },
    totais: { type: Object, default: () => ({}) },
    filtros: { type: Object, default: () => ({}) },
    plataformas: { type: Object, default: () => ({}) },
    produtos: { type: Array, default: () => [] },
    podeAvaliar: { type: Boolean, default: false },
    extensoes: { type: Array, default: () => ['mp4', 'mov', 'm4v', 'webm'] },
    maxMb: { type: Number, default: 1024 },
    tamanhoParte: { type: Number, default: 8 * 1024 * 1024 },
});

const BASE = '/admin/validacao-criativos';
const STATUS = {
    aguardando: ['Aguardando', 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200', 'fa-hourglass-half'],
    aprovado: ['Aprovado', 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300', 'fa-circle-check'],
    ajustar: ['Ajustar', 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200', 'fa-pen-to-square'],
    reprovado: ['Reprovado', 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300', 'fa-circle-xmark'],
};
const ABAS = [
    { chave: 'aguardando', nome: 'Aguardando' },
    { chave: 'aprovado', nome: 'Aprovados' },
    { chave: 'ajustar', nome: 'Ajustar' },
    { chave: 'reprovado', nome: 'Reprovados' },
    { chave: '', nome: 'Todos' },
];

const filtros = reactive({
    status: props.filtros.status ?? '',
    plataforma: props.filtros.plataforma ?? '',
    busca: props.filtros.busca ?? '',
    lixeira: !!props.filtros.lixeira,
});

const buscar = () => {
    router.get(BASE, {
        status: filtros.status || undefined,
        plataforma: filtros.plataforma || undefined,
        busca: filtros.busca || undefined,
        lixeira: filtros.lixeira ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};
const escolherAba = (status, lixeira = false) => {
    filtros.status = lixeira ? '' : status;
    filtros.lixeira = lixeira;
    buscar();
};
const totalGeral = computed(() => ['aguardando', 'aprovado', 'ajustar', 'reprovado'].reduce((s, k) => s + (props.totais[k] ?? 0), 0));
const contagem = (chave) => (chave ? props.totais[chave] ?? 0 : totalGeral.value);

// Envio ----------------------------------------------------------------------
const envioAberto = ref(props.criativos.data.length === 0 && !props.filtros.lixeira);
const novo = reactive({ plataforma: 'meta', produto_id: '', observacao: '' });
const fila = ref([]); // { arquivo, titulo, progresso, estado: 'esperando'|'enviando'|'pronto'|'erro', erro }
const enviando = ref(false);
const arrastando = ref(false);

const tituloDoArquivo = (nome) => nome.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' ').trim().slice(0, 150);
const extensao = (nome) => (nome.split('.').pop() || '').toLowerCase();

const adicionarArquivos = (lista) => {
    Array.from(lista ?? []).forEach((arquivo) => {
        let erro = null;
        if (!props.extensoes.includes(extensao(arquivo.name))) erro = 'Use vídeo MP4, MOV, M4V ou WebM.';
        else if (arquivo.size > props.maxMb * 1048576) erro = `Passa de ${props.maxMb} MB. Comprima antes de enviar.`;
        fila.value.push({ arquivo, titulo: tituloDoArquivo(arquivo.name), progresso: 0, estado: erro ? 'erro' : 'esperando', erro });
    });
};
const aoSoltar = (e) => {
    arrastando.value = false;
    adicionarArquivos(e.dataTransfer?.files);
};
const tirarDaFila = (i) => fila.value.splice(i, 1);

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');
const requisicao = (url, dados, aoProgredir) => new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-XSRF-TOKEN', xsrf());
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    if (aoProgredir) xhr.upload.onprogress = (e) => e.lengthComputable && aoProgredir(e.loaded);
    xhr.onload = () => {
        let corpo = null;
        try { corpo = JSON.parse(xhr.responseText); } catch { /* sem JSON (ex.: 413 do nginx) */ }
        resolve({ status: xhr.status, corpo });
    };
    xhr.onerror = () => resolve({ status: 0, corpo: null });
    xhr.send(dados);
});
const mensagemDeErro = ({ status, corpo }) => (corpo?.errors ? Object.values(corpo.errors)[0]?.[0] : corpo?.message)
    || (status === 0 ? 'A conexão caiu durante o envio.' : status === 413 ? 'Parte grande demais para o servidor.' : `Erro ${status} no servidor.`);

const enviarUm = async (item) => {
    const { arquivo } = item;
    const envio = crypto.randomUUID();
    let inicio = 0;
    let falhas = 0;
    item.estado = 'enviando';

    while (inicio < arquivo.size) {
        const parte = arquivo.slice(inicio, inicio + props.tamanhoParte);
        const dados = new FormData();
        dados.append('envio', envio);
        dados.append('inicio', String(inicio));
        dados.append('parte', parte, 'parte.bin');
        const base = inicio;
        const resposta = await requisicao(`${BASE}/parte`, dados, (enviado) => {
            item.progresso = Math.min(99, Math.round(((base + enviado) / arquivo.size) * 100));
        });

        if (resposta.status === 200) {
            inicio = resposta.corpo.recebido;
            falhas = 0;
        } else if (resposta.status === 409 && Number.isInteger(resposta.corpo?.recebido)) {
            inicio = resposta.corpo.recebido; // o servidor diz onde parou: continua dali
        } else if ((resposta.status === 0 || resposta.status >= 500) && falhas < 3) {
            falhas += 1;
            await new Promise((r) => setTimeout(r, 2000 * falhas));
        } else {
            throw new Error(mensagemDeErro(resposta));
        }
    }

    const fim = new FormData();
    Object.entries({
        envio,
        nome_original: arquivo.name,
        tamanho: arquivo.size,
        titulo: item.titulo || tituloDoArquivo(arquivo.name) || 'Criativo',
        plataforma: novo.plataforma,
        produto_id: novo.produto_id,
        observacao: novo.observacao,
    }).forEach(([chave, valor]) => {
        if (valor !== '' && valor !== null && valor !== undefined) fim.append(chave, valor);
    });
    const resposta = await requisicao(BASE, fim);
    if (resposta.status !== 201) throw new Error(mensagemDeErro(resposta));
    item.progresso = 100;
    item.estado = 'pronto';
};

const enviarFila = async () => {
    enviando.value = true;
    for (const item of fila.value.filter((i) => i.estado === 'esperando')) {
        try {
            await enviarUm(item);
        } catch (e) {
            item.estado = 'erro';
            item.erro = e.message;
        }
    }
    enviando.value = false;
    fila.value = fila.value.filter((i) => i.estado !== 'pronto');
    if (fila.value.length === 0) {
        novo.observacao = '';
        envioAberto.value = false;
    }
    router.reload({ only: ['criativos', 'totais'], preserveScroll: true });
};
const temParaEnviar = computed(() => fila.value.some((i) => i.estado === 'esperando'));

// Avaliar, editar, lixeira ---------------------------------------------------
const avaliando = ref(null); // { id, status }
const comentario = ref('');
const errosAvaliacao = ref({});
const pedirComentario = (criativo, status) => {
    avaliando.value = { id: criativo.id, status };
    comentario.value = criativo.comentario_avaliacao ?? '';
    errosAvaliacao.value = {};
};
const avaliar = (criativo, status, texto = null) => {
    router.patch(`${BASE}/${criativo.id}/avaliar`, { status, comentario: texto }, {
        preserveScroll: true,
        onSuccess: () => { avaliando.value = null; },
        onError: (erros) => { errosAvaliacao.value = erros; },
    });
};

const editando = ref(null);
const edicao = useForm({ titulo: '', plataforma: 'meta', produto_id: '', observacao: '' });
const editar = (criativo) => {
    editando.value = criativo.id;
    edicao.titulo = criativo.titulo;
    edicao.plataforma = criativo.plataforma;
    edicao.produto_id = criativo.produto?.id ?? '';
    edicao.observacao = criativo.observacao ?? '';
    edicao.clearErrors();
};
const salvarEdicao = (criativo) => {
    edicao.transform((d) => ({ ...d, produto_id: d.produto_id || null }))
        .patch(`${BASE}/${criativo.id}`, { preserveScroll: true, onSuccess: () => { editando.value = null; } });
};

const paraLixeira = (criativo) => {
    if (!window.confirm(`Mover "${criativo.titulo}" para a lixeira? O vídeo fica guardado e dá para restaurar.`)) return;
    router.delete(`${BASE}/${criativo.id}`, { preserveScroll: true });
};
const restaurar = (criativo) => router.patch(`${BASE}/${criativo.id}/restaurar`, {}, { preserveScroll: true });

const dataHora = (valor) => (valor ? new Date(valor).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }) : '-');
const duracao = (s) => (s ? `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` : null);
const mb = (bytes) => `${(bytes / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm';
const rotulo = 'block text-xs font-medium text-slate-500 dark:text-slate-400';
</script>

<template>
    <Head title="Validação de criativos" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
                <h1 class="mb-1 text-2xl font-bold">Validação de criativos</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Vídeos de anúncio para Meta, TikTok, Google e outras plataformas, guardados no servidor até a validação.
                    <template v-if="props.podeAvaliar">Assista e aprove, peça ajuste ou reprove.</template>
                    <template v-else>Só o administrador aprova; o comentário dele aparece no card.</template>
                    Horário de Brasília.
                </p>
            </div>
            <button type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis" :aria-expanded="envioAberto" @click="envioAberto = !envioAberto">
                <i :class="['fas mr-1', envioAberto ? 'fa-xmark' : 'fa-cloud-arrow-up']" aria-hidden="true"></i>{{ envioAberto ? 'Fechar envio' : 'Enviar criativos' }}
            </button>
        </div>

        <section v-if="envioAberto" class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-base font-semibold">Enviar criativos</h2>

            <label
                for="vc-arquivos"
                :class="['flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed px-4 py-8 text-center text-sm transition',
                    arrastando ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)] hover:border-primary/60']"
                @dragover.prevent="arrastando = true"
                @dragleave.prevent="arrastando = false"
                @drop.prevent="aoSoltar"
            >
                <i class="fas fa-film mb-1 text-2xl text-primary" aria-hidden="true"></i>
                <span class="font-medium">Arraste os vídeos aqui ou clique para escolher</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">MP4, MOV, M4V ou WebM, até {{ props.maxMb }} MB cada. Pode escolher vários.</span>
            </label>
            <input id="vc-arquivos" type="file" multiple :accept="props.extensoes.map((e) => '.' + e).join(',') + ',video/*'" class="sr-only"
                @change="adicionarArquivos($event.target.files); $event.target.value = ''">

            <div v-if="fila.length" class="mt-4 space-y-2">
                <div v-for="(item, i) in fila" :key="i" class="rounded-lg border border-[var(--surface-border)] p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <label :for="`vc-titulo-${i}`" class="sr-only">Nome do criativo</label>
                        <input :id="`vc-titulo-${i}`" v-model="item.titulo" type="text" maxlength="150" :disabled="item.estado !== 'esperando'"
                            class="min-w-0 flex-1 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
                        <span class="text-xs text-slate-500">{{ item.arquivo.name }} · {{ mb(item.arquivo.size) }}</span>
                        <button v-if="item.estado !== 'enviando'" type="button" class="text-slate-400 hover:text-red-600" :aria-label="`Tirar ${item.arquivo.name} da lista`" @click="tirarDaFila(i)">
                            <i class="fas fa-xmark" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div v-if="item.estado === 'enviando' || item.estado === 'pronto'" class="mt-2 h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
                        role="progressbar" :aria-valuenow="item.progresso" aria-valuemin="0" aria-valuemax="100" :aria-label="`Envio de ${item.titulo}`">
                        <div class="h-full bg-primary transition-all" :style="{ width: item.progresso + '%' }"></div>
                    </div>
                    <p v-if="item.estado === 'enviando'" class="mt-1 text-xs text-slate-500">Enviando... {{ item.progresso }}%</p>
                    <p v-if="item.erro" class="mt-1 text-xs text-red-600" role="alert">{{ item.erro }}</p>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="vc-plataforma" :class="rotulo">Plataforma</label>
                    <select id="vc-plataforma" v-model="novo.plataforma" :class="campo">
                        <option v-for="(nome, chave) in props.plataformas" :key="chave" :value="chave">{{ nome }}</option>
                    </select>
                </div>
                <div>
                    <label for="vc-produto" :class="rotulo">Produto (opcional)</label>
                    <select id="vc-produto" v-model="novo.produto_id" :class="campo">
                        <option value="">Nenhum</option>
                        <option v-for="p in props.produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-1">
                    <label for="vc-observacao" :class="rotulo">Observação para o Lira (opcional)</label>
                    <textarea id="vc-observacao" v-model="novo.observacao" rows="2" maxlength="2000" :class="campo" placeholder="Ex.: versão com legenda, para a campanha de Natal"></textarea>
                </div>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button" :disabled="!temParaEnviar || enviando" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-60" @click="enviarFila">
                    <i :class="['fas mr-1', enviando ? 'fa-spinner fa-spin' : 'fa-upload']" aria-hidden="true"></i>{{ enviando ? 'Enviando...' : 'Enviar para validação' }}
                </button>
                <span v-if="enviando" class="text-xs text-slate-500 dark:text-slate-400">Não feche a página até terminar.</span>
            </div>
        </section>

        <nav class="mb-3 flex flex-wrap gap-2" aria-label="Situação">
            <button v-for="aba in ABAS" :key="aba.chave" type="button"
                :class="['rounded-full border px-3 py-1.5 text-sm', !filtros.lixeira && filtros.status === aba.chave ? 'border-primary bg-primary/10 font-semibold text-primary' : 'border-[var(--surface-border)]']"
                :aria-pressed="!filtros.lixeira && filtros.status === aba.chave" @click="escolherAba(aba.chave)">
                {{ aba.nome }} <span class="ml-1 text-xs opacity-70">{{ contagem(aba.chave) }}</span>
            </button>
            <button type="button"
                :class="['rounded-full border px-3 py-1.5 text-sm', filtros.lixeira ? 'border-red-400 bg-red-50 font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-300' : 'border-[var(--surface-border)]']"
                :aria-pressed="filtros.lixeira" @click="escolherAba('', true)">
                <i class="fas fa-trash-can mr-1" aria-hidden="true"></i>Lixeira <span class="ml-1 text-xs opacity-70">{{ props.totais.lixeira ?? 0 }}</span>
            </button>
        </nav>

        <form class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-3" @submit.prevent="buscar">
            <div>
                <label for="vc-filtro-plataforma" :class="rotulo">Plataforma</label>
                <select id="vc-filtro-plataforma" v-model="filtros.plataforma" :class="campo" @change="buscar()">
                    <option value="">Todas</option>
                    <option v-for="(nome, chave) in props.plataformas" :key="chave" :value="chave">{{ nome }}</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="vc-busca" :class="rotulo">Nome, arquivo, produto ou observação</label>
                <div class="mt-1 flex gap-2">
                    <input id="vc-busca" v-model="filtros.busca" type="search" autocomplete="off" class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                    <button type="submit" class="whitespace-nowrap rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                        <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i> Buscar
                    </button>
                </div>
            </div>
        </form>

        <p v-if="props.criativos.data.length === 0" class="rounded-xl border border-dashed border-[var(--surface-border)] p-8 text-center text-sm text-slate-500 dark:text-slate-400">
            {{ filtros.lixeira ? 'A lixeira está vazia.' : 'Nenhum criativo aqui. Use "Enviar criativos" para subir o primeiro.' }}
        </p>

        <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <article v-for="c in props.criativos.data" :key="c.id" class="flex flex-col overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                <video :src="c.url" controls preload="metadata" playsinline class="aspect-[9/16] max-h-[28rem] w-full bg-black object-contain"></video>

                <div class="flex flex-1 flex-col gap-2 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <h3 class="font-semibold leading-snug">{{ c.titulo }}</h3>
                        <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs font-medium', STATUS[c.status]?.[1]]">
                            <i :class="['fas mr-1', STATUS[c.status]?.[2]]" aria-hidden="true"></i>{{ STATUS[c.status]?.[0] ?? c.status }}
                        </span>
                    </div>

                    <p class="flex flex-wrap gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                        <span>{{ props.plataformas[c.plataforma] ?? c.plataforma }}</span>
                        <span v-if="c.proporcao">· {{ c.proporcao }}</span>
                        <span v-if="duracao(c.duracao)">· {{ duracao(c.duracao) }}</span>
                        <span>· {{ c.tamanho_mb.toLocaleString('pt-BR') }} MB</span>
                    </p>
                    <p v-if="c.produto" class="text-xs"><i class="fas fa-box mr-1 text-slate-400" aria-hidden="true"></i>{{ c.produto.nome }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Enviado por {{ c.enviado_por ?? 'alguém da equipe' }} em {{ dataHora(c.enviado_em) }}</p>
                    <p v-if="c.observacao" class="whitespace-pre-line rounded-lg bg-[var(--surface-muted)] p-2 text-sm">{{ c.observacao }}</p>
                    <div v-if="c.comentario_avaliacao || c.avaliado_em" :class="['rounded-lg border-l-4 p-2 text-sm', c.status === 'aprovado' ? 'border-green-500' : c.status === 'reprovado' ? 'border-red-500' : 'border-sky-500']">
                        <p v-if="c.comentario_avaliacao" class="whitespace-pre-line">{{ c.comentario_avaliacao }}</p>
                        <p v-if="c.avaliado_em" class="mt-1 text-xs text-slate-500">{{ c.avaliado_por ?? 'Administrador' }}, {{ dataHora(c.avaliado_em) }}</p>
                    </div>

                    <!-- Avaliação (só administrador) -->
                    <div v-if="props.podeAvaliar && !c.na_lixeira" class="mt-auto pt-2">
                        <form v-if="avaliando?.id === c.id" class="space-y-2" @submit.prevent="avaliar(c, avaliando.status, comentario)">
                            <label :for="`vc-comentario-${c.id}`" class="text-xs font-medium">
                                {{ avaliando.status === 'aprovado' ? 'Comentário (opcional)' : 'O que precisa mudar?' }}
                            </label>
                            <textarea :id="`vc-comentario-${c.id}`" v-model="comentario" rows="3" maxlength="2000" :class="campo"></textarea>
                            <p v-if="errosAvaliacao.comentario" class="text-xs text-red-600" role="alert">{{ errosAvaliacao.comentario }}</p>
                            <div class="flex gap-2">
                                <button type="submit" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white">Salvar</button>
                                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm" @click="avaliando = null">Cancelar</button>
                            </div>
                        </form>
                        <div v-else class="flex flex-wrap gap-2">
                            <button v-if="c.status !== 'aprovado'" type="button" class="rounded-lg bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700" @click="avaliar(c, 'aprovado')">
                                <i class="fas fa-check mr-1" aria-hidden="true"></i>Aprovar
                            </button>
                            <button type="button" class="rounded-lg border border-sky-500 px-3 py-1.5 text-sm font-medium text-sky-700 dark:text-sky-300" @click="pedirComentario(c, 'ajustar')">
                                Pedir ajuste
                            </button>
                            <button v-if="c.status !== 'reprovado'" type="button" class="rounded-lg border border-red-400 px-3 py-1.5 text-sm font-medium text-red-700 dark:text-red-300" @click="pedirComentario(c, 'reprovado')">
                                Reprovar
                            </button>
                            <button v-if="c.status !== 'aguardando'" type="button" class="rounded-lg px-2 py-1.5 text-xs text-slate-500 underline" @click="avaliar(c, 'aguardando')">
                                Voltar para a fila
                            </button>
                        </div>
                    </div>

                    <!-- Editar dados -->
                    <form v-if="editando === c.id" class="space-y-2 border-t border-[var(--surface-border)] pt-3" @submit.prevent="salvarEdicao(c)">
                        <div>
                            <label :for="`vc-e-titulo-${c.id}`" :class="rotulo">Nome</label>
                            <input :id="`vc-e-titulo-${c.id}`" v-model="edicao.titulo" type="text" maxlength="150" :class="campo">
                            <p v-if="edicao.errors.titulo" class="text-xs text-red-600">{{ edicao.errors.titulo }}</p>
                        </div>
                        <div>
                            <label :for="`vc-e-plataforma-${c.id}`" :class="rotulo">Plataforma</label>
                            <select :id="`vc-e-plataforma-${c.id}`" v-model="edicao.plataforma" :class="campo">
                                <option v-for="(nome, chave) in props.plataformas" :key="chave" :value="chave">{{ nome }}</option>
                            </select>
                        </div>
                        <div>
                            <label :for="`vc-e-produto-${c.id}`" :class="rotulo">Produto</label>
                            <select :id="`vc-e-produto-${c.id}`" v-model="edicao.produto_id" :class="campo">
                                <option value="">Nenhum</option>
                                <option v-for="p in props.produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                            </select>
                        </div>
                        <div>
                            <label :for="`vc-e-obs-${c.id}`" :class="rotulo">Observação</label>
                            <textarea :id="`vc-e-obs-${c.id}`" v-model="edicao.observacao" rows="2" maxlength="2000" :class="campo"></textarea>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" :disabled="edicao.processing" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white disabled:opacity-60">Salvar</button>
                            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm" @click="editando = null">Cancelar</button>
                        </div>
                    </form>

                    <div class="flex flex-wrap items-center gap-3 border-t border-[var(--surface-border)] pt-2 text-sm">
                        <a :href="c.download" class="text-primary hover:underline"><i class="fas fa-download mr-1" aria-hidden="true"></i>Baixar</a>
                        <template v-if="!c.na_lixeira">
                            <button v-if="editando !== c.id" type="button" class="text-slate-600 hover:underline dark:text-slate-300" @click="editar(c)">
                                <i class="fas fa-pen mr-1" aria-hidden="true"></i>Editar
                            </button>
                            <button type="button" class="ml-auto text-red-600 hover:underline" @click="paraLixeira(c)">
                                <i class="fas fa-trash-can mr-1" aria-hidden="true"></i>Lixeira
                            </button>
                        </template>
                        <button v-else type="button" class="ml-auto text-green-700 hover:underline dark:text-green-400" @click="restaurar(c)">
                            <i class="fas fa-rotate-left mr-1" aria-hidden="true"></i>Restaurar
                        </button>
                    </div>
                </div>
            </article>
        </div>

        <div class="mt-4">
            <ServerPagination :paginator="props.criativos" embedded />
        </div>
    </AdminLayout>
</template>
