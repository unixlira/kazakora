<script setup>
// Vender › Promoções (pedido 2026-10-21): regras da Promoção Airfryer e da
// Promoção PDFs. Mesma tela para todas (também as criadas na lista), só muda o slug.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    promocao: { type: Object, required: true },
});

const { can } = usePermissions();

const form = useForm({
    titulo: props.promocao.titulo ?? '',
    descricao: props.promocao.descricao ?? '',
    ativa: props.promocao.ativa,
    inicio: props.promocao.inicio ?? '',
    fim: props.promocao.fim ?? '',
    valor_minimo: props.promocao.valor_minimo ?? '',
    observacoes: props.promocao.observacoes ?? '',
    regras: props.promocao.regras.map((r) => ({ ...r })),
});

const addRegra = () => form.regras.push({ chave: '', valor: '' });
const tirarRegra = (i) => form.regras.splice(i, 1);
const salvar = () => form.put(`/admin/promocoes/${props.promocao.slug}`, { preserveScroll: true });

const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <Head :title="promocao.nome" />

    <AdminLayout>
        <div class="mb-6 max-w-2xl">
            <Link href="/admin/promocoes" class="mb-2 inline-block text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Promoções
            </Link>
            <h1 class="mb-1 text-2xl font-bold">{{ promocao.nome }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Título e descrição que o cliente vê na
                <a :href="`/promocoes/${promocao.slug}`" target="_blank" rel="noopener" class="text-primary hover:underline">página da promoção</a>,
                se ela está ativa, o período, o valor mínimo do pedido e as chaves/regras próprias dela.
            </p>
        </div>

        <form class="max-w-3xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm" @submit.prevent="salvar">
            <div class="mb-4">
                <label for="pr-titulo" class="mb-1 block text-sm font-medium">Título</label>
                <input id="pr-titulo" v-model="form.titulo" type="text" maxlength="255" :class="campo">
                <InputError :message="form.errors.titulo" />
            </div>

            <div class="mb-4">
                <label for="pr-descricao" class="mb-1 block text-sm font-medium">Descrição</label>
                <textarea id="pr-descricao" v-model="form.descricao" rows="8" maxlength="10000" :class="[campo, 'resize-y leading-relaxed']"></textarea>
                <p class="mt-1 text-xs text-slate-500">Enter quebra a linha. Uma linha em branco começa um parágrafo novo. A página mostra igual ao que está aqui.</p>
                <InputError :message="form.errors.descricao" />
            </div>

            <label class="mb-4 flex items-center gap-2 text-sm font-medium">
                <input v-model="form.ativa" type="checkbox" :disabled="!can('cadastros.edit')"> Promoção ativa
            </label>

            <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <label for="pr-inicio" class="mb-1 block text-sm font-medium">Início</label>
                    <input id="pr-inicio" v-model="form.inicio" type="datetime-local" :class="campo">
                    <InputError :message="form.errors.inicio" />
                </div>
                <div>
                    <label for="pr-fim" class="mb-1 block text-sm font-medium">Fim</label>
                    <input id="pr-fim" v-model="form.fim" type="datetime-local" :class="campo">
                    <InputError :message="form.errors.fim" />
                </div>
                <div>
                    <label for="pr-min" class="mb-1 block text-sm font-medium">Valor mínimo do pedido (R$)</label>
                    <input id="pr-min" v-model="form.valor_minimo" type="number" min="0" step="0.01" :class="campo">
                    <InputError :message="form.errors.valor_minimo" />
                </div>
            </div>

            <div class="mb-4">
                <div class="mb-1 flex items-center justify-between">
                    <span class="text-sm font-medium">Chaves e regras</span>
                    <button v-if="can('cadastros.edit')" type="button" class="text-xs text-primary hover:underline" @click="addRegra">
                        <i class="fas fa-plus" aria-hidden="true"></i> Adicionar
                    </button>
                </div>
                <p v-if="form.regras.length === 0" class="rounded-lg border border-dashed border-[var(--surface-border)] p-3 text-xs text-slate-500">
                    Nenhuma regra. Use "Adicionar" para criar uma chave (ex.: <code>pedidos_por_cliente</code>) e o valor dela.
                </p>
                <div v-for="(regra, i) in form.regras" :key="i" class="mb-2">
                    <div class="flex gap-2">
                        <input v-model="regra.chave" type="text" maxlength="60" placeholder="chave" :aria-label="`Chave ${i + 1}`" :class="[campo, 'font-mono']">
                        <input v-model="regra.valor" type="text" maxlength="500" placeholder="valor" :aria-label="`Valor ${i + 1}`" :class="campo">
                        <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 text-red-600" title="Remover" aria-label="Remover regra" @click="tirarRegra(i)">
                            <i class="fas fa-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                    <InputError :message="form.errors[`regras.${i}.chave`] || form.errors[`regras.${i}.valor`]" />
                </div>
            </div>

            <div class="mb-4">
                <label for="pr-obs" class="mb-1 block text-sm font-medium">Observações</label>
                <textarea id="pr-obs" v-model="form.observacoes" rows="3" maxlength="2000" :class="campo"></textarea>
                <InputError :message="form.errors.observacoes" />
            </div>

            <button v-if="can('cadastros.edit')" type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-floppy-disk'" aria-hidden="true"></i>
                Salvar
            </button>
        </form>
    </AdminLayout>
</template>
