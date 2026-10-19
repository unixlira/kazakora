<script setup>
// Editar e excluir anúncio (Meta ADS 2026-10-19, spec do Jonathan). Usado no
// espelho (linha do anúncio) e no card do anúncio da tela da campanha. A
// tela chama editar(alvo) ou excluir(alvo), com:
//   alvo = { local: dados do KazaKora (dadosParaEditar), nome, status (da Meta),
//            gasto (no período), ultimoAtivo (único ativo do conjunto) }
// Na Meta, nada muda no KazaKora antes de a Meta aceitar (o servidor garante).
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { notifySuccess } from '@/Shared/notify';
import { computed, reactive, ref } from 'vue';
import { enviarArquivo, formatarReais, pedirJson, statusMeta } from './metaAds';

const props = defineProps({
    produtos: { type: Array, default: () => [] },
    chamadas: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['mudou']);

const BASE = '/admin/publicidade/meta-ads/campanhas';
const MAX_MB = 60;
const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40';
const botao = 'inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-50';

const url = (local) => `${BASE}/${local.campanha_id}/videos/${local.id}`;
const ativo = (alvo) => alvo?.status === 'ACTIVE';
const nomeDoStatus = (alvo) => statusMeta(alvo?.status)[0].toLowerCase();

// Editar ---------------------------------------------------------------------

const edicao = ref(null);
const form = reactive({ nome: '', produto_id: '', link_destino: '', texto_principal: '', titulo: '', descricao: '', chamada: 'SHOP_NOW' });
const inicial = ref({});
const arquivo = ref(null);
const progresso = ref(0);
const salvando = ref(false);
const erros = ref({});
const erroGeral = ref('');
const entendiAnalise = ref(false);

const CRIATIVO = ['link_destino', 'texto_principal', 'titulo', 'descricao', 'chamada'];
const limpo = (v) => (v === null || v === undefined ? '' : String(v).trim());

const editar = (alvo) => {
    const l = alvo.local;
    const valores = {
        nome: alvo.nome ?? l.nome ?? '',
        produto_id: l.produto_id ?? '',
        link_destino: l.link_destino ?? '',
        texto_principal: l.texto_principal ?? '',
        titulo: l.titulo ?? '',
        descricao: l.descricao ?? '',
        chamada: l.chamada ?? 'SHOP_NOW',
    };
    Object.assign(form, valores);
    inicial.value = { ...valores };
    arquivo.value = null;
    progresso.value = 0;
    erros.value = {};
    erroGeral.value = '';
    entendiAnalise.value = false;
    edicao.value = alvo;
};

const naMeta = computed(() => Boolean(edicao.value?.local?.na_meta));
const mudaCriativo = computed(() => Boolean(arquivo.value) || CRIATIVO.some((k) => limpo(form[k]) !== limpo(inicial.value[k])));
const mudouAlgo = computed(() => mudaCriativo.value || Object.keys(form).some((k) => limpo(form[k]) !== limpo(inicial.value[k])));
const precisaConfirmar = computed(() => naMeta.value && mudaCriativo.value);
const tituloEdicao = computed(() => `Editar o anúncio "${edicao.value?.nome ?? ''}"`);
const rotuloSalvar = computed(() => (precisaConfirmar.value ? 'Salvar e enviar para análise' : 'Salvar'));

const aoEscolherProduto = () => {
    const p = props.produtos.find((x) => x.id === Number(form.produto_id));
    if (p) form.link_destino = p.link;
};

const aoEscolherArquivo = (evento) => {
    const f = evento.target.files?.[0] ?? null;
    erros.value = { ...erros.value, arquivo: undefined };
    if (f && f.size > MAX_MB * 1048576) {
        erros.value = { ...erros.value, arquivo: [`O arquivo passa de ${MAX_MB} MB. Comprima antes de enviar.`] };
        evento.target.value = '';
        arquivo.value = null;
        return;
    }
    arquivo.value = f;
};

const tamanho = (f) => `${(f.size / 1048576).toFixed(1).replace('.', ',')} MB`;

const salvar = async () => {
    if (!edicao.value || salvando.value || !mudouAlgo.value || (precisaConfirmar.value && !entendiAnalise.value)) return;
    salvando.value = true;
    erros.value = {};
    erroGeral.value = '';
    const dados = {
        ...form,
        produto_id: form.produto_id || null,
        confirmo_analise: precisaConfirmar.value && entendiAnalise.value ? 1 : 0,
    };

    try {
        const resposta = arquivo.value
            ? await enviarArquivo(url(edicao.value.local), 'arquivo', arquivo.value, (p) => { progresso.value = p; }, { ...dados, _method: 'PUT' })
            : await pedirJson('PUT', url(edicao.value.local), dados);
        notifySuccess(resposta?.message ?? 'Anúncio atualizado.');
        edicao.value = null;
        emit('mudou');
    } catch (erro) {
        erros.value = erro.erros ?? {};
        erroGeral.value = erro.erros ? '' : erro.message;
    } finally {
        salvando.value = false;
    }
};

// Excluir --------------------------------------------------------------------

const exclusao = ref(null);
const entendiDefinitivo = ref(false);
const entendiSemAtivo = ref(false);
const excluindo = ref(false);
const erroExclusao = ref('');
const ultimoAtivo = ref(false);

const excluir = (alvo) => {
    entendiDefinitivo.value = false;
    entendiSemAtivo.value = false;
    erroExclusao.value = '';
    ultimoAtivo.value = Boolean(alvo.ultimoAtivo);
    exclusao.value = alvo;
};

const exclusaoNaMeta = computed(() => Boolean(exclusao.value?.local?.na_meta));
const podeExcluirDeVez = computed(() => entendiDefinitivo.value && (!ultimoAtivo.value || entendiSemAtivo.value));
const tituloExclusao = computed(() => `Excluir o anúncio "${exclusao.value?.nome ?? ''}"?`);
const nomeDoConjunto = computed(() => exclusao.value?.local?.conjunto ?? '');

const confirmarExclusao = async () => {
    if (!exclusao.value || excluindo.value) return;
    excluindo.value = true;
    erroExclusao.value = '';
    try {
        const resposta = await pedirJson('DELETE', url(exclusao.value.local), exclusaoNaMeta.value
            ? { confirmo_exclusao: entendiDefinitivo.value, sem_anuncio_ativo: ultimoAtivo.value && entendiSemAtivo.value }
            : null);
        notifySuccess(resposta?.message ?? 'Anúncio excluído.');
        exclusao.value = null;
        emit('mudou');
    } catch (erro) {
        // A Meta disse que é o último ativo (o espelho estava desatualizado).
        if (erro.erros?.sem_anuncio_ativo) {
            ultimoAtivo.value = true;
            erroExclusao.value = '';
        } else {
            erroExclusao.value = erro.message;
        }
    } finally {
        excluindo.value = false;
    }
};

const pausar = async () => {
    if (!exclusao.value || excluindo.value) return;
    excluindo.value = true;
    erroExclusao.value = '';
    try {
        const resposta = await pedirJson('POST', `${url(exclusao.value.local)}/pausar`);
        notifySuccess(resposta?.message ?? 'Anúncio pausado na Meta.');
        exclusao.value = null;
        emit('mudou');
    } catch (erro) {
        erroExclusao.value = erro.message;
    } finally {
        excluindo.value = false;
    }
};

defineExpose({ editar, excluir });
</script>

<template>
    <ConfirmModal :open="edicao !== null" :title="tituloEdicao" width="max-w-2xl" :loading="salvando" @close="edicao = null">
        <form v-if="edicao" class="space-y-4" novalidate @submit.prevent="salvar">
            <p class="text-xs text-slate-500">
                Conjunto: <strong>{{ edicao.local.conjunto }}</strong>. O conjunto não muda depois de enviado.
            </p>

            <div>
                <label for="anuncio-nome" class="block text-sm font-medium">Nome do anúncio</label>
                <input id="anuncio-nome" name="nome" v-model="form.nome" type="text" maxlength="255" :class="campo" autocomplete="off" spellcheck="false">
                <p v-if="erros.nome" class="mt-1 text-xs text-red-600">{{ erros.nome[0] }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="anuncio-produto" class="block text-sm font-medium">Produto</label>
                    <select id="anuncio-produto" name="produto_id" v-model="form.produto_id" :class="campo" @change="aoEscolherProduto">
                        <option value="">Sem produto</option>
                        <option v-for="p in produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Escolher o produto preenche o link.</p>
                </div>
                <div>
                    <label for="anuncio-chamada" class="block text-sm font-medium">Botão</label>
                    <select id="anuncio-chamada" name="chamada" v-model="form.chamada" :class="campo">
                        <option v-for="(rotulo, valor) in chamadas" :key="valor" :value="valor">{{ rotulo }}</option>
                    </select>
                    <p v-if="erros.chamada" class="mt-1 text-xs text-red-600">{{ erros.chamada[0] }}</p>
                </div>
            </div>

            <div>
                <label for="anuncio-link" class="block text-sm font-medium">Link de destino</label>
                <input id="anuncio-link" name="link_destino" autocomplete="off" v-model="form.link_destino" type="url" inputmode="url" maxlength="500" placeholder="https://kazakora.com/produtos/…" :class="campo"
                    :aria-invalid="Boolean(erros.link_destino)" aria-describedby="anuncio-link-ajuda">
                <p id="anuncio-link-ajuda" class="mt-1 text-xs" :class="erros.link_destino ? 'text-red-600' : 'text-slate-500'">
                    {{ erros.link_destino ? erros.link_destino[0] : 'Só links de https://kazakora.com' }}
                </p>
            </div>

            <div>
                <label for="anuncio-texto" class="block text-sm font-medium">Texto principal</label>
                <textarea id="anuncio-texto" name="texto_principal" v-model="form.texto_principal" rows="4" maxlength="2000" :class="campo"></textarea>
                <p v-if="erros.texto_principal" class="mt-1 text-xs text-red-600">{{ erros.texto_principal[0] }}</p>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="anuncio-titulo" class="block text-sm font-medium">Título</label>
                    <input id="anuncio-titulo" name="titulo" autocomplete="off" v-model="form.titulo" type="text" maxlength="255" :class="campo">
                    <p v-if="erros.titulo" class="mt-1 text-xs text-red-600">{{ erros.titulo[0] }}</p>
                </div>
                <div>
                    <label for="anuncio-descricao" class="block text-sm font-medium">Descrição</label>
                    <input id="anuncio-descricao" name="descricao" autocomplete="off" v-model="form.descricao" type="text" maxlength="255" :class="campo">
                    <p v-if="erros.descricao" class="mt-1 text-xs text-red-600">{{ erros.descricao[0] }}</p>
                </div>
            </div>

            <div>
                <span id="anuncio-arquivo-rotulo" class="block text-sm font-medium">Vídeo ou imagem</span>
                <p class="mt-1 truncate text-xs text-slate-500">
                    Atual: {{ edicao.local.arquivo }} ({{ edicao.local.tipo === 'imagem' ? 'imagem' : 'vídeo' }})
                </p>
                <label for="anuncio-arquivo" class="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--surface-muted)] focus-within:ring-2 focus-within:ring-primary/40">
                    <i class="fas fa-arrow-up-from-bracket text-xs" aria-hidden="true"></i> Trocar arquivo
                    <input id="anuncio-arquivo" name="arquivo" type="file" accept="video/mp4,video/quicktime,image/jpeg,image/png" class="sr-only"
                        aria-labelledby="anuncio-arquivo-rotulo" @change="aoEscolherArquivo">
                </label>
                <p v-if="arquivo" class="mt-1 text-xs">Novo: <strong>{{ arquivo.name }}</strong> · {{ tamanho(arquivo) }}</p>
                <p class="mt-1 text-xs text-slate-500">MP4 ou MOV, ou JPG ou PNG, até {{ MAX_MB }} MB. Vídeo deitado sobe como 9:16.</p>
                <p v-if="erros.arquivo" class="mt-1 text-xs text-red-600">{{ erros.arquivo[0] }}</p>
                <div v-if="salvando && arquivo" class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-500/20" role="progressbar"
                    :aria-valuenow="progresso" aria-valuemin="0" aria-valuemax="100" aria-label="Enviando o arquivo novo">
                    <div class="h-full bg-primary transition-[width]" :style="{ width: `${progresso}%` }"></div>
                </div>
            </div>

            <div v-if="precisaConfirmar" class="space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                <p>Mudar texto, link, botão ou arquivo cria um criativo novo neste anúncio. Ele volta para a análise da Meta (costuma levar de minutos a algumas horas). As métricas continuam.</p>
                <p v-if="ativo(edicao)">Enquanto a Meta analisa, o anúncio pode ficar sem entrega.</p>
                <label class="flex items-start gap-2">
                    <input v-model="entendiAnalise" type="checkbox" class="mt-0.5">
                    <span>Entendi que o anúncio volta para a análise da Meta.</span>
                </label>
                <p v-if="erros.confirmo_analise" class="text-xs text-red-600">{{ erros.confirmo_analise[0] }}</p>
            </div>

            <p aria-live="polite" class="text-sm text-red-700" :class="{ 'sr-only': !erroGeral }">{{ erroGeral }}</p>
            <button type="submit" class="hidden" tabindex="-1" aria-hidden="true"></button>
        </form>

        <template #actions>
            <button type="button" :disabled="salvando" :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="edicao = null">
                Voltar
            </button>
            <button type="button" :disabled="salvando || !mudouAlgo || (precisaConfirmar && !entendiAnalise)"
                :class="[botao, 'bg-primary text-white hover:bg-primary-emphasis']" @click="salvar">
                <i v-if="salvando" class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                {{ salvando && arquivo ? `Enviando ${progresso}%` : rotuloSalvar }}
            </button>
        </template>
    </ConfirmModal>

    <ConfirmModal :open="exclusao !== null" :title="tituloExclusao" danger :loading="excluindo" @close="exclusao = null">
        <template v-if="exclusao">
            <p v-if="!exclusaoNaMeta" class="text-sm">Ele ainda não está na Meta. Sai do KazaKora junto com o arquivo.</p>
            <template v-else>
                <p class="text-sm">
                    Este anúncio já gastou <strong>{{ formatarReais(exclusao.gasto ?? 0) }}</strong> no período e está <strong>{{ nomeDoStatus(exclusao) }}</strong>.
                    Excluir apaga o anúncio na Meta e não tem volta. Pausar para a entrega e mantém tudo.
                </p>
                <div v-if="ultimoAtivo" class="mt-3 space-y-2 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-500/10 dark:text-red-200" role="alert">
                    <p>É o último anúncio ativo do conjunto "{{ nomeDoConjunto }}". Sem ele o conjunto para de entregar.</p>
                    <label class="flex items-start gap-2">
                        <input v-model="entendiSemAtivo" type="checkbox" class="mt-0.5">
                        <span>Mesmo assim, quero deixar o conjunto sem anúncio ativo.</span>
                    </label>
                </div>
                <label class="mt-3 flex items-start gap-2 text-sm">
                    <input v-model="entendiDefinitivo" type="checkbox" class="mt-0.5">
                    <span>Entendi que a exclusão na Meta é definitiva.</span>
                </label>
            </template>
            <p aria-live="assertive" class="mt-3 text-sm text-red-700" :class="{ 'sr-only': !erroExclusao }">{{ erroExclusao }}</p>
        </template>

        <template #actions>
            <button type="button" :disabled="excluindo" :class="[botao, 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="exclusao = null">
                Voltar
            </button>
            <template v-if="exclusaoNaMeta">
                <button v-if="ativo(exclusao)" type="button" :disabled="excluindo"
                    :class="[botao, ultimoAtivo ? 'bg-primary text-white hover:bg-primary-emphasis' : 'border border-[var(--surface-border)] hover:bg-[var(--surface-muted)]']" @click="pausar">
                    <i class="fas fa-pause text-xs" aria-hidden="true"></i> Pausar em vez de excluir
                </button>
                <button type="button" :disabled="excluindo || !podeExcluirDeVez"
                    :class="[botao, ultimoAtivo ? 'border border-red-300 text-red-700 hover:bg-red-50' : 'bg-error text-white hover:opacity-90']" @click="confirmarExclusao">
                    <i v-if="excluindo" class="fas fa-spinner fa-spin" aria-hidden="true"></i> Excluir de vez
                </button>
            </template>
            <button v-else type="button" :disabled="excluindo" :class="[botao, 'bg-error text-white hover:opacity-90']" @click="confirmarExclusao">
                <i v-if="excluindo" class="fas fa-spinner fa-spin" aria-hidden="true"></i> Excluir anúncio
            </button>
        </template>
    </ConfirmModal>
</template>
