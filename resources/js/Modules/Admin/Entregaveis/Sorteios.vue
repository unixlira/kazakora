<script setup>
// Entregáveis › Sorteios (pedido 2026-10-20): produto onde o sorteio aparece
// (ou todos), foto do prêmio, título e dois parágrafos. Ativa e desativa a
// qualquer momento.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import InputError from '@/Shared/Components/InputError.vue';
import BuscaProduto from '@/Modules/Admin/Entregaveis/BuscaProduto.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    sorteios: { type: Array, default: () => [] },
    // Condições da Promoção Airfryer: com elas, o quadro mostra este texto no lugar dos parágrafos.
    condicoesPromocao: { type: String, default: null },
});

const { can } = usePermissions();

const vazio = () => ({ product_id: null, titulo: '', paragrafo_1: '', paragrafo_2: '', imagem: null, ativo: true });
const form = useForm(vazio());
const editando = ref(null);
const produtoEscolhido = ref(null);
const inputImagem = ref(null);

const escolher = (produto) => {
    produtoEscolhido.value = produto;
    form.product_id = produto.id;
};
const todosOsProdutos = () => {
    produtoEscolhido.value = null;
    form.product_id = null;
};

// Prévia da imagem escolhida (ou a atual, na edição).
const previaLocal = ref(null);
watch(() => form.imagem, (arquivo) => {
    if (previaLocal.value) URL.revokeObjectURL(previaLocal.value);
    previaLocal.value = arquivo ? URL.createObjectURL(arquivo) : null;
});
onUnmounted(() => previaLocal.value && URL.revokeObjectURL(previaLocal.value));
const previaImagem = computed(() => previaLocal.value ?? editando.value?.imagem_url ?? null);

