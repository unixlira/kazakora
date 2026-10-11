<script setup>
// Tela de uma notificação do cliente (pedido 2026-10-11): aberta pelo sino,
// mostra o texto inteiro e o botão para onde ela leva.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    notificacao: { type: Object, required: true },
});
</script>

<template>
    <Head :title="notificacao.titulo" />

    <AppLayout>
        <div class="mx-auto max-w-[640px] px-4 py-10 md:px-6">
            <article class="overflow-hidden rounded-3xl border border-store-border bg-store-bg-raised shadow-sm">
                <div class="flex items-center gap-4 bg-gradient-to-r from-[#F27A2A] to-[#e0480f] px-6 py-6 text-white">
                    <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/20 text-2xl">
                        <i class="fas" :class="notificacao.icone"></i>
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-wider opacity-80">Notificação</p>
                        <h1 class="text-xl font-bold leading-snug">{{ notificacao.titulo }}</h1>
                    </div>
                </div>

                <div class="px-6 py-6">
                    <p v-if="notificacao.texto" class="leading-relaxed text-store-fg">{{ notificacao.texto }}</p>
                    <p class="mt-4 text-xs text-store-fg-faint"><i class="far fa-clock mr-1"></i>{{ notificacao.quando }}</p>

                    <div class="mt-6 flex flex-wrap gap-3">
                        <Link v-if="notificacao.link" :href="notificacao.link"
                            class="inline-flex items-center gap-2 rounded-full bg-[#F27A2A] px-6 py-3 text-sm font-bold text-white no-underline shadow hover:brightness-95">
                            {{ notificacao.acao ?? 'Abrir' }} <i class="fas fa-arrow-right text-xs"></i>
                        </Link>
                        <Link href="/" class="inline-flex items-center rounded-full border border-store-border px-6 py-3 text-sm font-semibold text-store-fg no-underline hover:border-[#F27A2A]">
                            Voltar à loja
                        </Link>
                    </div>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
