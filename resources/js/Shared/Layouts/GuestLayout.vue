<script setup>
import { Link } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';
import { useStorefrontAssets } from '@/Shared/useStorefrontAssets';

useStorefrontAssets();
onUnmounted(useStorefrontAssets());
</script>

<template>
    <div class="min-vh-100 d-flex">
        <!-- Form side -->
        <div class="col-12 col-lg-6 d-flex flex-column align-items-center justify-content-center px-3 py-5 bg-white">
            <Link href="/" class="mb-4 text-decoration-none">
                <h1 class="display-6 text-primary m-0"><i class="fas fa-leaf text-secondary me-2"></i>KazaKora</h1>
            </Link>

            <div class="w-100" style="max-width: 24rem;">
                <slot />
            </div>
        </div>

        <!-- Illustration / brand side -->
        <div class="d-none d-lg-flex col-lg-6 align-items-center justify-content-center position-relative overflow-hidden"
            style="background: linear-gradient(135deg, #F28B00 0%, #F92400 100%);">
            <div class="text-center text-white px-5" style="max-width: 26rem;">
                <i class="fas fa-couch mb-4" style="font-size: 4rem; opacity: .9;"></i>
                <h2 class="text-white mb-3">Decoração que transforma sua casa</h2>
                <p class="mb-0" style="opacity: .9;">
                    Gerencie sua loja, seus pedidos e sua equipe em um só lugar.
                </p>
            </div>
        </div>
    </div>
</template>
