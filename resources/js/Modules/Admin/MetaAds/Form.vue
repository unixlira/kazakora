<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { dividirOrcamento, enviarArquivo, formatarReais, pedirJson } from './metaAds';

const props = defineProps({
    campanha: { type: Object, default: null },
    videos: { type: Array, default: () => [] },
    limites: { type: Object, required: true },
    produtos: { type: Array, default: () => [] },
    config: { type: Object, required: true },
    objetivos: { type: Object, required: true },
    generos: { type: Object, required: true },
    chamadas: { type: Object, required: true },
    siteUrl: { type: String, required: true },
});

// Vira "editando" assim que o rascunho é criado, mesmo se um vídeo falhar
// e a pessoa tentar de novo sem sair da tela.
const campanhaId = ref(props.campanha?.id ?? null);
const editando = computed(() => campanhaId.value !== null);
const BASE = '/admin/publicidade/meta-ads/campanhas';
const hoje = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });
const c = props.campanha ?? {};

const form = useForm({
    nome: c.nome ?? '',
    objetivo: c.objetivo ?? (props.config.pixel ? 'OUTCOME_SALES' : 'OUTCOME_TRAFFIC'),
    orcamento_diario: c.orcamento_diario !== undefined ? Number(c.orcamento_diario) : 20,
    inicio: c.inicio ?? hoje,
    fim: c.fim ?? '',
    idade_min: c.idade_min ?? 18,
    idade_max: c.idade_max ?? 65,
    genero: c.genero ?? 'todos',
    localizacoes: {
        regioes: [...(c.localizacoes?.regioes ?? [])],
        cidades: [...(c.localizacoes?.cidades ?? [])],
    },
    produto_id: c.produto_id ?? '',
    link_destino: c.link_destino ?? '',
    texto_principal: c.texto_principal ?? '',
    titulo: c.titulo ?? '',
    descricao: c.descricao ?? '',
    chamada: c.chamada ?? 'SHOP_NOW',
});

const produtoSelecionado = computed(() => props.produtos.find((p) => p.id === Number(form.produto_id)) ?? null);

// Ao escolher o produto, sugere o link e o título dele.
const aoEscolherProduto = () => {
    const p = produtoSelecionado.value;
    if (!p) return;
    form.link_destino = p.link;
    if (!form.titulo) form.titulo = p.nome.slice(0, 255);
};

// Vídeos: os já guardados no servidor e os escolhidos agora (sobem ao salvar).
const salvos = ref([...props.videos]);
const novos = ref([]);
const arrastando = ref(false);
const erroVideos = ref('');
const seletor = ref(null);
const maxBytes = props.limites.max_mb * 1024 * 1024;

const totalVideos = computed(() => salvos.value.length + novos.value.filter((v) => v.estado !== 'erro').length);

const adicionarArquivos = (lista) => {
    erroVideos.value = '';
    for (const arquivo of Array.from(lista ?? [])) {
        if (totalVideos.value >= props.limites.max_videos) {
            erroVideos.value = `Máximo de ${props.limites.max_videos} vídeos por campanha.`;
            break;
        }
        if (!['video/mp4', 'video/quicktime'].includes(arquivo.type)) {
            erroVideos.value = `${arquivo.name}: use vídeo MP4 ou MOV.`;
            continue;
        }
        if (arquivo.size > maxBytes) {
            erroVideos.value = `${arquivo.name} passa de ${props.limites.max_mb} MB. Comprima antes de enviar.`;
            continue;
        }
        novos.value.push({ chave: `${arquivo.name}-${arquivo.size}-${Date.now()}`, arquivo, previa: URL.createObjectURL(arquivo), progresso: 0, estado: 'esperando', erro: '' });
    }
};

const aoSoltar = (evento) => {
    arrastando.value = false;
    adicionarArquivos(evento.dataTransfer?.files);
};

