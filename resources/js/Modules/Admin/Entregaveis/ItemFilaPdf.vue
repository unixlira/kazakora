<script setup>
// Um PDF da fila de brindes (20/11/2026): mostra o status, deixa editar título,
// tipo, páginas, prioridade e briefing, e aprovar ou devolver o PDF entregue.
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete, notifyError, notifySuccess } from '@/Shared/notify';
import { chamar, TONS } from '@/Modules/Admin/Entregaveis/filaPdfs';
import { reactive, ref } from 'vue';

const props = defineProps({
    item: { type: Object, required: true },
    tipos: { type: Object, required: true },
});
const emit = defineEmits(['mudou']);
const { can } = usePermissions();

const editando = ref(false);
const salvando = ref(false);
const form = reactive({});
const devolvendo = ref(false);
const observacao = ref('');

const abrir = () => {
    Object.assign(form, { tipo: props.item.tipo, titulo: props.item.titulo, briefing: props.item.briefing ?? '', paginas: props.item.paginas, prioridade: props.item.prioridade });
    editando.value = true;
};

const executar = async (metodo, url, corpo, mensagem) => {
    salvando.value = true;
    try {
        await chamar(metodo, url, corpo);
        if (mensagem) notifySuccess(mensagem);
        editando.value = false;
        devolvendo.value = false;
        emit('mudou');
    } catch (e) {
        notifyError(e.message);
    } finally {
        salvando.value = false;
    }
};

const salvar = () => executar('PUT', `/admin/entregaveis/fila-pdfs/${props.item.id}`, form, 'PDF da fila atualizado.');
const aprovar = () => executar('POST', `/admin/entregaveis/fila-pdfs/${props.item.id}/aprovar`, null, 'Aprovado: o PDF já aparece na página do produto.');
const devolver = () => executar('POST', `/admin/entregaveis/fila-pdfs/${props.item.id}/devolver`, { observacao: observacao.value }, 'Voltou para a fila com o seu pedido.');
const voltarParaFila = () => executar('PUT', `/admin/entregaveis/fila-pdfs/${props.item.id}`, { ...props.item, briefing: props.item.briefing ?? '', status: 'fila' }, 'Voltou para a fila.');
const excluir = async () => {
    if (await confirmDelete({ title: `Tirar "${props.item.titulo}" da fila?`, text: 'O livro já entregue (se houver) continua em Livros Digitais.' })) {
        executar('DELETE', `/admin/entregaveis/fila-pdfs/${props.item.id}`, null, 'Tirado da fila.');
    }
};

const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <li class="rounded-lg border border-[var(--surface-border)] p-3">
        <div class="flex items-start gap-3">
            <img v-if="item.livro?.capa_url" :src="item.livro.capa_url" alt="" class="h-16 w-12 shrink-0 rounded object-cover">
            <i v-else class="fas w-12 shrink-0 pt-1 text-center text-2xl text-slate-300" :class="item.tipo === 'colorir' ? 'fa-palette' : item.tipo === 'historia' ? 'fa-book-open' : item.tipo === 'receitas' ? 'fa-utensils' : 'fa-file-lines'" aria-hidden="true"></i>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium">{{ item.titulo }}</span>
                    <StatusBadge :tone="TONS[item.status] ?? 'gray'" :label="item.status_nome" />
                </div>
                <p class="text-xs text-slate-500">{{ item.tipo_nome }} · {{ item.paginas }} páginas · prioridade {{ item.prioridade }}<span v-if="item.atualizado"> · {{ item.atualizado }}</span></p>
                <p v-if="item.observacao" class="mt-1 text-xs text-amber-700 dark:text-amber-400"><i class="fas fa-comment-dots mr-1" aria-hidden="true"></i>{{ item.observacao }}</p>
                <a v-if="item.livro" :href="`/admin/entregaveis/livros-digitais/${item.livro.id}/pdf`" class="mt-1 inline-block text-xs text-primary hover:underline">
                    <i class="fas fa-file-pdf mr-1" aria-hidden="true"></i>Baixar o PDF entregue para revisar
                </a>
            </div>
            <div v-if="can('cadastros.edit')" class="flex shrink-0 flex-wrap justify-end gap-1">
                <button v-if="item.status === 'revisao'" type="button" :disabled="salvando" class="rounded-lg bg-emerald-600 px-2 py-1 text-xs font-medium text-white" @click="aprovar">
                    <i class="fas fa-check mr-1" aria-hidden="true"></i>Aprovar
                </button>
                <button v-if="item.livro && item.status !== 'fila'" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" @click="devolvendo = !devolvendo">
                    <i class="fas fa-rotate-left mr-1" aria-hidden="true"></i>Refazer
                </button>
                <button v-if="item.status === 'erro' || item.status === 'produzindo'" type="button" :disabled="salvando" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" @click="voltarParaFila">
                    Voltar para a fila
                </button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs" title="Editar" aria-label="Editar" @click="editando ? (editando = false) : abrir()">
                    <i class="fas fa-pen" aria-hidden="true"></i>
                </button>
                <button v-if="can('cadastros.delete')" type="button" class="rounded-lg border border-[var(--surface-border)] px-2 py-1 text-xs text-red-600" title="Tirar da fila" aria-label="Tirar da fila" @click="excluir">
                    <i class="fas fa-trash" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <form v-if="devolvendo" class="mt-3 space-y-2" @submit.prevent="devolver">
            <label :for="`dev-${item.id}`" class="block text-xs font-medium">O que mudar? (o Homer refaz seguindo isto)</label>
            <textarea :id="`dev-${item.id}`" v-model="observacao" rows="2" :class="campo" placeholder="Ex.: capa mais colorida; trocar o nome da personagem; menos texto por página"></textarea>
            <button type="submit" :disabled="salvando || !observacao.trim()" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">Devolver para refazer</button>
        </form>

        <form v-if="editando" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-4" @submit.prevent="salvar">
            <label class="sm:col-span-4 text-xs font-medium">Título
                <input v-model="form.titulo" type="text" maxlength="255" :class="campo">
            </label>
            <label class="sm:col-span-2 text-xs font-medium">Tipo
                <select v-model="form.tipo" :class="campo">
                    <option v-for="(nome, chave) in tipos" :key="chave" :value="chave">{{ nome }}</option>
                </select>
            </label>
            <label class="text-xs font-medium">Páginas
                <input v-model.number="form.paginas" type="number" min="4" max="80" :class="campo">
            </label>
            <label class="text-xs font-medium">Prioridade (menor sai antes)
                <input v-model.number="form.prioridade" type="number" min="1" max="999" :class="campo">
            </label>
            <label class="sm:col-span-4 text-xs font-medium">Briefing (tema, copy e regras que o Homer segue)
                <textarea v-model="form.briefing" rows="5" maxlength="5000" :class="campo"></textarea>
            </label>
            <div class="sm:col-span-4 flex gap-2">
                <button type="submit" :disabled="salvando" class="rounded-lg bg-primary px-3 py-1.5 text-xs font-medium text-white disabled:opacity-50">Salvar</button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs" @click="editando = false">Cancelar</button>
            </div>
        </form>
    </li>
</template>
