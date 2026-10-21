<script setup>
// Entregáveis › Livros Digitais (pedido 2026-10-20): sobe o PDF, dá o título
// e escolhe se aparece na página do produto e em quais produtos (todos ou os
// escolhidos). Desativado some da página na hora, sem apagar o PDF.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import InputError from '@/Shared/Components/InputError.vue';
import BuscaProduto from '@/Modules/Admin/Entregaveis/BuscaProduto.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    livros: { type: Array, default: () => [] },
    emailPedido: { type: Boolean, default: false },
});

const { can } = usePermissions();

const vazio = () => ({ titulo: '', pdf: null, capa: null, remover_capa: false, imagem_quadro: null, remover_imagem_quadro: false, ativo: true, todos_produtos: true, produtos: [] });
const form = useForm(vazio());
const editando = ref(null);
const escolhidos = ref([]);
const inputPdf = ref(null);
const inputCapa = ref(null);
const inputQuadro = ref(null);

const adicionar = (produto) => {
    if (!escolhidos.value.some((p) => p.id === produto.id)) escolhidos.value.push(produto);
};
const remover = (produto) => {
    escolhidos.value = escolhidos.value.filter((p) => p.id !== produto.id);
};

const limparArquivos = () => {
    if (inputPdf.value) inputPdf.value.value = '';
    if (inputCapa.value) inputCapa.value.value = '';
    if (inputQuadro.value) inputQuadro.value.value = '';
};