const tirarNovo = (chave) => {
    const item = novos.value.find((v) => v.chave === chave);
    if (item) URL.revokeObjectURL(item.previa);
    novos.value = novos.value.filter((v) => v.chave !== chave);
};

const tirarSalvo = async (video) => {
    if (!window.confirm(`Tirar o vídeo ${video.nome} da campanha?`)) return;
    try {
        await pedirJson('DELETE', `${BASE}/${campanhaId.value}/videos/${video.id}`);
        salvos.value = salvos.value.filter((v) => v.id !== video.id);
    } catch (erro) {
        erroVideos.value = erro.message;
    }
};

onBeforeUnmount(() => novos.value.forEach((v) => URL.revokeObjectURL(v.previa)));

const partes = computed(() => dividirOrcamento(form.orcamento_diario, totalVideos.value));
const orcamentoBaixo = computed(() => totalVideos.value > 0 && partes.value[0] !== undefined
    && Math.min(...partes.value) < props.limites.minimo_por_video);
const primeiroVideo = computed(() => novos.value[0]?.previa ?? salvos.value[0]?.url ?? null);

const dias = computed(() => {
    if (!form.fim || !form.inicio) return null;
    const ms = new Date(`${form.fim}T00:00:00`) - new Date(`${form.inicio}T00:00:00`);
    return ms >= 0 ? Math.round(ms / 86400000) + 1 : null;
});

const totalPrevisto = computed(() => (dias.value ? dias.value * Number(form.orcamento_diario || 0) : null));

// Busca de estados e cidades no Meta (só com o Meta configurado).
const busca = ref('');
const resultados = ref([]);
const buscando = ref(false);
const erroBusca = ref('');
let timer = null;

