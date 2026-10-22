<script setup>
// Promoções › Texto e regras (pedido 2026-10-21). Mesma tela para todas
// (Airfryer, PDFs e as criadas na lista), só muda o slug. Dois blocos: o
// texto da página pública (com prévia dos parágrafos) e as regras de negócio.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    promocao: { type: Object, required: true },
});

const { can } = usePermissions();
const podeEditar = can('cadastros.edit');

const form = useForm({
    titulo: props.promocao.titulo ?? '',
    descricao: props.promocao.descricao ?? '',
    ativa: props.promocao.ativa,
    inicio: props.promocao.inicio ?? '',
    fim: props.promocao.fim ?? '',
    valor_minimo: props.promocao.valor_minimo ?? '',
    itens_minimos: props.promocao.itens_minimos ?? '',
    limite_por_cliente: props.promocao.limite_por_cliente ?? '',
    primeira_compra: props.promocao.primeira_compra,
    acumula_cupom: props.promocao.acumula_cupom,
    observacoes: props.promocao.observacoes ?? '',
    regras: props.promocao.regras.map((r) => ({ ...r })),
});

// Mesma divisão do servidor (Promocao::paragrafos): linha em branco separa parágrafo.
const paragrafos = computed(() => form.descricao
    .replace(/\r\n?/g, '\n')
    .split(/\n[ \t]*\n/)
    .map((p) => p.replace(/^[\n ]+|[\n ]+$/g, ''))
    .filter((p) => p !== ''));

const addRegra = () => form.regras.push({ chave: '', valor: '' });
const tirarRegra = (i) => form.regras.splice(i, 1);
const salvar = () => form.put(`/admin/promocoes/${props.promocao.slug}`, { preserveScroll: true });

const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
const bloco = 'mb-5 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm';
</script>

