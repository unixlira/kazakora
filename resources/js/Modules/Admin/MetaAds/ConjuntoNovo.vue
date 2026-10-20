<script setup>
// Meta ADS (pedido do Lira 2026-10-19): conjunto novo dentro de campanha que
// já está na Meta. Monta nome, orçamento (quando é por conjunto), público e
// os anúncios do conjunto; salvar não fala com a Meta. O envio é um botão na
// tela da campanha e sobe só este conjunto, já ativo (2026-10-20). Com soAnuncios
// ("Acrescentar novos anúncios" em conjunto que já existe), só os anúncios
// novos mudam; nome e público do conjunto ficam como estão.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import AguardeModal from '@/Shared/Components/AguardeModal.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import BuscaLocal from './BuscaLocal.vue';
import { enviarArquivo, formatarReais, pedirJson } from './metaAds';

const props = defineProps({
    campanha: { type: Object, required: true },
    conjunto: { type: Object, default: null },
    soAnuncios: { type: Boolean, default: false },
    jaNaMeta: { type: Array, default: () => [] },
    videos: { type: Array, default: () => [] },
    outrosConjuntos: { type: Array, default: () => [] },
    limites: { type: Object, required: true },
    produtos: { type: Array, default: () => [] },
    config: { type: Object, required: true },
    generos: { type: Object, required: true },
    chamadas: { type: Object, required: true },
    siteUrl: { type: String, required: true },
});

const c = props.campanha;
const BASE = `/admin/publicidade/meta-ads/campanhas/${c.id}`;
const MINIMO = props.limites.minimo_por_conjunto;
const porConjunto = c.orcamento_tipo === 'conjunto';
const conjuntoId = ref(props.conjunto?.id ?? null);
const cj = props.conjunto ?? {};

const form = reactive({
    nome: cj.nome ?? `Conjunto ${props.outrosConjuntos.length + 1}`,
    orcamento_diario: cj.orcamento_diario ?? MINIMO * 2,
    publico_proprio: cj.publico_proprio ?? false,
    idade_min: cj.idade_min ?? c.idade_min,
    idade_max: cj.idade_max ?? c.idade_max,
    genero: cj.genero ?? c.genero,
    localizacoes: {
        regioes: [...(cj.localizacoes?.regioes ?? [])],
        cidades: (cj.localizacoes?.cidades ?? []).map((x) => ({ ...x })),
    },
});
const erros = ref({});

// Público próprio: ao ligar, começa com uma cópia do público da campanha.
const alternarPublico = (usarDaCampanha) => {
    form.publico_proprio = !usarDaCampanha;
    if (form.publico_proprio && !props.conjunto?.publico_proprio) {
        form.idade_min = c.idade_min;
        form.idade_max = c.idade_max;
        form.genero = c.genero;
        form.localizacoes = { regioes: [...(c.localizacoes?.regioes ?? [])], cidades: (c.localizacoes?.cidades ?? []).map((x) => ({ ...x })) };
    }
};

// Anúncios ---------------------------------------------------------------------

const CAMPOS = ['produto_id', 'link_destino', 'texto_principal', 'titulo', 'descricao', 'chamada'];
const camposDe = (v = {}) => Object.fromEntries(CAMPOS.map((k) => [k, v[k] ?? '']));
const TIPOS = { 'video/mp4': 'video', 'video/quicktime': 'video', 'image/jpeg': 'imagem', 'image/png': 'imagem' };
const maxBytes = props.limites.max_mb * 1024 * 1024;
let sequencia = 0;

const anuncios = ref(props.videos.map((v) => ({
    chave: `s${v.id}`, id: v.id, arquivo: null, tipo: v.tipo, nome: v.nome, previa: v.url,
    tamanho: `${v.tamanho_mb} MB`, formato: v.formato ?? '', campos: camposDe(v), estado: 'salvo', progresso: 0, erro: '', aberto: false,
})));
const removidos = ref([]);
const aviso = ref('');
const vagas = () => props.limites.max_videos - (props.limites.anuncios_na_campanha - props.videos.length) - anuncios.value.length;

