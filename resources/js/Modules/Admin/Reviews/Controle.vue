<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import SolicitacaoPosCompra from './Partials/SolicitacaoPosCompra.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, h, nextTick, ref } from 'vue';

// Avaliações › Cadastro e controle (pedido do Lira 2026-10-18).
const props = defineProps({
    produtos: { type: Array, default: () => [] },
    ultimaLeitura: { type: String, default: null },
});

const { can } = usePermissions();

const form = useForm({
    product_id: '',
    reviewer_name: '',
    rating: 5,
    comment: '',
    data: '',
    midias: [],
});

const buscaProduto = ref('');
const produtosFiltrados = computed(() => {
    const termo = buscaProduto.value.trim().toLowerCase();
    if (!termo) return props.produtos;
    return props.produtos.filter((p) => p.nome.toLowerCase().includes(termo) || (p.sku ?? '').toLowerCase().includes(termo));
});

const previas = ref([]);
const inputArquivos = ref(null);
const ehVideo = (arquivo) => arquivo.type.startsWith('video/') || /\.(mp4|mov|webm)$/i.test(arquivo.name);

const escolherArquivos = (event) => {
    const novos = Array.from(event.target.files ?? []);
    form.midias = [...form.midias, ...novos].slice(0, 6);
    previas.value = form.midias.map((arquivo) => ({ url: URL.createObjectURL(arquivo), video: ehVideo(arquivo), nome: arquivo.name }));
    event.target.value = '';
};
const removerArquivo = (indice) => {
    form.midias = form.midias.filter((_, i) => i !== indice);
    previas.value = previas.value.filter((_, i) => i !== indice);
};

const formulario = ref(null);
const adicionarPara = async (produto) => {
    buscaProduto.value = '';
    form.product_id = produto.id;
    await nextTick();
    formulario.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const submit = () => {
    form.post('/admin/avaliacoes/controle', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('reviewer_name', 'comment', 'data', 'midias');
            form.rating = 5;
            previas.value = [];
        },
    });
};

const atualizando = ref(false);
const atualizarContagem = () => {
    router.post('/admin/avaliacoes/controle/atualizar-contagem', {}, {
        preserveScroll: true,
        onStart: () => { atualizando.value = true; },
        onFinish: () => { atualizando.value = false; },
    });
};

const formatarData = (valor) => (valor ? new Date(valor.replace(' ', 'T')).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : 'nunca');

// Quantas reais da Shopee/ML ainda não estão no site (as importadas daquele canal).
const faltam = (p) => ['shopee', 'mercado_livre'].reduce((soma, canal) => soma + Math.max(0, (p[canal].total ?? 0) - p.site[canal]), 0);

const totais = computed(() => props.produtos.reduce((t, p) => ({
    shopee: t.shopee + (p.shopee.total ?? 0),
    mercado_livre: t.mercado_livre + (p.mercado_livre.total ?? 0),
    site: t.site + p.site.total,
    semAvaliacao: t.semAvaliacao + (p.site.total === 0 ? 1 : 0),
}), { shopee: 0, mercado_livre: 0, site: 0, semAvaliacao: 0 }));

const celulaCanal = (info) => {
    if (!info.anuncios.length) return h('span', { class: 'text-slate-400', title: 'Sem anúncio vinculado neste canal' }, 'sem anúncio');
    if (info.total === null) return h('span', { class: 'text-slate-400', title: 'Contagem ainda não lida' }, 'não lido');
    return h('div', { class: 'leading-tight' }, [
        h('span', { class: 'font-semibold' }, String(info.total)),
        info.nota ? h('span', { class: 'ml-1 text-xs text-amber-500' }, [`${String(info.nota).replace('.', ',')} `, h('i', { class: 'fas fa-star' })]) : null,
        h('span', { class: 'block text-xs text-slate-400' }, `${info.anuncios.length} anúncio(s)${info.sem_leitura ? `, ${info.sem_leitura} sem leitura` : ''}`),
    ]);
};

