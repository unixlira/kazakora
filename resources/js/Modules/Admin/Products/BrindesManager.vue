<script setup>
// Aba "Brindes (PDF)" do produto (pedido do Lira 20/11/2026): tudo dos PDFs de
// brinde deste produto sem mexer no código. Mostra o que a página exibe hoje,
// liga/desliga livros já cadastrados, sobe um PDF novo e cuida da fila de
// criação (sugerir pelo tema, criar, editar, aprovar, devolver para refazer).
import ItemFilaPdf from '@/Modules/Admin/Entregaveis/ItemFilaPdf.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { notifyError, notifySuccess } from '@/Shared/notify';
import { chamar } from '@/Modules/Admin/Entregaveis/filaPdfs';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';

const props = defineProps({
    product: { type: Object, required: true },
});
const { can } = usePermissions();

const dados = ref(null);
const carregando = ref(true);
const base = `/admin/produtos/${props.product.id}/brindes`;

const carregar = async () => {
    try {
        dados.value = await chamar('GET', base);
    } catch (e) {
        notifyError(e.message);
    } finally {
        carregando.value = false;
    }
};
onMounted(carregar);

const ocupado = ref(false);
const acao = async (metodo, url, corpo, mensagem) => {
    ocupado.value = true;
    try {
        const r = await chamar(metodo, url, corpo);
        notifySuccess(typeof mensagem === 'function' ? mensagem(r) : mensagem);
        await carregar();
    } catch (e) {
        notifyError(e.message);
    } finally {
        ocupado.value = false;
    }
};

const sugerir = () => acao('POST', `${base}/sugerir`, null, (r) => (r.criados ? `${r.criados} PDFs entraram na fila.` : 'A fila já tem os PDFs sugeridos.'));
const ligar = (livro) => acao('POST', `${base}/livros/${livro.id}`, null, `"${livro.titulo}" ligado a este produto.`);
const desligar = (livro) => acao('DELETE', `${base}/livros/${livro.id}`, null, `"${livro.titulo}" desligado deste produto.`);

// Novo item na fila.
const novo = reactive({ tipo: 'guia', titulo: '', briefing: '', paginas: 16, prioridade: 100 });
const abrirNovo = ref(false);
const criar = async () => {
    await acao('POST', `${base}/fila`, novo, 'PDF entrou na fila.');
    Object.assign(novo, { tipo: 'guia', titulo: '', briefing: '', paginas: 16, prioridade: 100 });
    abrirNovo.value = false;
};

// PDF pronto feito fora (Canva, outro computador): sobe direto, ligado a este produto.
const upload = useForm({ titulo: '', pdf: null, capa: null, imagem_quadro: null, ativo: true, todos_produtos: false, produtos: [props.product.parent_product_id ?? props.product.id], voltar: true });
const inputPdf = ref(null);
const inputCapa = ref(null);
const subir = () => upload.post('/admin/entregaveis/livros-digitais', {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
        upload.reset('titulo', 'pdf', 'capa');
        if (inputPdf.value) inputPdf.value.value = '';
        if (inputCapa.value) inputCapa.value.value = '';
        carregar();
    },
});

const usaTodos = computed(() => dados.value && dados.value.naPagina.length && dados.value.naPagina.every((l) => l.todos_produtos));
const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
const cartao = 'rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm';
</script>

