<script setup>
defineProps({
    stockMovements: {
        type: Array,
        default: () => [],
    },
});

const typeLabels = {
    sale: 'Venda',
    restock: 'Reposição',
    adjustment: 'Ajuste manual',
    return: 'Devolução',
    marketplace_sync: 'Sincronização de marketplace',
};
</script>

<template>
    <div>
        <p v-if="stockMovements.length === 0" class="text-sm text-slate-500">Nenhuma movimentação registrada ainda.</p>

        <table v-else class="w-full text-left text-sm">
            <thead class="text-xs uppercase text-slate-400">
                <tr>
                    <th class="py-2">Data</th>
                    <th class="py-2">Tipo</th>
                    <th class="py-2">Quantidade</th>
                    <th class="py-2">Estoque após</th>
                    <th class="py-2">Motivo</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="movement in stockMovements" :key="movement.id" class="border-t border-slate-100">
                    <td class="py-2 text-slate-500">{{ new Date(movement.created_at).toLocaleString('pt-BR') }}</td>
                    <td class="py-2">{{ typeLabels[movement.type] ?? movement.type }}</td>
                    <td class="py-2" :class="movement.quantity >= 0 ? 'text-emerald-600' : 'text-red-600'">
                        {{ movement.quantity >= 0 ? '+' : '' }}{{ movement.quantity }}
                    </td>
                    <td class="py-2">{{ movement.stock_after }}</td>
                    <td class="py-2 text-slate-500">{{ movement.reason ?? '—' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
