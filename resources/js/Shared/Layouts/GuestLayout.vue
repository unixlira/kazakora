<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="admin-shell flex min-h-screen bg-[var(--surface-muted)]">
        <!-- Form side -->
        <div class="flex w-full flex-col items-center justify-center bg-[var(--surface)] px-4 py-12 lg:w-1/2">
            <Link href="/" class="mb-8 no-underline">
                <span class="text-2xl font-bold text-primary"><i class="fas fa-leaf mr-2 text-secondary"></i>KazaKora</span>
            </Link>

            <div class="w-full" style="max-width: 24rem;">
                <slot />
            </div>
        </div>

        <!-- Illustration / brand side -->
        <div class="relative hidden w-1/2 items-center justify-center overflow-hidden lg:flex"
            style="background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-info) 100%);">
            <div class="px-10 text-center text-white" style="max-width: 26rem;">
                <i class="fas fa-couch mb-4 text-6xl opacity-90"></i>
                <h2 class="mb-3 text-2xl font-semibold text-white">Decoração que transforma sua casa</h2>
                <p class="mb-0 opacity-90">
                    Gerencie sua loja, seus pedidos e sua equipe em um só lugar.
                </p>
            </div>
        </div>
    </div>
</template>