const editar = (sorteio) => {
    editando.value = sorteio;
    produtoEscolhido.value = sorteio.produto;
    form.defaults({ product_id: sorteio.product_id, titulo: sorteio.titulo, paragrafo_1: sorteio.paragrafo_1, paragrafo_2: sorteio.paragrafo_2 ?? '', imagem: null, ativo: sorteio.ativo });
    form.reset();
    form.clearErrors();
    if (inputImagem.value) inputImagem.value.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cancelar = () => {
    editando.value = null;
    produtoEscolhido.value = null;
    form.defaults(vazio());
    form.reset();
    form.clearErrors();
    if (inputImagem.value) inputImagem.value.value = '';
};

const salvar = () => {
    const opcoes = { preserveScroll: true, forceFormData: true, onSuccess: cancelar };
    form.transform((data) => ({ ...data, ...(editando.value ? { _method: 'put' } : {}) }));
    form.post(editando.value ? `/admin/entregaveis/sorteios/${editando.value.id}` : '/admin/entregaveis/sorteios', opcoes);
};

const alternar = (sorteio) => router.patch(`/admin/entregaveis/sorteios/${sorteio.id}/ativo`, {}, { preserveScroll: true });

const excluir = async (sorteio) => {
    if (await confirmDelete({ title: `Excluir o sorteio "${sorteio.titulo}"?` })) {
        router.delete(`/admin/entregaveis/sorteios/${sorteio.id}`, { preserveScroll: true });
    }
};

const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <Head title="Sorteios" />

    <AdminLayout>
        <div class="mb-6 max-w-2xl">
            <!-- Fora do menu desde 2026-10-21: abre pelo card em Vender › Promoções. -->
            <Link href="/admin/promocoes" class="mb-2 inline-block text-sm text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Promoções
            </Link>
            <h1 class="mb-1 text-2xl font-bold">Sorteios</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Banner de sorteio na página do produto: foto do prêmio, título e dois parágrafos. Escolha o produto (ou todos) e ative ou desative quando quiser.
            </p>
            <p v-if="condicoesPromocao" class="mt-3 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                <i class="fas fa-circle-info mr-1" aria-hidden="true"></i>
                O quadro está mostrando as condições da Promoção Airfryer ("{{ condicoesPromocao }}") no lugar dos parágrafos daqui.
                Para mudar o texto, o botão ou a imagem, use <Link href="/admin/promocoes" class="font-semibold underline">Promoções</Link>.
            </p>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <form v-if="can(editando ? 'cadastros.edit' : 'cadastros.create')" @submit.prevent="salvar"
                class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm xl:col-span-1">
                <h2 class="mb-4 text-lg font-semibold">{{ editando ? 'Editar sorteio' : 'Novo sorteio' }}</h2>

                <div class="mb-4">
                    <label for="so-busca" class="mb-1 block text-sm font-medium">Produto onde aparece</label>
                    <div v-if="produtoEscolhido" class="flex items-center gap-3 rounded-lg border border-[var(--surface-border)] p-2">
                        <img v-if="produtoEscolhido.foto" :src="produtoEscolhido.foto" alt="" class="h-10 w-10 rounded object-cover">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">{{ produtoEscolhido.nome }}</div>
                            <div class="font-mono text-xs text-slate-500">{{ produtoEscolhido.sku }}</div>
                        </div>
                        <button type="button" class="text-xs text-primary hover:underline" @click="todosOsProdutos">Trocar</button>
                    </div>
                    <template v-else>
                        <BuscaProduto id="so-busca" @escolher="escolher" />
                        <p class="mt-1 text-xs text-slate-500">Sem produto escolhido, o sorteio aparece em <b>todos os produtos</b>.</p>
                    </template>
                    <InputError :message="form.errors.product_id" />
                </div>

                <div class="mb-4">
                    <label for="so-imagem" class="mb-1 block text-sm font-medium">Imagem do prêmio {{ editando ? '' : '*' }}</label>
                    <img v-if="previaImagem" :src="previaImagem" alt="" class="mb-2 h-28 w-auto rounded-lg object-contain">
                    <input id="so-imagem" ref="inputImagem" type="file" accept="image/*" class="block w-full text-sm" @input="form.imagem = $event.target.files[0] ?? null">
                    <p class="mt-1 text-xs text-slate-400">JPG, PNG ou WebP, até 5 MB. Fica à direita do texto (em pé funciona melhor).</p>
                    <InputError :message="form.errors.imagem" />
                </div>

                <div class="mb-4">
                    <label for="so-titulo" class="mb-1 block text-sm font-medium">Título *</label>
                    <input id="so-titulo" v-model="form.titulo" type="text" maxlength="255" :class="campo" placeholder="Ex.: Compre e concorra a uma AirFryer!">
                    <InputError :message="form.errors.titulo" />
                </div>

                <div class="mb-4">
                    <label for="so-p1" class="mb-1 block text-sm font-medium">1º parágrafo *</label>
                    <textarea id="so-p1" v-model="form.paragrafo_1" rows="3" maxlength="2000" :class="campo"></textarea>
                    <InputError :message="form.errors.paragrafo_1" />
                </div>

                <div class="mb-4">
                    <label for="so-p2" class="mb-1 block text-sm font-medium">2º parágrafo</label>
                    <textarea id="so-p2" v-model="form.paragrafo_2" rows="3" maxlength="2000" :class="campo"></textarea>
                    <InputError :message="form.errors.paragrafo_2" />
                </div>

                <label class="mb-4 flex items-center gap-2 text-sm font-medium">
                    <input v-model="form.ativo" type="checkbox"> Aparece na página do produto (ativo)
                </label>

                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-gift'" aria-hidden="true"></i>
                        {{ editando ? 'Salvar alterações' : 'Cadastrar sorteio' }}
                    </button>
                    <button v-if="editando" type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm" @click="cancelar">Cancelar</button>
                </div>
            </form>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm xl:col-span-2">
                <h2 class="p-5 pb-0 text-lg font-semibold">Sorteios cadastrados</h2>
                <div class="overflow-x-auto p-5">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase text-slate-400">
                                <th scope="col" class="pb-2 pr-4">Sorteio</th>
                                <th scope="col" class="pb-2 pr-4">Produto</th>
                                <th scope="col" class="pb-2 pr-4">Situação</th>
                                <th scope="col" class="pb-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.sorteios.length === 0">
                                <td colspan="4" class="py-8 text-center text-slate-400">Nenhum sorteio. Cadastre o primeiro ao lado.</td>
                            </tr>
                            <tr v-for="sorteio in props.sorteios" :key="sorteio.id" class="border-b border-[var(--surface-border)] align-top last:border-0"
                                :class="{ 'opacity-60': !sorteio.ativo }">
                                <td class="py-3 pr-4">
                                    <div class="flex items-start gap-3">
                                        <img :src="sorteio.imagem_url" alt="" class="h-14 w-14 shrink-0 rounded object-cover">
                                        <div class="min-w-0">
                                            <div class="max-w-sm font-medium">{{ sorteio.titulo }}</div>
                                            <p class="max-w-sm text-xs text-slate-500">{{ sorteio.paragrafo_1 }}</p>
                                            <p v-if="sorteio.paragrafo_2" class="max-w-sm text-xs text-slate-500">{{ sorteio.paragrafo_2 }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <span v-if="sorteio.produto" class="block max-w-xs truncate">{{ sorteio.produto.nome }}</span>
                                    <span v-else class="font-medium">Todos os produtos</span>
                                </td>
                                <td class="py-3 pr-4">
                                    <StatusBadge :tone="sorteio.ativo ? 'green' : 'gray'" :label="sorteio.ativo ? 'Ativo' : 'Desativado'" />
                                </td>
                                <td class="py-3">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" title="Editar" aria-label="Editar" @click="editar(sorteio)">
                                            <i class="fas fa-pen" aria-hidden="true"></i>
                                        </button>
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs"
                                            :title="sorteio.ativo ? 'Desativar' : 'Ativar'" :aria-label="sorteio.ativo ? 'Desativar' : 'Ativar'" @click="alternar(sorteio)">
                                            <i class="fas" :class="sorteio.ativo ? 'fa-eye-slash text-amber-600' : 'fa-eye text-emerald-600'" aria-hidden="true"></i>
                                        </button>
                                        <button v-if="can('cadastros.delete')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs text-red-600" title="Excluir" aria-label="Excluir" @click="excluir(sorteio)">
                                            <i class="fas fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
