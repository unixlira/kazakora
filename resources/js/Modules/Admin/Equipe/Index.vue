<script setup>
// Equipe › Visão geral (pedido do Lira 2026-10-20): um card por agente,
// com a cor e o ícone dele, a função e quantos comandos extras estão ativos.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    membros: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Equipe" />
    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl font-bold">Equipe</h1>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">Quem trabalha na KazaKora, o que cada um faz hoje e os comandos extras que você deu para cada um. Abra um membro para ver as skills e adicionar comandos.</p>
            </div>

            <ul class="grid list-none gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <li v-for="m in membros" :key="m.slug">
                    <Link :href="`/admin/equipe/${m.slug}`"
                        class="group flex h-full flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                        :class="`icone-cor-${m.cor}`">
                        <div class="flex items-center gap-3">
                            <span class="avatar-cor flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-lg font-bold" aria-hidden="true">{{ m.inicial }}</span>
                            <div class="min-w-0">
                                <p class="truncate font-semibold">{{ m.nome }}</p>
                                <p class="truncate text-xs text-slate-500">{{ m.cargo }}</p>
                            </div>
                            <i class="icone-cor ml-auto text-lg" :class="m.icone" aria-hidden="true"></i>
                        </div>
                        <p class="mt-3 line-clamp-3 flex-1 text-sm text-slate-600 dark:text-slate-300">{{ m.descricao }}</p>
                        <div class="mt-4 flex items-center justify-between text-xs">
                            <span class="rounded-full px-2 py-0.5 font-medium fundo-cor-suave">
                                {{ m.comandosAtivos }} {{ m.comandosAtivos === 1 ? 'comando extra' : 'comandos extras' }}
                            </span>
                            <span class="icone-cor font-semibold group-hover:underline">Ver skills <i class="fas fa-arrow-right ml-0.5 text-[10px]" aria-hidden="true"></i></span>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
