<script setup>
import { Link } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';
import { useStorefrontAssets } from '@/Shared/useStorefrontAssets';

useStorefrontAssets();
onUnmounted(useStorefrontAssets());
</script>

<template>
    <div class="d-flex flex-column align-items-center justify-content-center min-vh-100 bg-light px-3 py-5">
        <Link href="/" class="mb-4 text-decoration-none">
            <h1 class="display-6 text-primary m-0"><i class="fas fa-leaf text-secondary me-2"></i>KazaKora</h1>
        </Link>

        <div class="w-100 bg-white rounded shadow-sm p-4 p-md-5" style="max-width: 24rem;">
            <slot />
        </div>
    </div>
</template>
