<script setup>
// Promoções (pedido 2026-10-21): tela própria, link direto no menu (antes
// submenu de Vender), com "Adicionar Promoção" no topo e um card por
// promoção. Airfryer e PDFs vêm primeiro, lado a lado; cada uma tem a chave "mostrar na página do produto", a imagem
// do quadro (a de Entregáveis, uma pronta ou enviada, com tamanho e formato)
// e os produtos onde aparece, cada um com a sua chave. Texto, período e
// regras continuam na tela de cada promoção. As criadas pelo botão não têm
// quadro na página do produto: o card mostra só status, período e regras.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import BuscaProduto from '@/Modules/Admin/Entregaveis/BuscaProduto.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    promocoes: { type: Array, default: () => [] },
    formatos: { type: Array, default: () => [] },
    maxKb: { type: Number, default: 5120 },
});

const { can } = usePermissions();
const podeEditar = can('cadastros.edit');

const ICONES = { airfryer: 'fa-fan', pdfs: 'fa-file-pdf' };
const QUADRO = { airfryer: 'Quadro do sorteio', pdfs: 'Quadro dos livros digitais' };

const porSlug = (slug) => props.promocoes.find((p) => p.slug === slug);
const comQuadro = () => props.promocoes.filter((p) => p.quadro);

const modoSalvo = (promocao) => {
    if (!promocao.imagem) return { modo: 'padrao', caminho: '' };
    return promocao.enviada ? { modo: 'enviada', caminho: '' } : { modo: 'pronta', caminho: promocao.imagem };
};

const estado = reactive(Object.fromEntries(comQuadro().map((p) => [p.slug, {
    ...modoSalvo(p), arquivo: null, previa: null, medida: null, salvando: false, erro: '',
}])));
const inputs = ref({});

