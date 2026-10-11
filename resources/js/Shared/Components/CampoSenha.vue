<script setup>
// Campo de senha com o olho para ver o que foi digitado (pedido 2026-10-11).
// Atributos (id, class, autocomplete, required...) vão direto para o input.
import { ref } from 'vue';

defineOptions({ inheritAttrs: false });
const model = defineModel({ type: String, default: '' });
const visivel = ref(false);
</script>

<template>
    <div class="relative">
        <input v-model="model" v-bind="$attrs" :type="visivel ? 'text' : 'password'" style="padding-right: 2.75rem">
        <button type="button" class="absolute inset-y-0 right-0 flex w-11 cursor-pointer items-center justify-center text-store-fg-muted hover:text-store-fg"
            :aria-label="visivel ? 'Esconder senha' : 'Mostrar senha'" :title="visivel ? 'Esconder senha' : 'Mostrar senha'" @click="visivel = !visivel">
            <i class="far" :class="visivel ? 'fa-eye-slash' : 'fa-eye'"></i>
        </button>
    </div>
</template>
