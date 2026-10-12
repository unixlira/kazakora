<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

// Criar e editar pesquisa de mercado (mesma tela). Os produtos observados
// são salvos junto com a pesquisa, na ordem da tela.
const props = defineProps({
    pesquisa: { type: Object, default: null },
    plataformaInicial: { type: String, default: 'mercado_livre' },
    produtos: { type: Array, default: () => [] },
    opcoes: { type: Object, required: true },
});

const editando = computed(() => Boolean(props.pesquisa?.id));
const hoje = new Date().toLocaleDateString('en-CA', { timeZone: 'America/Sao_Paulo' });

const itemVazio = () => ({
    produto: '', link: '', preco: null, vendas: '', avaliacoes: '', vendedor: '', product_id: null, observacoes: '', nota: null,
});

const form = useForm({
    plataforma: props.pesquisa?.plataforma ?? props.plataformaInicial,
    plataforma_nome: props.pesquisa?.plataforma_nome ?? '',
    titulo: props.pesquisa?.titulo ?? '',
    data_pesquisa: props.pesquisa?.data_pesquisa?.slice(0, 10) ?? hoje,
    status: props.pesquisa?.status ?? 'rascunho',
    resumo: props.pesquisa?.resumo ?? '',
    itens: (props.pesquisa?.itens ?? []).map((item) => ({
        produto: item.produto ?? '',
        link: item.link ?? '',
        preco: item.preco,
        vendas: item.vendas ?? '',
        avaliacoes: item.avaliacoes ?? '',
        vendedor: item.vendedor ?? '',
        product_id: item.product_id,
        observacoes: item.observacoes ?? '',
        nota: item.nota,
    })),
});

if (!form.itens.length) form.itens.push(itemVazio());

const adicionar = () => form.itens.push(itemVazio());
const remover = (indice) => form.itens.splice(indice, 1);
const mover = (indice, passo) => {
    const destino = indice + passo;
    if (destino < 0 || destino >= form.itens.length) return;
    const [item] = form.itens.splice(indice, 1);
    form.itens.splice(destino, 0, item);
};

const erroItem = (indice, campo) => form.errors[`itens.${indice}.${campo}`];

const salvar = () => {
    // Linha de produto deixada em branco sai antes de enviar (assim os
    // erros continuam apontando para a linha certa).
    form.itens = form.itens.filter((item) => Object.values(item).some((v) => v !== '' && v !== null));
    form.transform((dados) => ({
        ...dados,
        plataforma_nome: dados.plataforma === 'outra' ? dados.plataforma_nome : null,
    }));
    if (editando.value) {
        form.put(`/admin/pesquisas/${props.pesquisa.id}`, { preserveScroll: true });
    } else {
        form.post('/admin/pesquisas', { preserveScroll: true });
    }
};

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm focus:border-primary focus:outline-none';
const voltar = computed(() => (editando.value ? `/admin/pesquisas/${props.pesquisa.id}` : '/admin/pesquisas'));
</script>

