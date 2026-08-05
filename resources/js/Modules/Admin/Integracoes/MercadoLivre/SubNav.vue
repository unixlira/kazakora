<script setup>
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();

const tabs = [
    { label: 'Vendas', href: '/admin/integracoes/mercado-livre/vendas' },
    { label: 'Anúncios', href: '/admin/integracoes/mercado-livre/anuncios' },
    { label: 'Tipos de envio', href: '/admin/integracoes/mercado-livre/envios' },
    { label: 'Devoluções', href: '/admin/integracoes/mercado-livre/devolucoes' },
];

const isActive = (href) => page.url.startsWith(href);
</script>

<template>
    <div class="mb-6 flex flex-wrap items-center gap-2 border-b border-[var(--surface-border)] pb-3">
        <Link href="/admin/integracoes" class="mr-2 text-sm text-slate-400 hover:text-primary">
            <i class="fas fa-arrow-left"></i> Integrações
        </Link>
        <Link v-for="tab in tabs" :key="tab.href" :href="tab.href"
            class="rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
            :class="isActive(tab.href) ? 'bg-primary text-white' : 'text-slate-500 hover:bg-lightprimary dark:text-slate-400'">
            {{ tab.label }}
        </Link>
    </div>
</template>
