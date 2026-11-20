<script setup>
// Entregáveis › Fila de PDFs (pedido do Lira 20/11/2026): todos os PDFs de
// brinde a criar, por produto. O Homer pega a fila e devolve como "Para
// revisar"; aqui o Lira aprova, devolve com o que mudar, edita ou reordena.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ItemFilaPdf from '@/Modules/Admin/Entregaveis/ItemFilaPdf.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    itens: { type: Array, default: () => [] },
    contagem: { type: Object, default: () => ({}) },
    filtro: { type: String, default: null },
    tipos: { type: Object, default: () => ({}) },
    status: { type: Object, default: () => ({}) },
    semBrinde: { type: Number, default: 0 },
});
const { can } = usePermissions();

// Agrupa por produto, na ordem que veio (revisão primeiro).
const grupos = computed(() => {
    const mapa = new Map();
    for (const item of props.itens) {
        const chave = item.product_id;
        if (!mapa.has(chave)) mapa.set(chave, { produto: item.produto, itens: [] });
        mapa.get(chave).itens.push(item);
    }
    return [...mapa.values()];
});

const recarregar = () => router.reload({ only: ['itens', 'contagem', 'semBrinde'], preserveScroll: true });
const montar = () => router.post('/admin/entregaveis/fila-pdfs/montar', {}, { preserveScroll: true });
const filtrar = (s) => router.get('/admin/entregaveis/fila-pdfs', s ? { status: s } : {}, { preserveState: true });
const total = computed(() => Object.values(props.contagem).reduce((a, b) => a + Number(b), 0));
</script>

<template>
    <Head title="Fila de PDFs" />

    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <Link href="/admin/entregaveis/livros-digitais" class="mb-2 inline-block text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Livros Digitais
            </Link>
            <h1 class="mb-1 text-2xl font-bold">Fila de PDFs de brinde</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Cada produto ganha PDFs no tema dele: brinquedo infantil = 2 histórias + 1 livro de colorir de 30 páginas; travesseiro e coluna = dor nas costas;
                ferramentas = guias da FerraKora; cozinha = receitas; o resto = guia prático. O Homer cria e devolve como <b>Para revisar</b>;
                o PDF só aparece na loja depois do seu <b>Aprovar</b>. Também dá para editar tudo na aba "Brindes (PDF)" de cada produto.
            </p>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <button type="button" class="rounded-full border px-3 py-1 text-xs" :class="!filtro ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)]'" @click="filtrar(null)">
                Todos ({{ total }})
            </button>
            <button v-for="(nome, chave) in status" :key="chave" type="button" class="rounded-full border px-3 py-1 text-xs"
                :class="filtro === chave ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)]'" @click="filtrar(chave)">
                {{ nome }} ({{ contagem[chave] ?? 0 }})
            </button>
            <button v-if="can('cadastros.create') && semBrinde" type="button" class="ml-auto rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white" @click="montar">
                <i class="fas fa-wand-magic-sparkles mr-1" aria-hidden="true"></i>Montar fila dos {{ semBrinde }} produtos sem brinde
            </button>
        </div>

        <p v-if="!grupos.length" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-8 text-center text-sm text-slate-400">Nada na fila.</p>

        <div class="space-y-4">
            <section v-for="grupo in grupos" :key="grupo.produto?.id" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <div class="mb-3 flex items-center gap-3">
                    <img v-if="grupo.produto?.foto" :src="grupo.produto.foto" alt="" class="h-10 w-10 rounded object-cover">
                    <Link :href="`/admin/produtos/${grupo.produto?.id}/edit`" class="min-w-0 flex-1 truncate font-semibold hover:underline">{{ grupo.produto?.nome }}</Link>
                </div>
                <ul class="space-y-2">
                    <ItemFilaPdf v-for="item in grupo.itens" :key="item.id" :item="item" :tipos="tipos" @mudou="recarregar" />
                </ul>
            </section>
        </div>
    </AdminLayout>
</template>
