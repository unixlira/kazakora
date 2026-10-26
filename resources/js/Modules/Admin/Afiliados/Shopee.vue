<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

// Afiliados › Shopee (2026-10-26): escolher os produtos que vão ser clonados
// como anúncio de afiliado. Preço do clone = custo × 1,5 (paga 10% ao
// afiliado); SKU do clone = SKU + "-AF". Só guarda a escolha, nada vai para
// a Shopee daqui.
const props = defineProps({
    produtos: { type: Array, default: () => [] },
    grupos: { type: Object, default: () => ({}) },
    contagem: { type: Object, default: () => ({}) },
    filtros: { type: Object, default: () => ({}) },
    totais: { type: Object, default: () => ({ escolhidos: 0, sugeridos: 0 }) },
    regra: { type: Object, default: () => ({ markup: 1.5, comissao: 0.1 }) },
});

const filtros = reactive({ grupo: props.filtros.grupo ?? '', q: props.filtros.q ?? '' });

const buscar = () => {
    router.get('/admin/afiliados/shopee', {
        grupo: filtros.grupo || undefined,
        q: filtros.q || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
};

const verGrupo = (grupo) => {
    filtros.grupo = grupo;
    filtros.q = '';
    buscar();
};

const escolher = (campos) => {
    router.post('/admin/afiliados/shopee/escolher', campos, { preserveScroll: true, preserveState: true });
};

const grupoAberto = computed(() => (filtros.grupo && props.grupos[filtros.grupo] ? filtros.grupo : null));

const dinheiro = (valor) => (valor === null || valor === undefined
    ? '—'
    : Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }));

const porcento = (valor) => `${Math.round(valor * 100)}%`;
</script>

<template>
    <Head title="Afiliados · Shopee" />

    <AdminLayout>
        <div class="mb-6 max-w-3xl">
            <h1 class="mb-1 text-2xl font-bold">Afiliados · Shopee</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Escolha os produtos que vão ser clonados como anúncio de afiliado na Shopee.
                Preço do anúncio = custo × {{ String(props.regra.markup).replace('.', ',') }}, para pagar
                {{ porcento(props.regra.comissao) }} de comissão ao afiliado. O SKU do anúncio leva "-AF" no fim.
                Aqui só fica guardada a escolha: nada é criado na Shopee.
            </p>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
            <CardStats stat-subtitle="ESCOLHIDOS" :stat-title="String(props.totais.escolhidos)" stat-icon-name="fas fa-check" variant="primary" compact />
            <CardStats stat-subtitle="SUGERIDOS PELO LIRA" :stat-title="String(props.totais.sugeridos)" stat-icon-name="fas fa-list-check" variant="info" compact />
        </div>

        <div class="mb-4 flex flex-wrap gap-2">
            <button type="button" class="rounded-full border px-3 py-1.5 text-sm"
                :class="!filtros.grupo && !filtros.q ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] bg-[var(--surface)]'"
                @click="verGrupo('')">
                Todos os sugeridos
            </button>
            <button v-for="(rotulo, chave) in props.grupos" :key="chave" type="button" class="rounded-full border px-3 py-1.5 text-sm"
                :class="filtros.grupo === chave ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] bg-[var(--surface)]'"
                @click="verGrupo(chave)">
                {{ rotulo }} <span class="opacity-70">({{ props.contagem[chave] ?? 0 }})</span>
            </button>
            <button type="button" class="rounded-full border px-3 py-1.5 text-sm"
                :class="filtros.grupo === 'escolhidos' ? 'border-primary bg-primary text-white' : 'border-[var(--surface-border)] bg-[var(--surface)]'"
                @click="verGrupo('escolhidos')">
                Só os escolhidos <span class="opacity-70">({{ props.totais.escolhidos }})</span>
            </button>
        </div>

        <form class="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="buscar">
            <div class="min-w-64 flex-1">
                <label for="af-q" class="block text-xs font-medium text-slate-500 dark:text-slate-400">Buscar em todo o catálogo</label>
                <input id="af-q" v-model="filtros.q" type="search" autocomplete="off" placeholder="Nome ou SKU…"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
            </div>
            <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                <i class="fas fa-magnifying-glass mr-1" aria-hidden="true"></i> Buscar
            </button>
            <template v-if="grupoAberto">
                <button type="button" class="rounded-lg border border-primary px-4 py-2 text-sm font-medium text-primary"
                    @click="escolher({ grupo: grupoAberto, escolhido: true })">
                    Marcar todo o grupo
                </button>
                <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm"
                    @click="escolher({ grupo: grupoAberto, escolhido: false })">
                    Desmarcar o grupo
                </button>
            </template>
        </form>

        <div class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-500 dark:text-slate-400">
                        <tr>
                            <th scope="col" class="px-3 py-3 text-center">Clonar</th>
                            <th scope="col" class="px-3 py-3">Produto</th>
                            <th scope="col" class="px-3 py-3">Grupo</th>
                            <th scope="col" class="px-3 py-3 text-right">Custo</th>
                            <th scope="col" class="px-3 py-3 text-right">Preço hoje</th>
                            <th scope="col" class="px-3 py-3 text-right">Preço afiliado</th>
                            <th scope="col" class="px-3 py-3 text-right">Comissão {{ porcento(props.regra.comissao) }}</th>
                            <th scope="col" class="px-3 py-3">Shopee hoje</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <tr v-for="p in props.produtos" :key="p.id" class="hover:bg-[var(--surface-muted)]/50"
                            :class="{ 'bg-primary/5': p.escolhido }">
                            <td class="px-3 py-2.5 text-center">
                                <input type="checkbox" :checked="p.escolhido" :disabled="p.preco_afiliado === null && !p.escolhido"
                                    :aria-label="`Clonar ${p.nome} para afiliado`"
                                    :title="p.preco_afiliado === null ? 'Sem custo cadastrado' : ''"
                                    @change="escolher({ produtos: [p.id], escolhido: $event.target.checked })">
                            </td>
                            <td class="px-3 py-2.5">
                                <div>{{ p.nome }}</div>
                                <div class="font-mono text-xs text-slate-500">
                                    {{ p.sku ?? 'sem SKU' }}<template v-if="p.sku_afiliado"> → {{ p.sku_afiliado }}</template>
                                    <span v-if="!p.ativo" class="ml-2 rounded bg-slate-200 px-1.5 py-0.5 font-sans text-[11px] text-slate-600 dark:bg-slate-700 dark:text-slate-300">inativo no site</span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-xs">{{ p.grupo ? props.grupos[p.grupo] : '—' }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ dinheiro(p.custo) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ dinheiro(p.preco_atual) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right font-semibold">
                                <template v-if="p.preco_afiliado !== null">{{ dinheiro(p.preco_afiliado) }}</template>
                                <span v-else class="text-xs font-normal text-red-600">sem custo</span>
                            </td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-right">{{ dinheiro(p.comissao) }}</td>
                            <td class="whitespace-nowrap px-3 py-2.5 text-xs">
                                <span v-if="p.shopee_item_id" class="font-mono">{{ p.shopee_item_id }}</span>
                                <span v-else class="text-slate-500">sem anúncio</span>
                            </td>
                        </tr>
                        <tr v-if="props.produtos.length === 0">
                            <td colspan="8" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                Nenhum produto encontrado.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