<template>
    <div v-if="carregando" class="p-6 text-sm text-slate-500"><i class="fas fa-spinner animate-spin mr-1" aria-hidden="true"></i>Carregando os brindes…</div>

    <div v-else-if="dados" class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <!-- O que a página mostra hoje -->
        <section :class="cartao">
            <h2 class="mb-1 text-lg font-semibold">Na página do produto agora</h2>
            <p class="mb-3 text-sm text-slate-500">
                <template v-if="usaTodos">Este produto não tem livro próprio ativo: aparecem os livros de "todos os produtos".</template>
                <template v-else-if="dados.naPagina.length">Os livros ligados e ativos deste produto.</template>
                <template v-else>Nenhum livro aparece na página deste produto.</template>
            </p>
            <ul class="flex flex-wrap gap-3">
                <li v-for="livro in dados.naPagina" :key="livro.id" class="w-24 text-center text-xs">
                    <img v-if="livro.capa_url" :src="livro.capa_url" alt="" class="mx-auto mb-1 h-32 w-24 rounded object-cover shadow">
                    <i v-else class="fas fa-file-pdf mb-1 block text-4xl text-red-500" aria-hidden="true"></i>
                    <span class="line-clamp-2">{{ livro.titulo }}</span>
                </li>
            </ul>
        </section>

        <!-- Livros ligados / disponíveis -->
        <section :class="cartao">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Livros deste produto</h2>
                <Link href="/admin/entregaveis/livros-digitais" class="text-xs text-primary hover:underline">Todos os livros</Link>
            </div>
            <ul v-if="dados.ligados.length" class="mb-4 space-y-2">
                <li v-for="livro in dados.ligados" :key="livro.id" class="flex items-center gap-3 rounded-lg border border-[var(--surface-border)] p-2" :class="{ 'opacity-60': !livro.ativo }">
                    <img v-if="livro.capa_url" :src="livro.capa_url" alt="" class="h-12 w-9 rounded object-cover">
                    <i v-else class="fas fa-file-pdf w-9 text-center text-xl text-red-500" aria-hidden="true"></i>
                    <span class="min-w-0 flex-1 truncate text-sm">{{ livro.titulo }} <em v-if="!livro.ativo" class="text-xs text-slate-500">(desativado: aprove na fila)</em></span>
                    <button v-if="can('cadastros.edit')" type="button" :disabled="ocupado" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs text-red-600" @click="desligar(livro)">Desligar</button>
                </li>
            </ul>
            <p v-else class="mb-4 text-sm text-slate-500">Nenhum livro ligado só a este produto.</p>

            <details v-if="can('cadastros.edit') && dados.disponiveis.length" class="mb-4">
                <summary class="cursor-pointer text-sm font-medium text-primary">Ligar um livro já cadastrado</summary>
                <ul class="mt-2 max-h-64 space-y-1 overflow-y-auto">
                    <li v-for="livro in dados.disponiveis" :key="livro.id" class="flex items-center gap-2 rounded-lg p-1.5 hover:bg-slate-50 dark:hover:bg-slate-800">
                        <img v-if="livro.capa_url" :src="livro.capa_url" alt="" class="h-10 w-8 rounded object-cover">
                        <span class="min-w-0 flex-1 truncate text-sm">{{ livro.titulo }}<em v-if="livro.todos_produtos" class="text-xs text-slate-500"> · todos os produtos</em></span>
                        <button type="button" :disabled="ocupado" class="rounded-lg bg-primary px-2 py-1 text-xs text-white" @click="ligar(livro)">Ligar</button>
                    </li>
                </ul>
            </details>

            <details v-if="can('cadastros.create')">
                <summary class="cursor-pointer text-sm font-medium text-primary">Subir um PDF pronto para este produto</summary>
                <form class="mt-3 space-y-3" @submit.prevent="subir">
                    <label class="block text-xs font-medium">Título *
                        <input v-model="upload.titulo" type="text" maxlength="255" :class="campo">
                        <InputError :message="upload.errors.titulo" />
                    </label>
                    <label class="block text-xs font-medium">PDF *
                        <input ref="inputPdf" type="file" accept="application/pdf,.pdf" class="mt-1 block w-full text-sm" @input="upload.pdf = $event.target.files[0] ?? null">
                        <InputError :message="upload.errors.pdf" />
                    </label>
                    <label class="block text-xs font-medium">Capa
                        <input ref="inputCapa" type="file" accept="image/*" class="mt-1 block w-full text-sm" @input="upload.capa = $event.target.files[0] ?? null">
                        <InputError :message="upload.errors.capa" />
                    </label>
                    <label class="flex items-center gap-2 text-xs font-medium"><input v-model="upload.ativo" type="checkbox"> Já aparece na página</label>
                    <button type="submit" :disabled="upload.processing" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">
                        {{ upload.processing ? `Enviando… ${upload.progress?.percentage ?? 0}%` : 'Subir PDF' }}
                    </button>
                </form>
            </details>
        </section>

        <!-- Fila de criação -->
        <section :class="cartao" class="xl:col-span-2">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Fila de criação dos PDFs</h2>
                <div class="flex flex-wrap gap-2">
                    <button v-if="can('cadastros.create')" type="button" :disabled="ocupado" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50" @click="sugerir">
                        <i class="fas fa-wand-magic-sparkles mr-1" aria-hidden="true"></i>Sugerir pelo tema do produto
                    </button>
                    <button v-if="can('cadastros.create')" type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs" @click="abrirNovo = !abrirNovo">
                        <i class="fas fa-plus mr-1" aria-hidden="true"></i>PDF avulso
                    </button>
                    <Link href="/admin/entregaveis/fila-pdfs" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs">Fila inteira</Link>
                </div>
            </div>
            <p class="mb-4 text-sm text-slate-500">
                O Homer pega a fila, cria o PDF e a capa e devolve aqui como "Para revisar". O livro só aparece na página depois do seu <b>Aprovar</b>.
                <template v-if="!dados.fila.length"> Sugestão para este produto: {{ dados.sugestoes.map((s) => s.titulo).join(' · ') }}.</template>
            </p>

            <form v-if="abrirNovo" class="mb-4 grid grid-cols-1 gap-2 rounded-lg border border-dashed border-[var(--surface-border)] p-3 sm:grid-cols-4" @submit.prevent="criar">
                <label class="sm:col-span-4 text-xs font-medium">Título
                    <input v-model="novo.titulo" type="text" maxlength="255" required :class="campo">
                </label>
                <label class="sm:col-span-2 text-xs font-medium">Tipo
                    <select v-model="novo.tipo" :class="campo">
                        <option v-for="(nome, chave) in dados.tipos" :key="chave" :value="chave">{{ nome }}</option>
                    </select>
                </label>
                <label class="text-xs font-medium">Páginas
                    <input v-model.number="novo.paginas" type="number" min="4" max="80" :class="campo">
                </label>
                <label class="text-xs font-medium">Prioridade
                    <input v-model.number="novo.prioridade" type="number" min="1" max="999" :class="campo">
                </label>
                <label class="sm:col-span-4 text-xs font-medium">Briefing (tema, público, copy)
                    <textarea v-model="novo.briefing" rows="4" maxlength="5000" :class="campo"></textarea>
                </label>
                <div class="sm:col-span-4"><button type="submit" :disabled="ocupado" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white">Pôr na fila</button></div>
            </form>

            <ul v-if="dados.fila.length" class="space-y-2">
                <ItemFilaPdf v-for="item in dados.fila" :key="item.id" :item="item" :tipos="dados.tipos" @mudou="carregar" />
            </ul>
            <p v-else class="text-sm text-slate-400">Nenhum PDF na fila deste produto.</p>
        </section>
    </div>
</template>
