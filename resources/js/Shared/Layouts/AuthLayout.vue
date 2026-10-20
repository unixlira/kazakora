<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="storefront-shell flex min-h-screen bg-store-bg font-store text-store-fg">
        <!-- Form side -->
        <div class="flex w-full flex-col items-center justify-center bg-store-bg-raised px-4 py-12 lg:w-1/2">
            <Link href="/" class="mb-8 no-underline">
                <picture>
                    <source media="(prefers-color-scheme: dark)" :srcset="$page.props.marca?.logoRodape">
                    <img :src="$page.props.marca?.logoNav ?? '/images/marca/logo-nav.png'" alt="KazaKora" class="h-9 w-auto">
                </picture>
            </Link>

            <div class="w-full" style="max-width: 24rem;">
                <slot />
            </div>
        </div>

        <!-- Illustration / brand side -->
        <div class="relative hidden w-1/2 items-center justify-center overflow-hidden lg:flex"
            style="background: radial-gradient(circle at 24% 18%, color-mix(in oklab, var(--color-store-accent) 36%, transparent), transparent 32%), linear-gradient(135deg, var(--color-store-accent-strong) 0%, #140d1e 100%);">
            <div class="px-10 text-center text-store-accent-contrast" style="max-width: 26rem;">
                <i class="fas fa-cart-shopping mb-4 text-6xl text-store-accent"></i>
                <h2 class="mb-3 font-display text-2xl font-semibold text-white">Sua casa, do seu jeito</h2>
                <p class="mb-0 opacity-90">
                    Acompanhe seus pedidos, favoritos e dados da sua conta em um só lugar.
                </p>
            </div>
        </div>
    </div>
</template>
