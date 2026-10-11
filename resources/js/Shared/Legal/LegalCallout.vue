<script setup>
import { COMPANY } from '@/Shared/company';
</script>

<template>
    <div class="rounded-2xl bg-store-accent-strong p-6 text-center text-store-accent-contrast">
        <p class="text-sm font-medium">
            Precisa de ajuda? Nossa equipe está sempre disponível durante o horário comercial, inclusive aos sábados.
        </p>
        <p class="font-store-mono mt-1 text-xs opacity-80">{{ COMPANY.horario }}</p>

        <div class="mt-4 flex flex-wrap items-center justify-center gap-3">
            <a :href="COMPANY.whatsappLink" target="_blank" rel="noopener"
                class="inline-flex items-center gap-2 rounded-full bg-[#25D366] px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90">
                <i class="fab fa-whatsapp"></i>WhatsApp
            </a>
            <a :href="`mailto:${COMPANY.email}`"
                class="inline-flex items-center gap-2 rounded-full bg-store-accent-contrast px-5 py-2.5 text-sm font-semibold text-store-accent-strong hover:opacity-90">
                <i class="fas fa-envelope"></i>E-mail
            </a>
        </div>
    </div>
</template>