const buscarLocal = () => {
    clearTimeout(timer);
    erroBusca.value = '';
    if (busca.value.trim().length < 2) {
        resultados.value = [];
        return;
    }
    timer = setTimeout(async () => {
        buscando.value = true;
        try {
            const resposta = await fetch(`${BASE}/localizacoes?q=${encodeURIComponent(busca.value.trim())}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const json = await resposta.json();
            resultados.value = json.data ?? [];
            erroBusca.value = json.erro ?? '';
        } catch {
            erroBusca.value = 'Não deu para buscar agora.';
        } finally {
            buscando.value = false;
        }
    }, 350);
};

const adicionarLocal = (item) => {
    const lista = item.tipo === 'cidade' ? form.localizacoes.cidades : form.localizacoes.regioes;
    if (!lista.some((l) => l.key === item.key)) {
        lista.push(item.tipo === 'cidade' ? { key: item.key, nome: item.nome, raio_km: null } : { key: item.key, nome: item.nome });
    }
    busca.value = '';
    resultados.value = [];
};

const removerLocal = (tipo, key) => {
    form.localizacoes[tipo] = form.localizacoes[tipo].filter((l) => l.key !== key);
};

// Salva o rascunho (JSON) e depois sobe os vídeos novos um por um, cada
// um com a sua barra de progresso. Nada disso fala com a Meta.
const salvando = ref(false);

const salvar = async () => {
    if (salvando.value) return;
    salvando.value = true;
    form.clearErrors();
    erroVideos.value = '';

    try {
        const url = editando.value ? `${BASE}/${campanhaId.value}` : BASE;
        const resposta = await pedirJson(editando.value ? 'PUT' : 'POST', url, form.data());
        campanhaId.value = resposta.id;
    } catch (erro) {
        if (erro.erros) form.setError(Object.fromEntries(Object.entries(erro.erros).map(([k, v]) => [k, v[0]])));
        else erroVideos.value = erro.message;
        salvando.value = false;
        return;
    }

    for (const item of novos.value.filter((v) => v.estado !== 'pronto')) {
        item.estado = 'subindo';
        item.erro = '';
        try {
            const resposta = await enviarArquivo(`${BASE}/${campanhaId.value}/videos`, 'video', item.arquivo, (p) => { item.progresso = p; });
            item.estado = 'pronto';
            salvos.value.push(resposta.video);
        } catch (erro) {
            item.estado = 'erro';
            item.erro = erro.message;
        }
    }

    novos.value = novos.value.filter((v) => v.estado !== 'pronto');
    salvando.value = false;

    if (novos.value.length === 0) {
        router.visit(`${BASE}/${campanhaId.value}`);
    } else {
        erroVideos.value = 'O rascunho foi salvo, mas algum vídeo não subiu. Veja o motivo abaixo e clique em salvar de novo.';
    }
};

const tamanho = (bytes) => `${(bytes / 1048576).toFixed(1)} MB`;

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm';
</script>

<template>
    <Head :title="editando ? 'Editar campanha Meta' : 'Nova campanha Meta'" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                {{ editando ? 'Editar campanha' : 'Nova campanha' }}
            </h1>
            <Link href="/admin/publicidade/meta-ads/campanhas" class="text-sm font-medium text-primary hover:underline">Voltar para a lista</Link>
        </div>

        <div v-if="!props.config.configurado" class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900" role="status">
            <strong>Meta não configurada.</strong> O rascunho e os vídeos ficam salvos, mas só dá para enviar depois de configurar.
        </div>

        <form class="grid grid-cols-1 gap-6 lg:grid-cols-3" @submit.prevent="salvar">
            <div class="space-y-6 lg:col-span-2">
                <!-- Campanha -->
                <section class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h2 class="text-lg font-semibold">Campanha</h2>
                    <div>
                        <label for="nome" class="block text-sm font-medium">Nome</label>
                        <input id="nome" v-model="form.nome" type="text" required maxlength="200" :class="campo" placeholder="Ex.: Parafusadeira outubro">
                        <InputError :message="form.errors.nome" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="objetivo" class="block text-sm font-medium">Objetivo</label>
                            <select id="objetivo" v-model="form.objetivo" :class="campo">
                                <option v-for="(rotulo, valor) in props.objetivos" :key="valor" :value="valor">{{ rotulo }}</option>
                            </select>
                            <p v-if="form.objetivo === 'OUTCOME_SALES' && !props.config.pixel" class="mt-1 text-xs text-amber-700">
                                Vendas precisa do pixel da Meta configurado. Sem ele, use Tráfego.
                            </p>
                            <InputError :message="form.errors.objetivo" />
                        </div>
                        <div>
                            <label for="orcamento" class="block text-sm font-medium">Orçamento da campanha por dia (R$)</label>
                            <input id="orcamento" v-model.number="form.orcamento_diario" type="number" step="0.01" min="1" required :class="campo">
                            <InputError :message="form.errors.orcamento_diario" />
                        </div>
                        <div>
                            <label for="inicio" class="block text-sm font-medium">Início</label>
                            <input id="inicio" v-model="form.inicio" type="date" required :class="campo">
                            <InputError :message="form.errors.inicio" />
                        </div>
                        <div>
                            <label for="fim" class="block text-sm font-medium">Fim (opcional)</label>
                            <input id="fim" v-model="form.fim" type="date" :min="form.inicio" :class="campo">
                            <InputError :message="form.errors.fim" />
                        </div>
                    </div>
                    <p class="text-sm text-slate-500">
                        <template v-if="totalPrevisto !== null">
                            {{ dias }} dia(s) a {{ formatarReais(form.orcamento_diario) }} = até <strong>{{ formatarReais(totalPrevisto) }}</strong> no total.
                        </template>
                        <template v-else>Sem data de fim: gasta até {{ formatarReais(form.orcamento_diario) }} por dia até você pausar.</template>
                        <template v-if="totalVideos > 1">
                            Dividido igual entre os {{ totalVideos }} vídeos: <strong>{{ formatarReais(partes[0]) }}</strong> por vídeo por dia.
                        </template>
                    </p>
                </section>

                <!-- Vídeos -->
                <section class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" aria-labelledby="videos-titulo">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 id="videos-titulo" class="text-lg font-semibold">Vídeos</h2>
                        <span class="text-xs text-slate-500">{{ totalVideos }} de {{ props.limites.max_videos }} · MP4 ou MOV até {{ props.limites.max_mb }} MB · vertical 9:16 rende mais</span>
                    </div>
                    <p class="text-sm text-slate-500">Cada vídeo vira um anúncio com a sua parte do orçamento. Assim dá para ver qual vídeo vende mais.</p>

                    <div
                        class="flex flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center transition-colors"
                        :class="arrastando ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)]'"
                        @dragover.prevent="arrastando = true"
                        @dragleave.prevent="arrastando = false"
                        @drop.prevent="aoSoltar"
                    >
                        <i class="fas fa-film text-2xl text-slate-400" aria-hidden="true"></i>
                        <p class="text-sm">Arraste os vídeos aqui ou</p>
                        <button type="button" :disabled="totalVideos >= props.limites.max_videos || salvando"
                            class="rounded-lg border border-primary px-4 py-1.5 text-sm font-medium text-primary hover:bg-primary/10 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="seletor?.click()">
                            Escolher vídeos
                        </button>
                        <input ref="seletor" type="file" accept="video/mp4,video/quicktime" multiple class="sr-only" aria-label="Escolher vídeos"
                            @change="adicionarArquivos($event.target.files); $event.target.value = ''">
                    </div>
                    <p v-if="erroVideos" class="text-sm text-red-600" role="alert">{{ erroVideos }}</p>

                    <ul v-if="salvos.length || novos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <li v-for="(v, i) in salvos" :key="`s${v.id}`" class="overflow-hidden rounded-lg border border-[var(--surface-border)]">
                            <video :src="v.url" class="aspect-[9/16] w-full bg-black object-contain" preload="metadata" muted controls></video>
                            <div class="space-y-1 p-2 text-xs">
                                <p class="truncate font-semibold" :title="v.nome">{{ v.nome }}</p>
                                <p class="text-slate-500">{{ v.tamanho_mb }} MB<template v-if="totalVideos"> · {{ formatarReais(partes[i]) }}/dia</template></p>
                                <button type="button" class="font-medium text-red-600 hover:underline" :disabled="salvando" @click="tirarSalvo(v)">Tirar</button>
                            </div>
                        </li>
                        <li v-for="(v, i) in novos" :key="v.chave" class="overflow-hidden rounded-lg border" :class="v.estado === 'erro' ? 'border-red-400' : 'border-[var(--surface-border)]'">
                            <video :src="v.previa" class="aspect-[9/16] w-full bg-black object-contain" preload="metadata" muted controls></video>
                            <div class="space-y-1 p-2 text-xs">
                                <p class="truncate font-semibold" :title="v.arquivo.name">{{ v.arquivo.name }}</p>
                                <p class="text-slate-500">{{ tamanho(v.arquivo.size) }}<template v-if="v.estado !== 'erro'"> · {{ formatarReais(partes[salvos.length + i]) }}/dia</template></p>
                                <div v-if="v.estado === 'subindo'" class="h-1.5 overflow-hidden rounded-full bg-slate-500/20" role="progressbar" :aria-valuenow="v.progresso" aria-valuemin="0" aria-valuemax="100" :aria-label="`Enviando ${v.arquivo.name}`">
                                    <div class="h-full bg-primary transition-all" :style="{ width: `${v.progresso}%` }"></div>
                                </div>
                                <p v-if="v.estado === 'esperando'" class="text-slate-500">Sobe ao salvar</p>
                                <p v-if="v.estado === 'erro'" class="text-red-600">{{ v.erro }}</p>
                                <button v-if="v.estado !== 'subindo'" type="button" class="font-medium text-red-600 hover:underline" @click="tirarNovo(v.chave)">Tirar</button>
                            </div>
                        </li>
                    </ul>
                    <p v-if="orcamentoBaixo" class="text-sm text-amber-700" role="status">
                        Cada vídeo precisa de pelo menos {{ formatarReais(props.limites.minimo_por_video) }} por dia.
                        Com {{ totalVideos }} vídeos, o orçamento mínimo é {{ formatarReais(props.limites.minimo_por_video * totalVideos) }}.
                    </p>
                </section>

                <!-- Público -->
                <section class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h2 class="text-lg font-semibold">Público</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label for="idade_min" class="block text-sm font-medium">Idade mínima</label>
                            <input id="idade_min" v-model.number="form.idade_min" type="number" min="18" max="65" required :class="campo">
                            <InputError :message="form.errors.idade_min" />
                        </div>
                        <div>
                            <label for="idade_max" class="block text-sm font-medium">Idade máxima</label>
                            <input id="idade_max" v-model.number="form.idade_max" type="number" min="18" max="65" required :class="campo">
                            <InputError :message="form.errors.idade_max" />
                        </div>
                        <div>
                            <label for="genero" class="block text-sm font-medium">Gênero</label>
                            <select id="genero" v-model="form.genero" :class="campo">
                                <option v-for="(rotulo, valor) in props.generos" :key="valor" :value="valor">{{ rotulo }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="busca-local" class="block text-sm font-medium">Onde mostrar</label>
                        <p class="text-xs text-slate-500">Sem estado ou cidade escolhidos, mostra para o Brasil todo.</p>
                        <div class="relative">
                            <input id="busca-local" v-model="busca" type="search" autocomplete="off" :disabled="!props.config.configurado"
                                :placeholder="props.config.configurado ? 'Buscar estado ou cidade...' : 'Busca disponível depois de configurar a Meta'"
                                :class="campo" @input="buscarLocal">
                            <ul v-if="resultados.length" class="absolute z-10 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] shadow-lg">
                                <li v-for="item in resultados" :key="item.key">
                                    <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-primary/10" @click="adicionarLocal(item)">
                                        <span>{{ item.nome }}</span><span class="text-xs text-slate-500">{{ item.tipo }}</span>
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p v-if="buscando" class="mt-1 text-xs text-slate-500">Buscando…</p>
                        <p v-if="erroBusca" class="mt-1 text-xs text-red-600">{{ erroBusca }}</p>

                        <div class="mt-2 flex flex-wrap gap-2">
                            <span v-if="!form.localizacoes.regioes.length && !form.localizacoes.cidades.length"
                                class="rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">Brasil inteiro</span>
                            <span v-for="r in form.localizacoes.regioes" :key="`r${r.key}`" class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                {{ r.nome }}
                                <button type="button" :aria-label="`Remover ${r.nome}`" class="ml-1" @click="removerLocal('regioes', r.key)"><i class="fas fa-xmark" aria-hidden="true"></i></button>
                            </span>
                            <span v-for="cid in form.localizacoes.cidades" :key="`c${cid.key}`" class="inline-flex items-center gap-2 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                                {{ cid.nome }}
                                <select v-model.number="cid.raio_km" :aria-label="`Raio em volta de ${cid.nome}`" class="rounded border-none bg-transparent p-0 text-xs">
                                    <option :value="null">só a cidade</option>
                                    <option v-for="km in [10, 25, 40, 80]" :key="km" :value="km">+{{ km }} km</option>
                                </select>
                                <button type="button" :aria-label="`Remover ${cid.nome}`" @click="removerLocal('cidades', cid.key)"><i class="fas fa-xmark" aria-hidden="true"></i></button>
                            </span>
                        </div>
                    </div>
                </section>

                <!-- Anúncio -->
                <section class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h2 class="text-lg font-semibold">Anúncio</h2>
                    <div>
                        <label for="produto" class="block text-sm font-medium">Produto (opcional)</label>
                        <select id="produto" v-model="form.produto_id" :class="campo" @change="aoEscolherProduto">
                            <option value="">Nenhum (link livre)</option>
                            <option v-for="p in props.produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                        </select>
                        <InputError :message="form.errors.produto_id" />
                    </div>
                    <div>
                        <label for="link" class="block text-sm font-medium">Link de destino</label>
                        <input id="link" v-model="form.link_destino" type="url" maxlength="500" :class="campo" :placeholder="`${props.siteUrl}/produtos/...`">
                        <p class="mt-1 text-xs text-slate-500">Vazio = página do produto escolhido, ou a home da loja.</p>
                        <InputError :message="form.errors.link_destino" />
                    </div>
                    <div>
                        <label for="texto" class="block text-sm font-medium">Texto principal</label>
                        <textarea id="texto" v-model="form.texto_principal" rows="4" required maxlength="2000" :class="campo" />
                        <InputError :message="form.errors.texto_principal" />
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="titulo" class="block text-sm font-medium">Título</label>
                            <input id="titulo" v-model="form.titulo" type="text" required maxlength="255" :class="campo">
                            <InputError :message="form.errors.titulo" />
                        </div>
                        <div>
                            <label for="descricao" class="block text-sm font-medium">Descrição (opcional)</label>
                            <input id="descricao" v-model="form.descricao" type="text" maxlength="255" :class="campo">
                            <InputError :message="form.errors.descricao" />
                        </div>
                        <div>
                            <label for="chamada" class="block text-sm font-medium">Botão</label>
                            <select id="chamada" v-model="form.chamada" :class="campo">
                                <option v-for="(rotulo, valor) in props.chamadas" :key="valor" :value="valor">{{ rotulo }}</option>
                            </select>
                        </div>
                    </div>
                </section>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="salvando"
                        class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                        {{ salvando ? 'Salvando…' : (editando ? 'Salvar rascunho' : 'Criar rascunho') }}
                    </button>
                    <span class="text-xs text-slate-500">Salvar guarda os vídeos aqui no servidor. Nada vai para a Meta ainda.</span>
                </div>
            </div>

            <!-- Prévia -->
            <aside class="lg:sticky lg:top-4 lg:self-start">
                <p class="mb-2 text-sm font-semibold text-slate-500">Prévia aproximada</p>
                <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
                    <div class="flex items-center gap-2 p-3">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-bold text-white">KK</div>
                        <div class="text-sm font-semibold">KazaKora <span class="block text-xs font-normal text-slate-500">Patrocinado</span></div>
                    </div>
                    <p class="whitespace-pre-line px-3 pb-3 text-sm">{{ form.texto_principal || 'Texto principal do anúncio' }}</p>
                    <video v-if="primeiroVideo" :src="primeiroVideo" class="aspect-[4/5] w-full bg-black object-cover" muted loop autoplay playsinline aria-label="Prévia do primeiro vídeo"></video>
                    <div v-else class="flex aspect-[4/5] w-full items-center justify-center bg-slate-500/10 text-sm text-slate-500">Sem vídeo</div>
                    <div class="flex items-center justify-between gap-3 bg-slate-500/5 p-3">
                        <div class="min-w-0">
                            <p class="truncate text-xs uppercase text-slate-500">kazakora.com</p>
                            <p class="truncate text-sm font-semibold">{{ form.titulo || 'Título' }}</p>
                            <p v-if="form.descricao" class="truncate text-xs text-slate-500">{{ form.descricao }}</p>
                        </div>
                        <span class="shrink-0 rounded-md bg-slate-500/15 px-3 py-1.5 text-xs font-semibold">{{ props.chamadas[form.chamada] }}</span>
                    </div>
                </div>
            </aside>
        </form>
    </AdminLayout>
</template>