const columns = [
    {
        id: 'produto',
        header: 'Produto',
        accessorFn: (row) => `${row.nome} ${row.sku ?? ''}`,
        cell: ({ row }) => h('div', { class: 'max-w-sm' }, [
            h('span', { class: 'line-clamp-2 font-medium' }, row.original.nome),
            row.original.sku ? h('span', { class: 'block text-xs text-slate-400' }, row.original.sku) : null,
        ]),
    },
    { id: 'shopee', header: 'Shopee (reais)', accessorFn: (row) => row.shopee.total ?? -1, cell: ({ row }) => celulaCanal(row.original.shopee) },
    { id: 'mercado_livre', header: 'Mercado Livre (reais)', accessorFn: (row) => row.mercado_livre.total ?? -1, cell: ({ row }) => celulaCanal(row.original.mercado_livre) },
    {
        id: 'site',
        header: 'No site',
        accessorFn: (row) => row.site.total,
        cell: ({ row }) => {
            const s = row.original.site;
            const partes = [
                s.shopee && `${s.shopee} Shopee`,
                s.mercado_livre && `${s.mercado_livre} ML`,
                s.manual && `${s.manual} manual`,
                s.clientes && `${s.clientes} clientes do site`,
                s.outros && `${s.outros} outros canais`,
            ].filter(Boolean);
            return h('div', { class: 'leading-tight' }, [
                h('span', { class: ['font-semibold', s.total === 0 ? 'text-red-500' : ''] }, String(s.total)),
                partes.length ? h('span', { class: 'block text-xs text-slate-400' }, partes.join(' · ')) : null,
            ]);
        },
    },
    {
        id: 'faltam',
        header: 'Faltam no site',
        accessorFn: (row) => faltam(row),
        cell: ({ row }) => {
            const n = faltam(row.original);
            return h('span', { class: n > 0 ? 'font-semibold text-amber-600' : 'text-slate-400', title: 'Reais na Shopee/ML que ainda não foram importadas para o site' }, n > 0 ? String(n) : '0');
        },
    },
    {
        id: 'acoes',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex justify-end gap-2' }, [
            row.original.slug ? h('a', { href: `/produtos/${row.original.slug}`, target: '_blank', rel: 'noopener', class: 'text-xs text-slate-500 hover:underline' }, 'Ver na loja') : null,
            can('cadastros.create') ? h('button', { type: 'button', class: 'text-xs font-medium text-primary hover:underline', onClick: () => adicionarPara(row.original) }, 'Adicionar avaliação') : null,
        ]),
    },
];
</script>

