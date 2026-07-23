<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
});

const rows = [
    { icon: 'fa-envelope', label: 'E-mails de notificação', description: 'Receber e-mails sobre pedidos e novidades' },
    { icon: 'fa-language', label: 'Idioma', description: 'Português (Brasil)' },
    { icon: 'fa-sun', label: 'Aparência', description: 'Tema claro' },
    { icon: 'fa-shield-halved', label: 'Privacidade', description: 'Preferências de dados e privacidade' },
];
</script>

<template>
    <Head title="Configurações" />

    <AppLayout>
        <div class="mx-auto max-w-[760px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Configurações</h1>
            <p class="mt-2 text-store-fg-muted">Preferências da conta de {{ profileUser.name }}. Em breve.</p>

            <div class="mt-8 divide-y divide-store-border rounded-2xl border border-store-border bg-store-bg-raised">
                <div v-for="row in rows" :key="row.label" class="flex items-center justify-between px-6 py-4">
                    <div>
                        <p class="flex items-center gap-2 font-medium">
                            <i class="fas text-store-fg-muted" :class="row.icon"></i>{{ row.label }}
                        </p>
                        <p class="mt-0.5 text-sm text-store-fg-muted">{{ row.description }}</p>
                    </div>
                    <span class="font-store-mono rounded-full bg-store-bg-sunken px-3 py-1 text-xs text-store-fg-faint">Em breve</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
