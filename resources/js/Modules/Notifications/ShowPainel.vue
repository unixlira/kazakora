<script setup>
// Tela de uma notificação para a equipe (2026-10-31): mesmo conteúdo de
// Show.vue, no visual do painel. O destino é <a> porque o link gravado pode
// ser de fora do painel, onde a visita do Inertia não funciona.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    notificacao: { type: Object, required: true },
});
</script>

<template>
    <Head :title="notificacao.titulo" />

    <AdminLayout>
        <Link href="/notificacoes" class="mb-4 inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
            <i class="fas fa-arrow-left text-xs"></i> Todas as notificações
        </Link>

        <article class="max-w-3xl rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-6">
            <div class="flex items-start gap-3">
                <i class="fas mt-1 text-primary" :class="notificacao.icone"></i>
                <h1 class="min-w-0 break-words text-lg font-bold">{{ notificacao.titulo }}</h1>
            </div>

            <p v-if="notificacao.texto" class="mt-4 whitespace-pre-line break-words text-sm leading-relaxed text-slate-600 dark:text-slate-300">{{ notificacao.texto }}</p>
            <p class="mt-4 text-xs text-slate-400"><i class="far fa-clock mr-1"></i>{{ notificacao.quando }}</p>

            <a v-if="notificacao.link" :href="notificacao.link"
                class="mt-5 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:brightness-95">
                {{ notificacao.acao }} <i class="fas fa-arrow-right text-xs"></i>
            </a>
        </article>
    </AdminLayout>
</template>