<template>
    <Head title="Cadastro e controle de avaliações" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Cadastro e controle de avaliações</h1>
            <Link href="/admin/avaliacoes" class="text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1"></i> Todas as avaliações
            </Link>
        </div>

        <form v-if="can('cadastros.create')" ref="formulario"
            class="mb-8 grid gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm lg:grid-cols-2"
            enctype="multipart/form-data" @submit.prevent="submit">
            <div class="lg:col-span-2">
                <h2 class="text-lg font-semibold">Adicionar avaliação</h2>
                <p class="text-sm text-slate-500">
                    Só avaliação real de cliente (recebida por WhatsApp, e-mail ou em outro canal). Ela aparece na página do produto com o nome informado.
                </p>
            </div>

            <div class="lg:col-span-2">
                <label for="produto" class="block text-sm font-medium">Produto</label>
                <div class="mt-1 grid gap-2 sm:grid-cols-[14rem_1fr]">
                    <input v-model="buscaProduto" type="search" placeholder="Filtrar por nome ou SKU"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <select id="produto" v-model="form.product_id" required
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                        <option value="" disabled>Escolha o produto ({{ produtosFiltrados.length }})</option>
                        <option v-for="p in produtosFiltrados" :key="p.id" :value="p.id">{{ p.nome }}{{ p.sku ? ` (${p.sku})` : '' }}</option>
                    </select>
                </div>
                <InputError :message="form.errors.product_id" />
            </div>

            <div>
                <label for="reviewer_name" class="block text-sm font-medium">Nome do cliente</label>
                <input id="reviewer_name" v-model="form.reviewer_name" type="text" maxlength="255" required
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2">
                <InputError :message="form.errors.reviewer_name" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <span class="block text-sm font-medium">Nota</span>
                    <div class="mt-1 flex items-center gap-1" role="radiogroup" aria-label="Nota">
                        <button v-for="estrela in 5" :key="estrela" type="button" role="radio" :aria-checked="form.rating === estrela"
                            :aria-label="`${estrela} estrela(s)`" @click="form.rating = estrela">
                            <i class="text-2xl" :class="estrela <= form.rating ? 'fas fa-star text-amber-400' : 'far fa-star text-slate-300 dark:text-slate-600'"></i>
                        </button>
                    </div>
                    <InputError :message="form.errors.rating" />
                </div>
                <div>
                    <label for="data" class="block text-sm font-medium">Data da avaliação</label>
                    <input id="data" v-model="form.data" type="date" :max="new Date().toISOString().slice(0, 10)"
                        class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <InputError :message="form.errors.data" />
                </div>
            </div>

            <div class="lg:col-span-2">
                <label for="comment" class="block text-sm font-medium">Texto da avaliação</label>
                <textarea id="comment" v-model="form.comment" rows="4" maxlength="1000"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] px-3 py-2"></textarea>
                <span class="text-xs text-slate-400">{{ form.comment.length }}/1000</span>
                <InputError :message="form.errors.comment" />
            </div>

            <div class="lg:col-span-2">
                <span class="block text-sm font-medium">Fotos e vídeos do cliente</span>
                <p class="text-xs text-slate-500">Até 6 arquivos: JPG, PNG, WEBP, MP4, MOV ou WEBM, até 50 MB cada.</p>
                <div class="mt-2 flex flex-wrap gap-3">
                    <div v-for="(previa, indice) in previas" :key="previa.url" class="relative h-24 w-24 overflow-hidden rounded-lg border border-[var(--surface-border)]">
                        <video v-if="previa.video" :src="previa.url" class="h-full w-full object-cover" muted></video>
                        <img v-else :src="previa.url" alt="" class="h-full w-full object-cover">
                        <i v-if="previa.video" class="fas fa-play absolute bottom-1 left-1 text-xs text-white drop-shadow"></i>
                        <button type="button" class="absolute right-1 top-1 rounded-full bg-black/60 px-1.5 text-xs text-white" :aria-label="`Remover ${previa.nome}`" @click="removerArquivo(indice)">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>
                    <button v-if="form.midias.length < 6" type="button"
                        class="flex h-24 w-24 flex-col items-center justify-center rounded-lg border-2 border-dashed border-[var(--surface-border)] text-xs text-slate-500 hover:border-slate-400"
                        @click="inputArquivos?.click()">
                        <i class="fas fa-camera mb-1 text-lg"></i> Adicionar
                    </button>
                </div>
                <input ref="inputArquivos" type="file" class="hidden" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" @change="escolherArquivos">
                <InputError :message="form.errors.midias" />
                <InputError v-for="(erro, chave) in Object.fromEntries(Object.entries(form.errors).filter(([k]) => k.startsWith('midias.')))" :key="chave" :message="erro" />
                <p v-if="form.progress" class="mt-1 text-xs text-slate-500">Enviando {{ form.progress.percentage }}%</p>
            </div>

            <div class="lg:col-span-2">
                <button type="submit" :disabled="form.processing"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    Salvar avaliação
                </button>
            </div>
        </form>

        <!-- E-mail pedindo avaliação depois da entrega, com cupom (pedido do Lira 2026-10-18). -->
        <SolicitacaoPosCompra class="mb-8" />

        <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Avaliações por produto ativo</h2>
                <p class="text-sm text-slate-500">
                    Reais nos anúncios vinculados da Shopee e do Mercado Livre contra as que aparecem no site (variações somadas).
                    Última leitura dos canais: {{ formatarData(ultimaLeitura) }}.
                </p>
            </div>
            <button type="button" :disabled="atualizando"
                class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm font-medium hover:border-slate-400 disabled:opacity-50"
                @click="atualizarContagem">
                <i class="fas fa-rotate mr-1" :class="{ 'fa-spin': atualizando }"></i> Atualizar contagem
            </button>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Produtos ativos</span>
                <span class="block text-2xl font-bold">{{ produtos.length }}</span>
                <span class="block text-xs text-slate-500">{{ totais.semAvaliacao }} sem avaliação no site</span>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Shopee</span>
                <span class="block text-2xl font-bold">{{ totais.shopee }}</span>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">Mercado Livre</span>
                <span class="block text-2xl font-bold">{{ totais.mercado_livre }}</span>
            </div>
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <span class="block text-xs font-semibold uppercase tracking-wide text-slate-500">No site</span>
                <span class="block text-2xl font-bold">{{ totais.site }}</span>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :data="produtos"
            search-placeholder="Buscar por produto ou SKU..."
            empty-message="Nenhum produto ativo." />
    </AdminLayout>
</template>
