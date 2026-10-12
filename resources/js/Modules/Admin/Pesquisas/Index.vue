<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import ServerPagination from '@/Shared/Components/ServerPagination.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { usePermissions } from '@/Shared/usePermissions';
import { confirmDelete } from '@/Shared/notify';
import Abas from './Abas.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    pesquisas: { type: Object, required: true },
    filtros: { type: Object, required: true },
    contagem: { type: Object, default: () => ({}) },
    opcoes: { type: Object, required: true },
});

const { can } = usePermissions();

const busca = ref(props.filtros.busca ?? '');
const status = ref(props.filtros.status ?? '');

const nomesAba = { mercado_livre: 'Mercado Livre', shopee: 'Shopee', outras: 'Outras plataformas' };
const plataformaNova = computed(() => (props.filtros.aba === 'outras' ? 'tiktok_shop' : props.filtros.aba));

const filtrar = () => {
    router.get('/admin/pesquisas', {
        aba: props.filtros.aba,
        busca: busca.value || undefined,
        status: status.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

let timer = null;
watch(busca, () => {
    clearTimeout(timer);
    timer = setTimeout(filtrar, 350);
});
watch(status, filtrar);
onBeforeUnmount(() => clearTimeout(timer));

const dataBr = (iso) => (iso ? new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('pt-BR') : '—');

const remover = async (pesquisa) => {
    if (await confirmDelete({ title: `Remover a pesquisa "${pesquisa.titulo}"?`, text: 'Ela vai para a lixeira.' })) {
        router.delete(`/admin/pesquisas/${pesquisa.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Pesquisas de mercado" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">Pesquisas de mercado</h1>
                <p class="mt-1 text-sm text-slate-500">Registre o que você levantou sobre produtos campeões: preço, vendas, concorrente e conclusão.</p>
            </div>
            <Link v-if="can('cadastros.create')" :href="`/admin/pesquisas/nova?plataforma=${plataformaNova}`"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-plus text-xs" aria-hidden="true"></i>
                Nova pesquisa
            </Link>
        </div>

        <Abas :ativa="props.filtros.aba" :contagem="props.contagem" />

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="flex flex-wrap items-center gap-3 border-b border-[var(--surface-border)] p-4">
                <div class="relative w-full max-w-sm">
                    <label for="busca-pesquisa" class="sr-only">Buscar pesquisa</label>
                    <i class="fas fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" aria-hidden="true"></i>
                    <input id="busca-pesquisa" v-model="busca" type="search" autocomplete="off"
                        placeholder="Buscar por título, produto ou vendedor…"
                        class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none">
                </div>
                <div>
                    <label for="status-pesquisa" class="sr-only">Status</label>
                    <select id="status-pesquisa" v-model="status"
                        class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] px-3 py-2 text-sm">
                        <option value="">Todos os status</option>
                        <option v-for="(rotulo, valor) in props.opcoes.status" :key="valor" :value="valor">{{ rotulo }}</option>
                    </select>
                </div>
            </div>

            <div v-if="props.pesquisas.data.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-slate-500">
                            <th scope="col" class="px-4 py-3 font-semibold">Data</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Título</th>
                            <th v-if="props.filtros.aba === 'outras'" scope="col" class="px-4 py-3 font-semibold">Plataforma</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Produtos</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
                            <th scope="col" class="px-4 py-3 font-semibold">Autor</th>
                            <th scope="col" class="px-4 py-3 text-right font-semibold">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pesquisa in props.pesquisas.data" :key="pesquisa.id"
                            class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600">{{ dataBr(pesquisa.data_pesquisa) }}</td>
                            <td class="px-4 py-3">
                                <Link :href="`/admin/pesquisas/${pesquisa.id}`" class="font-medium hover:text-primary hover:underline">
                                    {{ pesquisa.titulo }}
                                </Link>
                            </td>
                            <td v-if="props.filtros.aba === 'outras'" class="px-4 py-3 text-slate-600">{{ pesquisa.plataforma_label }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ pesquisa.itens_count }}</td>
                            <td class="px-4 py-3">
                                <StatusBadge :tone="pesquisa.status === 'concluida' ? 'green' : 'yellow'"
                                    :label="props.opcoes.status[pesquisa.status] ?? pesquisa.status" />
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ pesquisa.autor?.name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <ActionIcon icon="fa-eye" label="Ver" color="slate" :href="`/admin/pesquisas/${pesquisa.id}`" />
                                    <ActionIcon v-if="can('cadastros.edit')" icon="fa-pen" label="Editar" color="blue" :href="`/admin/pesquisas/${pesquisa.id}/editar`" />
                                    <ActionIcon v-if="can('cadastros.delete')" icon="fa-trash" label="Remover" color="red" @click="remover(pesquisa)" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-else class="px-6 py-14 text-center">
                <i class="fas fa-flask mb-3 text-3xl text-slate-300" aria-hidden="true"></i>
                <p class="font-medium">
                    {{ props.filtros.busca || props.filtros.status ? 'Nenhuma pesquisa com esse filtro.' : `Nenhuma pesquisa de ${nomesAba[props.filtros.aba]} ainda.` }}
                </p>
                <p class="mt-1 text-sm text-slate-500">Cada pesquisa guarda os produtos observados e a sua conclusão.</p>
                <Link v-if="can('cadastros.create') && !props.filtros.busca && !props.filtros.status"
                    :href="`/admin/pesquisas/nova?plataforma=${plataformaNova}`"
                    class="mt-4 inline-flex items-center gap-2 rounded-lg border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-primary hover:text-white">
                    <i class="fas fa-plus text-xs" aria-hidden="true"></i>
                    Registrar a primeira
                </Link>
            </div>

            <ServerPagination :paginator="props.pesquisas" embedded />
        </div>
    </AdminLayout>
</template>
