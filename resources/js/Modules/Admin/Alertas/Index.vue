<script setup>
// Sistema › Alertas (pedido 2026-10-16): o que precisa de ação do Lira.
// Os abertos também aparecem na faixa vermelha do topo de todo o admin.
// Cada tipo de alerta pode ser ligado ou desligado aqui.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps({
    abertos: { type: Array, default: () => [] },
    historico: { type: Array, default: () => [] },
    tipos: { type: Array, default: () => [] },
});

const salvando = ref(null);

const resolver = (alerta) => {
    salvando.value = `alerta-${alerta.id}`;
    router.post(`/admin/alertas/${alerta.id}/resolver`, {}, { preserveScroll: true, onFinish: () => { salvando.value = null; } });
};

const alternarTipo = (tipo) => {
    salvando.value = `tipo-${tipo.chave}`;
    router.post(`/admin/alertas/tipos/${tipo.chave}`, { ativo: !tipo.ativo }, { preserveScroll: true, onFinish: () => { salvando.value = null; } });
};
</script>

<template>
    <Head title="Alertas" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold">Alertas</h1>
                <p class="mt-1 text-sm text-slate-500">Problemas que precisam da sua ação. Enquanto estiverem abertos, aparecem no topo de todas as telas do admin.</p>
            </div>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Abertos</h2>
                <p v-if="!abertos.length" class="mt-3 text-sm text-slate-500"><i class="fas fa-circle-check mr-1 text-green-600"></i> Nenhum alerta aberto.</p>
                <ul v-else class="mt-3 divide-y divide-[var(--surface-border)]">
                    <li v-for="alerta in abertos" :key="alerta.id" class="flex flex-col gap-3 py-3 sm:flex-row sm:items-start">
                        <i class="fas fa-triangle-exclamation mt-1 text-red-600" aria-hidden="true"></i>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ alerta.titulo }}</p>
                            <p class="mt-1 whitespace-pre-line break-words text-sm">{{ alerta.mensagem }}</p>
                            <p class="mt-1 text-xs text-slate-500">{{ alerta.ocorrencias }} ocorrência(s), desde {{ alerta.primeiraEm }}; última {{ alerta.ultimaEm }}</p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Link v-if="alerta.link && alerta.link !== '/admin/alertas'" :href="alerta.link" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-medium hover:bg-[var(--surface-muted)]">Ver</Link>
                            <button type="button" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                                :disabled="salvando === `alerta-${alerta.id}`" @click="resolver(alerta)">
                                {{ salvando === `alerta-${alerta.id}` ? 'Salvando…' : 'Resolvido' }}
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Tipos de alerta</h2>
                <ul class="mt-3 divide-y divide-[var(--surface-border)]">
                    <li v-for="tipo in tipos" :key="tipo.chave" class="flex items-start gap-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ tipo.nome }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ tipo.descricao }}</p>
                        </div>
                        <button type="button" role="switch" :aria-checked="tipo.ativo" :aria-label="`${tipo.ativo ? 'Desligar' : 'Ligar'} ${tipo.nome}`"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors disabled:opacity-60"
                            :class="tipo.ativo ? 'bg-green-600' : 'bg-slate-300 dark:bg-slate-600'"
                            :disabled="salvando === `tipo-${tipo.chave}`" @click="alternarTipo(tipo)">
                            <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition-transform" :class="tipo.ativo ? 'translate-x-5' : 'translate-x-0.5'"></span>
                        </button>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-lg font-semibold">Resolvidos (últimos 50)</h2>
                <p v-if="!historico.length" class="mt-3 text-sm text-slate-500">Nenhum ainda.</p>
                <div v-else class="mt-3 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-500">
                            <tr>
                                <th class="py-2 pr-4">Alerta</th>
                                <th class="py-2 pr-4">Vezes</th>
                                <th class="py-2 pr-4">Período</th>
                                <th class="py-2">Resolvido</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--surface-border)]">
                            <tr v-for="alerta in historico" :key="alerta.id">
                                <td class="py-2 pr-4">
                                    <p class="font-medium">{{ alerta.titulo }}</p>
                                    <p class="text-xs text-slate-500">{{ alerta.mensagem }}</p>
                                </td>
                                <td class="py-2 pr-4">{{ alerta.ocorrencias }}</td>
                                <td class="whitespace-nowrap py-2 pr-4">{{ alerta.primeiraEm }} a {{ alerta.ultimaEm }}</td>
                                <td class="whitespace-nowrap py-2">{{ alerta.resolvidoEm }} · {{ alerta.resolvidoPor }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