const adicionarArquivos = (lista) => {
    aviso.value = '';
    for (const arquivo of Array.from(lista ?? [])) {
        if (!TIPOS[arquivo.type]) {
            aviso.value = `"${arquivo.name}" não foi aceito. Use MP4, MOV, JPG ou PNG.`;
            continue;
        }
        if (arquivo.size > maxBytes) {
            aviso.value = `"${arquivo.name}" passa de ${props.limites.max_mb} MB. Comprima antes de enviar.`;
            continue;
        }
        if (vagas() <= 0) {
            aviso.value = `Limite de ${props.limites.max_videos} anúncios por campanha.`;
            break;
        }
        anuncios.value.push({
            chave: `n${sequencia++}`, id: null, arquivo, tipo: TIPOS[arquivo.type], nome: arquivo.name, previa: URL.createObjectURL(arquivo),
            tamanho: `${(arquivo.size / 1048576).toFixed(1)} MB`, formato: '', campos: camposDe(), estado: 'esperando', progresso: 0, erro: '', aberto: true,
        });
    }
};

const arrastando = ref(false);
const aoSoltar = (evento) => {
    arrastando.value = false;
    adicionarArquivos(evento.dataTransfer?.files);
};

const removerAnuncio = (i) => {
    const [tirado] = anuncios.value.splice(i, 1);
    if (tirado.id) removidos.value.push(tirado.id);
    else URL.revokeObjectURL(tirado.previa);
};

const aoEscolherProduto = (a) => {
    const p = props.produtos.find((x) => x.id === Number(a.campos.produto_id));
    if (!p) return;
    a.campos.link_destino = p.link;
    if (!a.campos.titulo) a.campos.titulo = p.nome.slice(0, 255);
};

onBeforeUnmount(() => anuncios.value.forEach((a) => { if (a.arquivo) URL.revokeObjectURL(a.previa); }));

// Pendências ---------------------------------------------------------------------

const preenchido = (v) => v !== null && v !== undefined && String(v).trim() !== '';
const ou = (proprio, padrao) => (preenchido(proprio) ? proprio : padrao);

const pendenciasDoAnuncio = (a) => {
    const lista = [];
    if (!preenchido(ou(a.campos.texto_principal, c.texto_principal))) lista.push('Escreva o texto principal (a campanha não tem texto padrão).');
    if (!preenchido(ou(a.campos.titulo, c.titulo))) lista.push('Escreva o título (a campanha não tem título padrão).');
    return lista;
};

const pendencias = computed(() => {
    const lista = [];
    if (!props.soAnuncios && !preenchido(form.nome)) lista.push('Dê um nome para o conjunto.');
    if (!props.soAnuncios && porConjunto && Number(form.orcamento_diario || 0) < MINIMO) lista.push(`Mínimo de ${formatarReais(MINIMO)} por dia neste conjunto.`);
    if (!anuncios.value.length && (!props.soAnuncios || !removidos.value.length)) lista.push('Adicione pelo menos um vídeo ou imagem.');
    anuncios.value.forEach((a) => lista.push(...pendenciasDoAnuncio(a)));
    return lista;
});

// Salvar ---------------------------------------------------------------------------
// 1) conjunto; 2) tira os anúncios removidos; 3) texto dos já guardados;
// 4) arquivos novos um por um, com barra de progresso. Nada vai para a Meta.

const salvando = ref(false);
const erroGeral = ref('');

// Modal único de espera (2026-10-20): andamento dos arquivos no salvar.
const arquivos = ref({ atual: null, n: 0, total: 0 });
const progressoGeral = computed(() => {
    const { atual, n, total } = arquivos.value;
    return total ? ((Math.max(n - 1, 0) + (atual?.progresso ?? 0) / 100) / total) * 100 : 0;
});
const detalheArquivos = computed(() => {
    const { atual, n, total } = arquivos.value;
    if (!total) return 'Salvando o rascunho…';
    return `Enviando arquivo ${n} de ${total}${atual?.arquivo ? ` (${atual.progresso ?? 0}%)` : ''}`;
});