<template>
    <Head :title="`Texto e regras · ${promocao.nome}`" />

    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <Link href="/admin/promocoes" class="mb-2 inline-block text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Promoções
            </Link>
            <h1 class="mb-1 text-2xl font-bold">Texto e regras · {{ promocao.nome }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                O texto e as regras aparecem na
                <a :href="`/promocoes/${promocao.slug}`" target="_blank" rel="noopener" class="text-primary hover:underline">página da promoção</a>.
                Campo vazio = sem limite.
            </p>
        </div>

        <form class="max-w-3xl" @submit.prevent="salvar">
            <fieldset :disabled="!podeEditar">
                <section :class="bloco">
                    <h2 class="mb-4 text-lg font-semibold">Texto</h2>

                    <div class="mb-4">
                        <label for="pr-titulo" class="mb-1 block text-sm font-medium">Título</label>
                        <input id="pr-titulo" v-model="form.titulo" type="text" maxlength="255" :placeholder="promocao.nome" :class="campo">
                        <InputError :message="form.errors.titulo" />
                    </div>

                    <div class="mb-4">
                        <div class="mb-1 flex items-baseline justify-between">
                            <label for="pr-descricao" class="text-sm font-medium">Descrição</label>
                            <span class="text-xs text-slate-500">{{ form.descricao.length.toLocaleString('pt-BR') }} / 10.000</span>
                        </div>
                        <textarea id="pr-descricao" v-model="form.descricao" rows="10" maxlength="10000" :class="[campo, 'resize-y leading-relaxed']"></textarea>
                        <p class="mt-1 text-xs text-slate-500">Enter quebra a linha. Uma linha em branco começa um parágrafo novo.</p>
                        <InputError :message="form.errors.descricao" />
                    </div>

                    <div v-if="paragrafos.length" class="rounded-lg border border-dashed border-[var(--surface-border)] p-4">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Prévia · {{ paragrafos.length === 1 ? '1 parágrafo' : `${paragrafos.length} parágrafos` }}</p>
                        <p class="mb-2 font-semibold">{{ form.titulo || promocao.nome }}</p>
                        <div class="space-y-3 text-sm leading-relaxed">
                            <p v-for="(paragrafo, i) in paragrafos" :key="i" class="whitespace-pre-line break-words">{{ paragrafo }}</p>
                        </div>
                    </div>
                </section>

                <section :class="bloco">
                    <h2 class="mb-4 text-lg font-semibold">Regras</h2>

                    <label class="mb-4 flex items-center gap-2 text-sm font-medium">
                        <input v-model="form.ativa" type="checkbox"> Promoção ativa
                    </label>

                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-2">
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
                    </div>

                    <div class="mb-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                            <label for="pr-min" class="mb-1 block text-sm font-medium">Pedido mínimo (R$)</label>
                            <input id="pr-min" v-model="form.valor_minimo" type="number" min="0" step="0.01" placeholder="Sem mínimo" :class="campo">
                            <InputError :message="form.errors.valor_minimo" />
                        </div>
                        <div>
                            <label for="pr-itens" class="mb-1 block text-sm font-medium">Itens mínimos no pedido</label>
                            <input id="pr-itens" v-model="form.itens_minimos" type="number" min="1" max="999" step="1" placeholder="Sem mínimo" :class="campo">
                            <InputError :message="form.errors.itens_minimos" />
                        </div>
                        <div>
                            <label for="pr-limite" class="mb-1 block text-sm font-medium">Participações por cliente</label>
                            <input id="pr-limite" v-model="form.limite_por_cliente" type="number" min="1" max="999" step="1" placeholder="Sem limite" :class="campo">
                            <InputError :message="form.errors.limite_por_cliente" />
                        </div>
                    </div>

                    <div class="mb-5 flex flex-col gap-2 text-sm">
                        <label class="flex items-center gap-2">
                            <input v-model="form.primeira_compra" type="checkbox"> Só na primeira compra do cliente
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="form.acumula_cupom" type="checkbox"> Vale junto com cupom de desconto
                        </label>
                    </div>

                    <div class="mb-4">
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-sm font-medium">Outras regras (chave e valor)</span>
                            <button v-if="podeEditar" type="button" class="text-xs text-primary hover:underline" @click="addRegra">
                                <i class="fas fa-plus" aria-hidden="true"></i> Adicionar
                            </button>
                        </div>
                        <p v-if="form.regras.length === 0" class="rounded-lg border border-dashed border-[var(--surface-border)] p-3 text-xs text-slate-500">
                            Nenhuma. Use "Adicionar" para o que não tem campo acima (ex.: <code>categoria</code> = <code>cozinha</code>).
                        </p>
                        <div v-for="(regra, i) in form.regras" :key="i" class="mb-2">
                            <div class="flex gap-2">
                                <input v-model="regra.chave" type="text" maxlength="60" placeholder="chave" :aria-label="`Chave ${i + 1}`" :class="[campo, 'font-mono']">
                                <input v-model="regra.valor" type="text" maxlength="500" placeholder="valor" :aria-label="`Valor ${i + 1}`" :class="campo">
                                <button v-if="podeEditar" type="button" class="rounded-lg border border-[var(--surface-border)] px-3 text-red-600" title="Remover" aria-label="Remover regra" @click="tirarRegra(i)">
                                    <i class="fas fa-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                            <InputError :message="form.errors[`regras.${i}.chave`] || form.errors[`regras.${i}.valor`]" />
                        </div>
                    </div>

                    <div>
                        <label for="pr-obs" class="mb-1 block text-sm font-medium">Observações internas</label>
                        <textarea id="pr-obs" v-model="form.observacoes" rows="3" maxlength="2000" :class="campo"></textarea>
                        <p class="mt-1 text-xs text-slate-500">Só a equipe vê.</p>
                        <InputError :message="form.errors.observacoes" />
                    </div>
                </section>
            </fieldset>

            <button v-if="podeEditar" type="submit" :disabled="form.processing"
                class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-floppy-disk'" aria-hidden="true"></i>
                Salvar
            </button>
        </form>
    </AdminLayout>
</template>
