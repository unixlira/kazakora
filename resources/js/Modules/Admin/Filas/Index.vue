<script setup>
// Filas (pedido 2026-10-11): o painel do Horizon dentro do admin.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Filas" />

    <AdminLayout>
        <div class="flex min-w-0 flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h1 class="flex items-center gap-2 font-display text-2xl font-semibold"><i class="fas fa-layer-group text-primary"></i> Filas</h1>
                <a href="/horizon" target="_blank" rel="noopener" class="text-sm font-medium text-primary">Abrir em tela cheia <i class="fas fa-up-right-from-square text-xs"></i></a>
            </div>
            <iframe src="/horizon" title="Horizon" class="h-[calc(100vh-10rem)] min-h-[560px] w-full rounded-2xl border border-[var(--surface-border)] bg-white shadow-sm"></iframe>
        </div>
    </AdminLayout>
</template>
