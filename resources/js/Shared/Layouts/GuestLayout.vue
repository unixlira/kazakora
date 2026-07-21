<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-6 py-12">
        <Link href="/" class="mb-8 text-lg font-semibold">Kazakora</Link>

        <div class="w-full max-w-sm rounded-lg border border-gray-200 bg-white p-8 shadow-sm">
            <slot />
        </div>
    </div>
</template>