const editar = (livro) => {
    editando.value = livro;
    escolhidos.value = [...livro.produtos];
    form.defaults({ titulo: livro.titulo, pdf: null, capa: null, remover_capa: false, imagem_quadro: null, remover_imagem_quadro: false, ativo: livro.ativo, todos_produtos: livro.todos_produtos, produtos: [] });
    form.reset();
    form.clearErrors();
    limparArquivos();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cancelar = () => {
    editando.value = null;
    escolhidos.value = [];
    form.defaults(vazio());
    form.reset();
    form.clearErrors();
    limparArquivos();
};

const salvar = () => {
    const opcoes = { preserveScroll: true, forceFormData: true, onSuccess: cancelar };
    form.transform((data) => ({
        ...data,
        produtos: data.todos_produtos ? [] : escolhidos.value.map((p) => p.id),
        ...(editando.value ? { _method: 'put' } : {}),
    }));
    form.post(editando.value ? `/admin/entregaveis/livros-digitais/${editando.value.id}` : '/admin/entregaveis/livros-digitais', opcoes);
};

// Chave da promoção (pedido 2026-10-21): livros ativos anexados no e-mail de confirmação do pedido.
const alternarEmail = () => router.patch('/admin/entregaveis/livros-digitais/email-pedido', {}, { preserveScroll: true });

const alternar = (livro) => router.patch(`/admin/entregaveis/livros-digitais/${livro.id}/ativo`, {}, { preserveScroll: true });

const excluir = async (livro) => {
    if (await confirmDelete({ title: `Excluir o livro "${livro.titulo}"?`, text: 'O PDF também é apagado.' })) {
        router.delete(`/admin/entregaveis/livros-digitais/${livro.id}`, { preserveScroll: true });
    }
};

const tamanho = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <Head title="Livros Digitais" />

    <AdminLayout>
        <div class="mb-6 max-w-2xl">
            <h1 class="mb-1 text-2xl font-bold">Livros Digitais</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                PDFs de brinde que aparecem na página do produto ("Compre hoje e receba … livros digitais grátis"). Ative ou desative a qualquer momento e escolha em quais produtos cada livro aparece.
            </p>
        </div>

        <div class="mb-6 flex max-w-2xl items-start gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
            <div class="min-w-0 flex-1">
                <h2 id="ld-email-titulo" class="text-base font-semibold">Enviar os livros no e-mail de confirmação do pedido</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Ligada: o e-mail de pedido confirmado do kazakora.com leva uma mensagem de presente e os PDFs dos livros ativos dos produtos comprados. Desligada: o e-mail sai sem os livros.
                </p>
            </div>
            <button type="button" role="switch" :aria-checked="props.emailPedido" aria-labelledby="ld-email-titulo" :disabled="!can('cadastros.edit')"
                class="relative mt-1 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                :class="props.emailPedido ? 'bg-emerald-600' : 'bg-slate-300 dark:bg-slate-600'" @click="alternarEmail">
                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform" :class="props.emailPedido ? 'translate-x-5' : 'translate-x-0.5'"></span>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
            <form v-if="can(editando ? 'cadastros.edit' : 'cadastros.create')" @submit.prevent="salvar"
                class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm xl:col-span-1">
                <h2 class="mb-4 text-lg font-semibold">{{ editando ? 'Editar livro' : 'Novo livro digital' }}</h2>

                <div class="mb-4">
                    <label for="ld-titulo" class="mb-1 block text-sm font-medium">Título do livro *</label>
                    <input id="ld-titulo" v-model="form.titulo" type="text" maxlength="255" :class="campo" placeholder="Ex.: 8 passos para acabar com dores nas costas">
                    <InputError :message="form.errors.titulo" />
                </div>

                <div class="mb-4">
                    <label for="ld-pdf" class="mb-1 block text-sm font-medium">Arquivo PDF {{ editando ? '' : '*' }}</label>
                    <p v-if="editando" class="mb-1 text-xs text-slate-500">Atual: {{ editando.nome_arquivo }}. Escolha outro só se for trocar.</p>
                    <input id="ld-pdf" ref="inputPdf" type="file" accept="application/pdf,.pdf" class="block w-full text-sm" @input="form.pdf = $event.target.files[0] ?? null">
                    <p class="mt-1 text-xs text-slate-400">Até 50 MB.</p>
                    <InputError :message="form.errors.pdf" />
                </div>

                <div class="mb-4">
                    <label for="ld-capa" class="mb-1 block text-sm font-medium">Capa (opcional)</label>
                    <div v-if="editando?.capa_url && !form.remover_capa" class="mb-2 flex items-center gap-3">
                        <img :src="editando.capa_url" alt="" class="h-16 w-12 rounded object-cover">
                        <button type="button" class="text-xs text-red-600 hover:underline" @click="form.remover_capa = true">Remover capa</button>
                    </div>
                    <input id="ld-capa" ref="inputCapa" type="file" accept="image/*" class="block w-full text-sm" @input="form.capa = $event.target.files[0] ?? null">
                    <p class="mt-1 text-xs text-slate-400">Imagem da capa na página do produto. Sem capa, aparece só o título.</p>
                    <InputError :message="form.errors.capa" />
                </div>

                <div class="mb-4">
                    <label for="ld-quadro" class="mb-1 block text-sm font-medium">Imagem do quadro (tablets)</label>
                    <div v-if="editando?.imagem_quadro_url && !form.remover_imagem_quadro" class="mb-2 flex items-center gap-3">
                        <img :src="editando.imagem_quadro_url" alt="" class="h-12 w-24 rounded object-contain">
                        <button type="button" class="text-xs text-red-600 hover:underline" @click="form.remover_imagem_quadro = true">Remover imagem</button>
                    </div>
                    <input id="ld-quadro" ref="inputQuadro" type="file" accept="image/*" class="block w-full text-sm" @input="form.imagem_quadro = $event.target.files[0] ?? null">
                    <p class="mt-1 text-xs text-slate-400">Imagem que aparece no quadro da página do produto; vale a do primeiro livro do grupo.</p>
                    <InputError :message="form.errors.imagem_quadro" />
                </div>

                <fieldset class="mb-4">
                    <legend class="mb-1 text-sm font-medium">Em quais produtos aparece</legend>
                    <label class="mb-1 flex items-center gap-2 text-sm">
                        <input v-model="form.todos_produtos" type="radio" :value="true"> Todos os produtos
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="form.todos_produtos" type="radio" :value="false"> Só nos produtos escolhidos
                    </label>
                    <div v-if="!form.todos_produtos" class="mt-3">
                        <BuscaProduto id="ld-busca" @escolher="adicionar" />
                        <ul v-if="escolhidos.length" class="mt-2 space-y-1">
                            <li v-for="p in escolhidos" :key="p.id" class="flex items-center gap-2 rounded-lg border border-[var(--surface-border)] p-1.5">
                                <img v-if="p.foto" :src="p.foto" alt="" class="h-8 w-8 rounded object-cover">
                                <span class="min-w-0 flex-1 truncate text-sm">{{ p.nome }}</span>
                                <button type="button" class="px-1 text-xs text-red-600" :aria-label="`Tirar ${p.nome}`" @click="remover(p)">
                                    <i class="fas fa-xmark" aria-hidden="true"></i>
                                </button>
                            </li>
                        </ul>
                        <p v-else class="mt-2 text-xs text-slate-400">Nenhum produto escolhido.</p>
                    </div>
                    <InputError :message="form.errors.produtos" />
                </fieldset>

                <label class="mb-4 flex items-center gap-2 text-sm font-medium">
                    <input v-model="form.ativo" type="checkbox"> Aparece na página do produto (ativo)
                </label>

                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-book-open'" aria-hidden="true"></i>
                        {{ editando ? 'Salvar alterações' : 'Cadastrar livro' }}
                    </button>
                    <button v-if="editando" type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm" @click="cancelar">Cancelar</button>
                </div>
                <p v-if="form.progress" class="mt-2 text-xs text-slate-500">Enviando… {{ form.progress.percentage }}%</p>
            </form>

            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm xl:col-span-2">
                <h2 class="p-5 pb-0 text-lg font-semibold">Livros cadastrados</h2>
                <div class="overflow-x-auto p-5">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase text-slate-400">
                                <th scope="col" class="pb-2 pr-4">Livro</th>
                                <th scope="col" class="pb-2 pr-4">Aparece em</th>
                                <th scope="col" class="pb-2 pr-4">Situação</th>
                                <th scope="col" class="pb-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="props.livros.length === 0">
                                <td colspan="4" class="py-8 text-center text-slate-400">Nenhum livro digital. Cadastre o primeiro ao lado.</td>
                            </tr>
                            <tr v-for="livro in props.livros" :key="livro.id" class="border-b border-[var(--surface-border)] align-top last:border-0"
                                :class="{ 'opacity-60': !livro.ativo }">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-3">
                                        <img v-if="livro.capa_url" :src="livro.capa_url" alt="" class="h-14 w-10 shrink-0 rounded object-cover">
                                        <i v-else class="fas fa-file-pdf w-10 shrink-0 text-center text-2xl text-red-500" aria-hidden="true"></i>
                                        <div class="min-w-0">
                                            <div class="max-w-xs truncate font-medium">{{ livro.titulo }}</div>
                                            <a :href="`/admin/entregaveis/livros-digitais/${livro.id}/pdf`" class="text-xs text-primary hover:underline">
                                                {{ livro.nome_arquivo }} · {{ tamanho(livro.tamanho) }}
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <span v-if="livro.todos_produtos" class="font-medium">Todos os produtos</span>
                                    <ul v-else class="space-y-0.5">
                                        <li v-for="p in livro.produtos" :key="p.id" class="max-w-xs truncate">{{ p.nome }}</li>
                                    </ul>
                                </td>
                                <td class="py-3 pr-4">
                                    <StatusBadge :tone="livro.ativo ? 'green' : 'gray'" :label="livro.ativo ? 'Ativo' : 'Desativado'" />
                                </td>
                                <td class="py-3">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" title="Editar" aria-label="Editar" @click="editar(livro)">
                                            <i class="fas fa-pen" aria-hidden="true"></i>
                                        </button>
                                        <button v-if="can('cadastros.edit')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs"
                                            :title="livro.ativo ? 'Desativar' : 'Ativar'" :aria-label="livro.ativo ? 'Desativar' : 'Ativar'" @click="alternar(livro)">
                                            <i class="fas" :class="livro.ativo ? 'fa-eye-slash text-amber-600' : 'fa-eye text-emerald-600'" aria-hidden="true"></i>
                                        </button>
                                        <button v-if="can('cadastros.delete')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs text-red-600" title="Excluir" aria-label="Excluir" @click="excluir(livro)">
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