const salvar = async () => {
    if (salvando.value) return;
    salvando.value = true;
    arquivos.value = { atual: null, n: 0, total: 0 };
    erros.value = {};
    erroGeral.value = '';

    const dados = {
        nome: form.nome,
        orcamento_diario: porConjunto ? form.orcamento_diario : null,
        publico_proprio: form.publico_proprio,
        ...(form.publico_proprio ? { idade_min: form.idade_min, idade_max: form.idade_max, genero: form.genero, localizacoes: form.localizacoes } : {}),
    };

    try {
        const resposta = props.soAnuncios ? { id: conjuntoId.value } : await pedirJson(conjuntoId.value ? 'PUT' : 'POST', conjuntoId.value ? `${BASE}/conjuntos/${conjuntoId.value}` : `${BASE}/conjuntos`, dados);
        conjuntoId.value = resposta.id;
    } catch (erro) {
        if (erro.erros) erros.value = Object.fromEntries(Object.entries(erro.erros).map(([k, m]) => [k, m[0]]));
        erroGeral.value = erro.erros ? 'Nada foi salvo. Corrija o que está em vermelho.' : erro.message;
        salvando.value = false;
        return;
    }

    let falhou = false;
    for (const id of [...removidos.value]) {
        try {
            await pedirJson('DELETE', `${BASE}/videos/${id}`);
            removidos.value = removidos.value.filter((x) => x !== id);
        } catch (erro) {
            erroGeral.value = erro.message;
            falhou = true;
        }
    }

    arquivos.value.total = anuncios.value.filter((a) => !a.id).length;
    for (const a of anuncios.value) {
        a.erro = '';
        const extras = { ...a.campos, conjunto_id: conjuntoId.value };
        try {
            if (a.id) {
                await pedirJson('PUT', `${BASE}/videos/${a.id}`, extras);
                continue;
            }
            arquivos.value = { ...arquivos.value, atual: a, n: arquivos.value.n + 1 };
            a.estado = 'subindo';
            const resposta = await enviarArquivo(`${BASE}/videos`, 'video', a.arquivo, (p) => { a.progresso = p; }, extras);
            URL.revokeObjectURL(a.previa);
            Object.assign(a, { id: resposta.video.id, arquivo: null, previa: resposta.video.url, estado: 'salvo', formato: resposta.video.formato || a.formato });
        } catch (erro) {
            a.estado = a.id ? 'salvo' : 'erro';
            a.erro = erro.message;
            a.aberto = true;
            falhou = true;
        }
    }

    salvando.value = false;

    if (falhou) {
        erroGeral.value ||= 'O conjunto foi salvo, mas algum anúncio não. Veja o motivo no anúncio e salve de novo.';
        window.history.replaceState(window.history.state, '', `${BASE}/conjuntos/${conjuntoId.value}/${props.soAnuncios ? 'anuncios' : 'editar'}`);
        return;
    }

    router.visit(BASE);
};

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm';
const cartao = 'rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm';
const botaoIcone = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-[var(--surface-muted)] disabled:cursor-not-allowed disabled:opacity-40';
</script>