<template>
    <Head :title="editando ? 'Editar pesquisa' : 'Nova pesquisa'" />

    <AdminLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <Link :href="voltar" class="text-sm text-slate-500 hover:text-primary">
                    <i class="fas fa-arrow-left mr-1 text-xs" aria-hidden="true"></i> Voltar
                </Link>
                <h1 class="mt-1 text-2xl font-bold">{{ editando ? 'Editar pesquisa' : 'Nova pesquisa de mercado' }}</h1>
            </div>
        </div>

        <form class="max-w-5xl space-y-6" @submit.prevent="salvar">
            <section class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" aria-labelledby="titulo-dados">
                <h2 id="titulo-dados" class="text-sm font-semibold uppercase tracking-wide text-slate-500">Dados da pesquisa</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                    <div class="sm:col-span-4">
                        <label for="titulo" class="block text-sm font-medium">Título ou tema</label>
                        <input id="titulo" v-model="form.titulo" type="text" required maxlength="255" autocomplete="off"
                            placeholder="Ex.: Mais vendidos em organizadores de cozinha…" :class="campo">
                        <InputError :message="form.errors.titulo" />
                    </div>
                    <div class="sm:col-span-2">
                        <label for="data_pesquisa" class="block text-sm font-medium">Data da pesquisa</label>
                        <input id="data_pesquisa" v-model="form.data_pesquisa" type="date" required :class="campo">
                        <InputError :message="form.errors.data_pesquisa" />
                    </div>

                    <div class="sm:col-span-2">
                        <label for="plataforma" class="block text-sm font-medium">Plataforma</label>
                        <select id="plataforma" v-model="form.plataforma" required :class="campo">
                            <option v-for="(rotulo, valor) in props.opcoes.plataformas" :key="valor" :value="valor">{{ rotulo }}</option>
                        </select>
                        <InputError :message="form.errors.plataforma" />
                    </div>
                    <div v-if="form.plataforma === 'outra'" class="sm:col-span-2">
                        <label for="plataforma_nome" class="block text-sm font-medium">Qual plataforma?</label>
                        <input id="plataforma_nome" v-model="form.plataforma_nome" type="text" maxlength="80" required autocomplete="off"
                            placeholder="Ex.: Shein, Magalu…" :class="campo">
                        <InputError :message="form.errors.plataforma_nome" />
                    </div>
                    <div class="sm:col-span-2">
                        <label for="status" class="block text-sm font-medium">Status</label>
                        <select id="status" v-model="form.status" required :class="campo">
                            <option v-for="(rotulo, valor) in props.opcoes.status" :key="valor" :value="valor">{{ rotulo }}</option>
                        </select>
                        <InputError :message="form.errors.status" />
                    </div>
                </div>

                <div>
                    <label for="resumo" class="block text-sm font-medium">Resumo e conclusão</label>
                    <textarea id="resumo" v-model="form.resumo" rows="6"
                        placeholder="O que você concluiu: faixa de preço, quem domina, oportunidade, próximo passo…" :class="campo" />
                    <InputError :message="form.errors.resumo" />
                </div>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6 shadow-sm" aria-labelledby="titulo-itens">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 id="titulo-itens" class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                        Produtos observados <span class="tabular-nums">({{ form.itens.length }})</span>
                    </h2>
                    <button type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-primary hover:underline" @click="adicionar">
                        <i class="fas fa-plus text-xs" aria-hidden="true"></i> Adicionar produto
                    </button>
                </div>

                <p v-if="!form.itens.length" class="rounded-lg border border-dashed border-[var(--surface-border)] p-6 text-center text-sm text-slate-500">
                    Nenhum produto. Clique em "Adicionar produto".
                </p>

                <ol class="space-y-4">
                    <li v-for="(item, indice) in form.itens" :key="indice"
                        class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">Produto {{ indice + 1 }}</span>
                            <div class="flex items-center gap-1">
                                <button type="button" class="rounded p-1.5 text-slate-500 hover:text-primary disabled:opacity-30" :disabled="indice === 0"
                                    :aria-label="`Subir produto ${indice + 1}`" @click="mover(indice, -1)">
                                    <i class="fas fa-arrow-up text-xs" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="rounded p-1.5 text-slate-500 hover:text-primary disabled:opacity-30" :disabled="indice === form.itens.length - 1"
                                    :aria-label="`Descer produto ${indice + 1}`" @click="mover(indice, 1)">
                                    <i class="fas fa-arrow-down text-xs" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="rounded p-1.5 text-red-600 hover:bg-red-50"
                                    :aria-label="`Remover produto ${indice + 1}`" @click="remover(indice)">
                                    <i class="fas fa-trash text-xs" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-6">
                            <div class="sm:col-span-4">
                                <label :for="`produto-${indice}`" class="block text-xs font-medium text-slate-600">Nome do produto</label>
                                <input :id="`produto-${indice}`" v-model="item.produto" type="text" required maxlength="255" autocomplete="off" :class="campo">
                                <InputError :message="erroItem(indice, 'produto')" />
                            </div>
                            <div class="sm:col-span-2">
                                <label :for="`preco-${indice}`" class="block text-xs font-medium text-slate-600">Preço observado (R$)</label>
                                <input :id="`preco-${indice}`" v-model.number="item.preco" type="number" inputmode="decimal" step="0.01" min="0" :class="campo">
                                <InputError :message="erroItem(indice, 'preco')" />
                            </div>

                            <div class="sm:col-span-4">
                                <label :for="`link-${indice}`" class="block text-xs font-medium text-slate-600">Link do anúncio</label>
                                <input :id="`link-${indice}`" v-model="item.link" type="url" inputmode="url" maxlength="2048" autocomplete="off" spellcheck="false"
                                    placeholder="https://…" :class="campo">
                                <InputError :message="erroItem(indice, 'link')" />
                            </div>
                            <div class="sm:col-span-2">
                                <label :for="`vendedor-${indice}`" class="block text-xs font-medium text-slate-600">Vendedor ou concorrente</label>
                                <input :id="`vendedor-${indice}`" v-model="item.vendedor" type="text" maxlength="255" autocomplete="off" :class="campo">
                                <InputError :message="erroItem(indice, 'vendedor')" />
                            </div>

                            <div class="sm:col-span-2">
                                <label :for="`vendas-${indice}`" class="block text-xs font-medium text-slate-600">Vendas observadas</label>
                                <input :id="`vendas-${indice}`" v-model="item.vendas" type="text" maxlength="120" autocomplete="off"
                                    placeholder="Ex.: +5 mil vendidos" :class="campo">
                                <InputError :message="erroItem(indice, 'vendas')" />
                            </div>
                            <div class="sm:col-span-2">
                                <label :for="`avaliacoes-${indice}`" class="block text-xs font-medium text-slate-600">Avaliações</label>
                                <input :id="`avaliacoes-${indice}`" v-model="item.avaliacoes" type="text" maxlength="120" autocomplete="off"
                                    placeholder="Ex.: 4,8 (1.200)" :class="campo">
                                <InputError :message="erroItem(indice, 'avaliacoes')" />
                            </div>
                            <div class="sm:col-span-2">
                                <label :for="`nota-${indice}`" class="block text-xs font-medium text-slate-600">Nota (0 a 10, opcional)</label>
                                <input :id="`nota-${indice}`" v-model.number="item.nota" type="number" inputmode="decimal" step="0.5" min="0" max="10" :class="campo">
                                <InputError :message="erroItem(indice, 'nota')" />
                            </div>

                            <div class="sm:col-span-6">
                                <label :for="`product-${indice}`" class="block text-xs font-medium text-slate-600">Produto nosso relacionado (opcional)</label>
                                <select :id="`product-${indice}`" v-model="item.product_id" :class="campo">
                                    <option :value="null">Nenhum</option>
                                    <option v-for="produto in props.produtos" :key="produto.id" :value="produto.id">
                                        {{ produto.name }}{{ produto.sku ? ` (${produto.sku})` : '' }}
                                    </option>
                                </select>
                                <InputError :message="erroItem(indice, 'product_id')" />
                            </div>

                            <div class="sm:col-span-6">
                                <label :for="`observacoes-${indice}`" class="block text-xs font-medium text-slate-600">Observações</label>
                                <textarea :id="`observacoes-${indice}`" v-model="item.observacoes" rows="2" :class="campo" />
                                <InputError :message="erroItem(indice, 'observacoes')" />
                            </div>
                        </div>
                    </li>
                </ol>
                <InputError :message="form.errors.itens" />
            </section>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Salvando…' : (editando ? 'Salvar alterações' : 'Salvar pesquisa') }}
                </button>
                <Link :href="voltar" class="text-sm text-slate-500 hover:text-primary">Cancelar</Link>
            </div>
        </form>
    </AdminLayout>
</template>