const formatosTexto = props.formatos.filter((f) => f !== 'jpeg').map((f) => ({ jpg: 'JPG', webp: 'WebP' }[f] ?? f.toUpperCase())).join(', ');
const maxMb = Math.round(props.maxKb / 1024);
const tamanho = (bytes) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB` : `${Math.round(bytes / 1024)} KB`);

const trocarPrevia = (slug, url) => {
    if (estado[slug].previa) URL.revokeObjectURL(estado[slug].previa);
    estado[slug].previa = url;
};

const urlEscolhida = (slug) => {
    const promocao = porSlug(slug);
    const atual = estado[slug];
    if (atual.modo === 'arquivo') return atual.previa;
    if (atual.modo === 'enviada') return promocao.imagem_url;
    if (atual.modo === 'pronta') return promocao.prontas.find((p) => p.caminho === atual.caminho)?.url ?? null;
    return promocao.padrao_url;
};

// Medida real da imagem escolhida e aviso quando foge do tamanho recomendado.
const medir = (slug, bytes = null) => {
    estado[slug].medida = null;
    const url = urlEscolhida(slug);
    if (!url) return;
    const img = new Image();
    img.onload = () => {
        const rec = porSlug(slug).recomendado;
        const alvo = rec.largura / rec.altura;
        estado[slug].medida = {
            largura: img.naturalWidth,
            altura: img.naturalHeight,
            bytes,
            proporcaoErrada: Math.abs(img.naturalWidth / img.naturalHeight - alvo) / alvo > 0.1,
            pequena: img.naturalWidth < rec.largura / 2,
        };
    };
    img.src = url;
};

onMounted(() => comQuadro().forEach((p) => medir(p.slug)));
onUnmounted(() => comQuadro().forEach((p) => trocarPrevia(p.slug, null)));

// Depois de salvar o Inertia manda os dados novos: o seletor volta para o salvo.
watch(() => props.promocoes, (novas) => novas.filter((p) => p.quadro).forEach((p) => {
    estado[p.slug] ??= { previa: null, medida: null, salvando: false, erro: '' };
    Object.assign(estado[p.slug], modoSalvo(p), { arquivo: null });
    trocarPrevia(p.slug, null);
    medir(p.slug);
}));

const escolher = (slug, modo, caminho = '') => {
    Object.assign(estado[slug], { modo, caminho, arquivo: null, erro: '' });
    trocarPrevia(slug, null);
    medir(slug);
};

const aoEscolherArquivo = (slug, event) => {
    const arquivo = event.target.files[0];
    event.target.value = '';
    if (!arquivo) return;
    estado[slug].erro = '';
    const extensao = arquivo.name.split('.').pop().toLowerCase();
    if (!props.formatos.includes(extensao)) {
        estado[slug].erro = `Formato .${extensao} não serve. Use ${formatosTexto}.`;
        return;
    }
    if (arquivo.size > props.maxKb * 1024) {
        estado[slug].erro = `A imagem tem ${tamanho(arquivo.size)}; o máximo é ${maxMb} MB.`;
        return;
    }
    Object.assign(estado[slug], { modo: 'arquivo', caminho: '', arquivo });
    trocarPrevia(slug, URL.createObjectURL(arquivo));
    medir(slug, arquivo.size);
};

const mudouImagem = (slug) => {
    const salvo = modoSalvo(porSlug(slug));
    const atual = estado[slug];
    return atual.modo === 'arquivo' || atual.modo !== salvo.modo || atual.caminho !== salvo.caminho;
};

const opcoes = (slug, extra = {}) => ({
    preserveScroll: true,
    onStart: () => { estado[slug].salvando = true; estado[slug].erro = ''; },
    onError: (erros) => { estado[slug].erro = Object.values(erros)[0] ?? 'Não deu para salvar.'; },
    onFinish: () => { estado[slug].salvando = false; },
    ...extra,
});

const salvarImagem = (slug) => {
    const atual = estado[slug];
    const dados = atual.modo === 'arquivo'
        ? { imagem: atual.arquivo }
        : { imagem_pronta: atual.modo === 'pronta' ? atual.caminho : '' };
    router.post(`/admin/promocoes/${slug}/imagem`, dados, opcoes(slug, { forceFormData: true }));
};

const mudarChave = (slug, campo, valor) => router.patch(`/admin/promocoes/${slug}/pagina-produto`, { [campo]: valor }, opcoes(slug));

const adicionarProduto = (slug, produto) => router.post(`/admin/promocoes/${slug}/produtos`, { product_id: produto.id }, opcoes(slug));
const alternarProduto = (slug, produto) => router.patch(`/admin/promocoes/${slug}/produtos/${produto.ligacao_id}`, {}, opcoes(slug));
const removerProduto = async (slug, produto) => {
    if (await confirmDelete({ title: `Tirar "${produto.nome}" da lista?`, text: 'O produto volta a seguir a regra geral da promoção.' })) {
        router.delete(`/admin/promocoes/${slug}/produtos/${produto.ligacao_id}`, opcoes(slug));
    }
};

const miniatura = 'relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border-2 bg-slate-50 dark:bg-slate-800';
const marcada = (sim) => (sim ? 'border-primary ring-2 ring-primary/30' : 'border-[var(--surface-border)] hover:border-primary/60');
const etiqueta = 'absolute inset-x-0 bottom-0 bg-black/60 text-center text-[10px] font-semibold text-white';
</script>

<template>
    <Head title="Promoções" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-3xl">
                <h1 class="mb-1 text-2xl font-bold">Promoções</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Os quadros da página do produto: ligue ou desligue cada promoção, escolha a imagem e em quais produtos ela aparece.
                    Texto, período e regras ficam em "Texto e regras" de cada uma.
                </p>
            </div>
            <Link v-if="podeEditar" href="/admin/promocoes/nova"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-plus" aria-hidden="true"></i>Adicionar Promoção
            </Link>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <template v-for="promocao in promocoes" :key="promocao.slug">
            <!-- Promoção criada pelo "Adicionar Promoção": sem quadro na página do produto -->
            <section v-if="!promocao.quadro"
                class="flex flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-semibold">
                            <i class="fas fa-tags text-primary" aria-hidden="true"></i>
                            {{ promocao.nome }}
                        </h2>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            Página pública <StatusBadge :tone="promocao.ativa ? 'green' : 'gray'" :label="promocao.ativa ? 'Ativa' : 'Inativa'" />
                            <template v-if="promocao.ativa && !promocao.no_ar"><span aria-hidden="true">·</span> fora do período</template>
                        </p>
                    </div>
                    <Link :href="`/admin/promocoes/${promocao.slug}`" class="text-sm text-primary hover:underline">
                        <i class="fas fa-pen-to-square mr-1" aria-hidden="true"></i>Texto e regras
                    </Link>
                </header>

                <p v-if="promocao.titulo" class="mt-4 text-sm font-medium">{{ promocao.titulo }}</p>
                <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt class="text-slate-500">Período</dt>
                    <dd>
                        <template v-if="promocao.inicio || promocao.fim">{{ promocao.inicio ?? 'sem início' }} até {{ promocao.fim ?? 'sem fim' }}</template>
                        <template v-else>Sem limite</template>
                    </dd>
                    <dt class="text-slate-500">Pedido mínimo</dt>
                    <dd>{{ promocao.valor_minimo ? `R$ ${Number(promocao.valor_minimo).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}` : 'Sem mínimo' }}</dd>
                    <dt class="text-slate-500">Regras</dt>
                    <dd>{{ promocao.regras === 0 ? 'Nenhuma' : promocao.regras === 1 ? '1 regra' : `${promocao.regras} regras` }}</dd>
                </dl>

                <a :href="`/promocoes/${promocao.slug}`" target="_blank" rel="noopener" class="mt-4 inline-block text-xs text-primary hover:underline">
                    <i class="fas fa-arrow-up-right-from-square mr-1" aria-hidden="true"></i>Ver a página da promoção
                </a>
            </section>

            <section v-else
                class="flex flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="flex items-center gap-2 text-lg font-semibold">
                            <i class="fas text-primary" :class="ICONES[promocao.slug]" aria-hidden="true"></i>
                            {{ promocao.nome }}
                        </h2>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            {{ QUADRO[promocao.slug] }}
                            <span aria-hidden="true">·</span>
                            Página pública <StatusBadge :tone="promocao.ativa ? 'green' : 'gray'" :label="promocao.ativa ? 'Ativa' : 'Inativa'" />
                        </p>
                    </div>
                    <Link :href="`/admin/promocoes/${promocao.slug}`" class="text-sm text-primary hover:underline">
                        <i class="fas fa-pen-to-square mr-1" aria-hidden="true"></i>Texto e regras
                    </Link>
                </header>

                <!-- Chave geral da página do produto -->
                <div class="mt-4 flex items-center justify-between gap-3 rounded-lg border border-[var(--surface-border)] px-3 py-2.5">
                    <span class="text-sm font-medium">Mostrar na página do produto</span>
                    <button type="button" role="switch" :aria-checked="promocao.na_pagina_produto"
                        :aria-label="`${promocao.nome}: mostrar na página do produto`"
                        :disabled="!podeEditar || estado[promocao.slug].salvando"
                        class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-50"
                        :class="promocao.na_pagina_produto ? 'bg-emerald-600' : 'bg-slate-300 dark:bg-slate-600'"
                        @click="mudarChave(promocao.slug, 'na_pagina_produto', !promocao.na_pagina_produto)">
                        <span class="absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all"
                            :class="promocao.na_pagina_produto ? 'left-[22px]' : 'left-0.5'"></span>
                    </button>
                </div>

                <div :class="{ 'opacity-60': !promocao.na_pagina_produto }">
                    <!-- Imagem do quadro -->
                    <div class="mt-4 flex h-44 items-center justify-center overflow-hidden rounded-lg border border-[var(--surface-border)] bg-slate-100 dark:bg-slate-800">
                        <img v-if="urlEscolhida(promocao.slug)" :src="urlEscolhida(promocao.slug)" :alt="`Imagem do ${QUADRO[promocao.slug].toLowerCase()}`"
                            class="max-h-full max-w-full object-contain">
                        <span v-else class="text-sm text-slate-400">Sem imagem</span>
                    </div>

                    <p class="mt-3 text-xs font-semibold uppercase text-slate-500">Imagem do quadro</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" :class="[miniatura, marcada(estado[promocao.slug].modo === 'padrao')]" :disabled="!podeEditar"
                            title="A cadastrada em Entregáveis" @click="escolher(promocao.slug, 'padrao')">
                            <img v-if="promocao.padrao_url" :src="promocao.padrao_url" alt="" class="h-full w-full object-cover">
                            <span :class="etiqueta">Padrão</span>
                        </button>
                        <button v-for="pronta in promocao.prontas" :key="pronta.caminho" type="button" :disabled="!podeEditar"
                            :class="[miniatura, marcada(estado[promocao.slug].modo === 'pronta' && estado[promocao.slug].caminho === pronta.caminho)]"
                            :title="`${pronta.nome} · ${pronta.largura} × ${pronta.altura} px`" :aria-label="pronta.nome"
                            @click="escolher(promocao.slug, 'pronta', pronta.caminho)">
                            <img :src="pronta.url" alt="" class="h-full w-full object-cover" loading="lazy">
                        </button>
                        <button v-if="promocao.enviada" type="button" :class="[miniatura, marcada(estado[promocao.slug].modo === 'enviada')]"
                            :disabled="!podeEditar" title="Imagem enviada" @click="escolher(promocao.slug, 'enviada')">
                            <img :src="promocao.imagem_url" alt="" class="h-full w-full object-cover">
                            <span :class="etiqueta">Enviada</span>
                        </button>
                        <span v-if="estado[promocao.slug].modo === 'arquivo'" :class="[miniatura, marcada(true)]" title="Nova imagem (falta salvar)">
                            <img :src="estado[promocao.slug].previa" alt="" class="h-full w-full object-cover">
                            <span :class="etiqueta">Nova</span>
                        </span>
                        <button v-if="podeEditar" type="button"
                            :class="[miniatura, 'border-dashed border-[var(--surface-border)] text-slate-400 hover:border-primary hover:text-primary']"
                            title="Enviar imagem do computador" @click="inputs[promocao.slug].click()">
                            <span class="text-center text-[11px] leading-tight"><i class="fas fa-upload mb-1 block text-base" aria-hidden="true"></i>Enviar</span>
                        </button>
                        <input :ref="(el) => { inputs[promocao.slug] = el; }" type="file" class="hidden"
                            :accept="formatos.map((f) => `.${f}`).join(',')" @change="aoEscolherArquivo(promocao.slug, $event)">
                    </div>

                    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs text-slate-500">
                        <dt class="font-semibold">Recomendado</dt>
                        <dd>{{ promocao.recomendado.largura }} × {{ promocao.recomendado.altura }} px ({{ promocao.recomendado.proporcao }})</dd>
                        <dt class="font-semibold">Formato</dt>
                        <dd>{{ formatosTexto }}, até {{ maxMb }} MB</dd>
                        <template v-if="estado[promocao.slug].medida">
                            <dt class="font-semibold">Escolhida</dt>
                            <dd :class="{ 'text-amber-700 dark:text-amber-400': estado[promocao.slug].medida.proporcaoErrada || estado[promocao.slug].medida.pequena }">
                                {{ estado[promocao.slug].medida.largura }} × {{ estado[promocao.slug].medida.altura }} px<span v-if="estado[promocao.slug].medida.bytes">, {{ tamanho(estado[promocao.slug].medida.bytes) }}</span>
                                <span v-if="estado[promocao.slug].medida.proporcaoErrada">: proporção diferente, vai sobrar ou cortar</span>
                                <span v-else-if="estado[promocao.slug].medida.pequena">: pequena, pode ficar borrada</span>
                            </dd>
                        </template>
                    </dl>

                    <div v-if="podeEditar" class="mt-3 flex items-center gap-3">
                        <button type="button" :disabled="!mudouImagem(promocao.slug) || estado[promocao.slug].salvando"
                            class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                            @click="salvarImagem(promocao.slug)">
                            <i class="fas mr-1" :class="estado[promocao.slug].salvando ? 'fa-spinner animate-spin' : 'fa-floppy-disk'" aria-hidden="true"></i>
                            Salvar imagem
                        </button>
                        <span v-if="mudouImagem(promocao.slug)" class="text-xs text-amber-700 dark:text-amber-400">Imagem alterada, falta salvar.</span>
                    </div>
                    <InputError :message="estado[promocao.slug].erro" />

                    <!-- O que o quadro mostra (cadastro de Entregáveis) -->
                    <div class="mt-5 border-t border-[var(--surface-border)] pt-4">
                        <p class="text-xs font-semibold uppercase text-slate-500">No quadro (Entregáveis)</p>
                        <ul v-if="promocao.entregaveis.itens.length" class="mt-2 space-y-1 text-sm">
                            <li v-for="item in promocao.entregaveis.itens" :key="item">
                                <i class="fas fa-check mr-1 text-emerald-600" aria-hidden="true"></i>{{ item }}
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-sm text-amber-700 dark:text-amber-400">Nada ativo em Entregáveis: o quadro não aparece.</p>
                        <Link :href="promocao.entregaveis.link" class="mt-1 inline-block text-xs text-primary hover:underline">Abrir em Entregáveis</Link>
                    </div>

                    <!-- Produtos -->
                    <div class="mt-5 border-t border-[var(--surface-border)] pt-4">
                        <p class="text-xs font-semibold uppercase text-slate-500">Em quais produtos</p>
                        <div class="mt-2 flex flex-wrap gap-4 text-sm" role="radiogroup" :aria-label="`${promocao.nome}: em quais produtos`">
                            <label class="flex items-center gap-2">
                                <input type="radio" :name="`todos-${promocao.slug}`" :checked="promocao.todos_produtos" :disabled="!podeEditar"
                                    @change="mudarChave(promocao.slug, 'todos_produtos', true)"> Todos os produtos
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="radio" :name="`todos-${promocao.slug}`" :checked="!promocao.todos_produtos" :disabled="!podeEditar"
                                    @change="mudarChave(promocao.slug, 'todos_produtos', false)"> Só os ligados abaixo
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ promocao.todos_produtos
                                ? 'Aparece em todos. Desligue a chave de um produto da lista para tirar só dele.'
                                : 'Aparece só nos produtos da lista com a chave ligada.' }}
                        </p>

                        <BuscaProduto v-if="podeEditar" :id="`busca-${promocao.slug}`" class="mt-3"
                            @escolher="(produto) => adicionarProduto(promocao.slug, produto)" />

                        <ul v-if="promocao.produtos.length" class="mt-3 divide-y divide-[var(--surface-border)] rounded-lg border border-[var(--surface-border)]">
                            <li v-for="produto in promocao.produtos" :key="produto.ligacao_id" class="flex items-center gap-3 px-3 py-2">
                                <img v-if="produto.foto" :src="produto.foto" alt="" class="h-9 w-9 rounded object-cover">
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm">{{ produto.nome }}</div>
                                    <div class="font-mono text-xs text-slate-500">{{ produto.sku }}</div>
                                </div>
                                <button type="button" role="switch" :aria-checked="produto.ativo"
                                    :aria-label="`${promocao.nome} em ${produto.nome}`" :disabled="!podeEditar || estado[promocao.slug].salvando"
                                    class="relative inline-flex h-5 w-9 shrink-0 rounded-full transition-colors disabled:opacity-50"
                                    :class="produto.ativo ? 'bg-emerald-600' : 'bg-slate-300 dark:bg-slate-600'"
                                    @click="alternarProduto(promocao.slug, produto)">
                                    <span class="absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all"
                                        :class="produto.ativo ? 'left-[18px]' : 'left-0.5'"></span>
                                </button>
                                <button v-if="podeEditar" type="button" class="px-1 text-slate-400 hover:text-red-600"
                                    :aria-label="`Tirar ${produto.nome} da lista`" title="Tirar da lista" @click="removerProduto(promocao.slug, produto)">
                                    <i class="fas fa-xmark" aria-hidden="true"></i>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="mt-3 rounded-lg border border-dashed border-[var(--surface-border)] p-3 text-xs text-slate-500">
                            {{ promocao.todos_produtos ? 'Nenhuma exceção: aparece em todos os produtos.' : 'Nenhum produto na lista: o quadro não aparece em lugar nenhum.' }}
                        </p>
                    </div>
                </div>
            </section>
            </template>
        </div>
    </AdminLayout>
</template>