<template>
    <Head :title="soAnuncios ? 'Acrescentar novos anúncios' : (conjunto ? 'Editar conjunto novo' : 'Acrescentar novo conjunto')" />

    <AdminLayout>
        <div class="mb-2">
            <Link :href="BASE" class="text-sm font-medium text-primary hover:underline">Voltar para a campanha</Link>
        </div>
        <div class="mb-4">
            <h1 class="flex items-center gap-3 text-2xl font-bold">
                <i class="fab fa-meta text-[#0866FF]" aria-hidden="true"></i>
                {{ soAnuncios ? 'Acrescentar novos anúncios' : (conjunto ? 'Editar conjunto novo' : 'Acrescentar novo conjunto') }}
            </h1>
            <p v-if="soAnuncios" class="mt-1 text-sm text-slate-500">
                No conjunto <strong>{{ conjunto.nome }}</strong> da campanha <strong>{{ c.nome }}</strong>.
                Salvar não manda nada: o envio é na tela da campanha e os anúncios novos nascem ativos, sem mexer nos que já rodam.
            </p>
            <p v-else class="mt-1 text-sm text-slate-500">
                Na campanha <strong>{{ c.nome }}</strong>, que já está na Meta.
                Salvar não manda nada: o envio é na tela da campanha e o conjunto nasce ativo, sem mexer nos outros conjuntos.
            </p>
        </div>

        <form class="space-y-6 lg:max-w-4xl" @submit.prevent="salvar">
            <section v-if="soAnuncios" :class="[cartao, 'space-y-1 p-5 text-sm']">
                <h2 class="text-lg font-semibold">{{ conjunto.nome }}</h2>
                <p class="text-slate-500">
                    {{ conjunto.publico_proprio ? 'Público próprio' : 'Público da campanha' }}: {{ conjunto.publico.idade_min }} a {{ conjunto.publico.idade_max }} anos, {{ generos[conjunto.publico.genero]?.toLowerCase() }}
                    <template v-if="porConjunto"> · {{ formatarReais(conjunto.orcamento_diario) }}/dia</template>
                </p>
                <p v-if="jaNaMeta.length" class="text-slate-500">Já na Meta (não mudam): {{ jaNaMeta.map((v) => v.nome).join(', ') }}.</p>
            </section>

            <section v-else :class="[cartao, 'space-y-4 p-5']">
                <h2 class="text-lg font-semibold">Conjunto</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div :class="porConjunto ? 'sm:col-span-2' : 'sm:col-span-3'">
                        <label for="nome" class="block text-sm font-medium">Nome do conjunto</label>
                        <input id="nome" v-model="form.nome" type="text" maxlength="120" :class="campo" placeholder="Ex.: Ferramentas">
                        <InputError :message="erros.nome" />
                    </div>
                    <div v-if="porConjunto">
                        <label for="orcamento" class="block text-sm font-medium">Orçamento por dia (R$)</label>
                        <input id="orcamento" v-model.number="form.orcamento_diario" type="number" step="0.01" :min="MINIMO" :class="campo">
                        <InputError :message="erros.orcamento_diario" />
                    </div>
                </div>
                <p class="text-xs text-slate-500">
                    <template v-if="porConjunto">A campanha tem orçamento por conjunto: este valor soma ao que ela já gasta, quando você ativar o conjunto.</template>
                    <template v-else>A campanha tem orçamento próprio ({{ formatarReais(Number(c.orcamento_diario)) }}/dia): a Meta divide esse valor entre os conjuntos, o total não muda.</template>
                </p>
                <p v-if="outrosConjuntos.length" class="text-xs text-slate-500">Já na campanha: {{ outrosConjuntos.join(', ') }}.</p>

                <div>
                    <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
                        <input type="checkbox" :checked="!form.publico_proprio" @change="alternarPublico($event.target.checked)">
                        Usar o público da campanha ({{ c.idade_min }} a {{ c.idade_max }} anos, {{ generos[c.genero]?.toLowerCase() }})
                    </label>
                    <div v-if="form.publico_proprio" class="mt-3 space-y-3 rounded-lg bg-slate-500/5 p-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label for="idade-min" class="block text-xs font-medium">Idade mínima</label>
                                <input id="idade-min" v-model.number="form.idade_min" type="number" min="18" max="65" :class="campo">
                            </div>
                            <div>
                                <label for="idade-max" class="block text-xs font-medium">Idade máxima</label>
                                <input id="idade-max" v-model.number="form.idade_max" type="number" min="18" max="65" :class="campo">
                                <InputError :message="erros.idade_max" />
                            </div>
                            <div>
                                <label for="genero" class="block text-xs font-medium">Gênero</label>
                                <select id="genero" v-model="form.genero" :class="campo">
                                    <option v-for="(rotulo, valor) in generos" :key="valor" :value="valor">{{ rotulo }}</option>
                                </select>
                            </div>
                        </div>
                        <BuscaLocal id="local" :local="form.localizacoes" :configurado="config.configurado" />
                    </div>
                </div>
            </section>

            <section :class="[cartao, 'space-y-4 p-5']" aria-labelledby="anuncios-titulo">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="anuncios-titulo" class="text-lg font-semibold">{{ soAnuncios ? 'Anúncios novos' : 'Anúncios' }}</h2>
                    <span class="text-xs text-slate-500">Vídeo MP4/MOV ou imagem JPG/PNG até {{ limites.max_mb }} MB. Campo vazio usa o padrão da campanha.</span>
                </div>
                <p v-if="aviso" class="text-sm text-amber-700" role="alert">{{ aviso }}</p>

                <ol v-if="anuncios.length" class="space-y-2">
                    <li v-for="(a, ai) in anuncios" :key="a.chave" class="rounded-lg border" :class="a.erro ? 'border-red-400' : 'border-[var(--surface-border)]'">
                        <div class="flex items-center gap-3 p-2">
                            <button type="button" class="flex min-w-0 flex-1 items-center gap-3 text-left" :aria-expanded="a.aberto" @click="a.aberto = !a.aberto">
                                <img v-if="a.tipo === 'imagem'" :src="a.previa" alt="" class="h-14 w-11 shrink-0 rounded bg-black object-cover">
                                <video v-else :src="a.previa" class="h-14 w-11 shrink-0 rounded bg-black object-cover" preload="metadata" muted></video>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold" :title="a.nome">{{ a.nome }}</span>
                                    <span class="block truncate text-xs text-slate-500">
                                        {{ ou(a.campos.titulo, c.titulo) || 'Sem título' }} · {{ a.tamanho }}<template v-if="a.formato"> · {{ a.formato }}</template>
                                    </span>
                                </span>
                            </button>
                            <span v-if="a.estado === 'esperando'" class="hidden text-xs text-slate-500 sm:inline">Sobe ao salvar</span>
                            <i v-if="pendenciasDoAnuncio(a).length" class="fas fa-circle text-[8px] text-amber-500" aria-label="Com pendência" role="img"></i>
                            <i v-else class="fas fa-circle text-[8px] text-emerald-500" aria-label="Pronto" role="img"></i>
                            <button type="button" :class="[botaoIcone, 'hover:text-red-600']" :aria-label="`Remover ${a.nome}`" title="Remover" :disabled="salvando" @click="removerAnuncio(ai)">
                                <i class="fas fa-xmark text-xs" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div v-if="a.estado === 'subindo'" class="mx-2 mb-2 h-1.5 overflow-hidden rounded-full bg-slate-500/20" role="progressbar" :aria-valuenow="a.progresso" aria-valuemin="0" aria-valuemax="100" :aria-label="`Enviando ${a.nome}`">
                            <div class="h-full bg-primary transition-all" :style="{ width: `${a.progresso}%` }"></div>
                        </div>

                        <div v-show="a.aberto" class="grid grid-cols-1 gap-3 border-t border-[var(--surface-border)] p-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label :for="`produto-${a.chave}`" class="block text-xs font-medium">Produto</label>
                                <select :id="`produto-${a.chave}`" v-model="a.campos.produto_id" :class="campo" @change="aoEscolherProduto(a)">
                                    <option value="">Nenhum (usa o link padrão)</option>
                                    <option v-for="p in produtos" :key="p.id" :value="p.id">{{ p.nome }}</option>
                                </select>
                            </div>
                            <div v-for="(meta, chave) in {
                                texto_principal: { rotulo: 'Texto principal', padrao: c.texto_principal, area: true },
                                titulo: { rotulo: 'Título', padrao: c.titulo },
                                descricao: { rotulo: 'Descrição', padrao: c.descricao },
                                link_destino: { rotulo: 'Link de destino', padrao: c.link_destino || `${siteUrl}/` },
                            }" :key="chave" :class="meta.area ? 'sm:col-span-2' : ''">
                                <div class="flex items-baseline justify-between gap-2">
                                    <label :for="`${chave}-${a.chave}`" class="block text-xs font-medium">{{ meta.rotulo }}</label>
                                    <span v-if="!preenchido(a.campos[chave]) && preenchido(meta.padrao)" class="text-xs text-slate-400">Padrão da campanha</span>
                                </div>
                                <textarea v-if="meta.area" :id="`${chave}-${a.chave}`" v-model="a.campos[chave]" rows="2" maxlength="2000" :class="campo" :placeholder="meta.padrao || 'Escreva o texto deste anúncio'" />
                                <input v-else :id="`${chave}-${a.chave}`" v-model="a.campos[chave]" :type="chave === 'link_destino' ? 'url' : 'text'" :maxlength="chave === 'link_destino' ? 500 : 255" :class="campo" :placeholder="meta.padrao || (chave === 'descricao' ? 'Opcional' : '')">
                            </div>
                            <div>
                                <label :for="`chamada-${a.chave}`" class="block text-xs font-medium">Botão</label>
                                <select :id="`chamada-${a.chave}`" v-model="a.campos.chamada" :class="campo">
                                    <option value="">Padrão ({{ chamadas[c.chamada] }})</option>
                                    <option v-for="(rotulo, valor) in chamadas" :key="valor" :value="valor">{{ rotulo }}</option>
                                </select>
                            </div>
                            <ul v-if="pendenciasDoAnuncio(a).length || a.erro" class="space-y-0.5 text-xs sm:col-span-2" role="alert">
                                <li v-for="p in pendenciasDoAnuncio(a)" :key="p" class="text-amber-700">{{ p }}</li>
                                <li v-if="a.erro" class="text-red-600">{{ a.erro }}</li>
                            </ul>
                        </div>
                    </li>
                </ol>

                <p v-if="anuncios.length > limites.recomendado_por_conjunto" class="text-xs text-amber-700" role="status">
                    Mais de {{ limites.recomendado_por_conjunto }} anúncios no mesmo conjunto dividem demais a entrega.
                </p>

                <div class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-4 text-center text-sm transition-colors"
                    :class="arrastando ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)]'"
                    @dragover.prevent="arrastando = true" @dragleave.prevent="arrastando = false" @drop.prevent="aoSoltar">
                    <p class="text-slate-500">Solte vídeos ou imagens aqui. Cada arquivo vira um anúncio.</p>
                    <label class="cursor-pointer rounded-lg border border-primary px-4 py-1.5 text-sm font-medium text-primary hover:bg-primary/10">
                        <i class="fas fa-plus text-xs" aria-hidden="true"></i> Anúncio
                        <input type="file" accept="video/mp4,video/quicktime,image/jpeg,image/png" multiple class="sr-only" :disabled="salvando"
                            @change="adicionarArquivos($event.target.files); $event.target.value = ''">
                    </label>
                </div>
            </section>

            <div class="sticky bottom-0 z-20 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)]/95 px-4 py-3 shadow-lg backdrop-blur">
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <p v-if="erroGeral" class="mr-auto text-sm text-red-600" role="alert">{{ erroGeral }}</p>
                    <p v-else-if="pendencias.length" class="mr-auto text-xs text-amber-700" role="status">{{ pendencias[0] }}<template v-if="pendencias.length > 1"> (+{{ pendencias.length - 1 }})</template></p>
                    <p v-else class="mr-auto hidden text-xs text-slate-500 sm:block">Salvar guarda aqui no servidor. Nada vai para a Meta ainda.</p>
                    <Link :href="BASE" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]">Cancelar</Link>
                    <button type="submit" :disabled="salvando || (!soAnuncios && !preenchido(form.nome))"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                        {{ salvando ? 'Salvando…' : 'Salvar e voltar para a campanha' }}
                    </button>
                </div>
            </div>
        </form>
        <AguardeModal :open="salvando" titulo="Aguarde enquanto o anúncio é preparado"
            mensagem="Salvando no servidor. Não feche esta tela; a janela fecha sozinha quando terminar."
            :progresso="arquivos.total ? progressoGeral : null" :detalhe="detalheArquivos" />
    </AdminLayout>
</template>
