<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import LegalCard from '@/Shared/Legal/LegalCard.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Quem Somos" />

    <AppLayout>
        <div class="mx-auto max-w-[840px] px-4 py-12 md:px-6">
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-faint">
                <Link href="/" class="hover:text-store-accent">Início</Link> / Quem Somos
            </p>
            <h1 class="mt-2 font-display text-3xl font-semibold">Quem Somos</h1>

            <div class="mt-8">
                <LegalCard title="A KazaKora">
                    <p>A KazaKora é uma parte da Alpha Kora.</p>
                    <p>José Roberto Lira é o proprietário.</p>
                </LegalCard>
            </div>
        </div>
    </AppLayout>
</template>
