<script setup>
// Campo de busca das tabelas do Radar; use com v-model.
defineProps({
    modelValue: { type: String, default: '' },
    rotulo: { type: String, required: true },
    placeholder: { type: String, default: 'Buscar' },
});
defineEmits(['update:modelValue']);
</script>

<template>
    <label class="busca">
        <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
        <span class="sr-only">{{ rotulo }}</span>
        <input :value="modelValue" type="search" :placeholder="placeholder" autocomplete="off"
            @input="$emit('update:modelValue', $event.target.value.trim())">
    </label>
</template>

<style scoped>
.busca {
    display: flex;
    flex: 1 1 12rem;
    max-width: 24rem;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid var(--surface-border);
    border-radius: 10px;
    background: var(--surface);
    padding: 0.4rem 0.75rem;
    color: var(--texto-suave);
}
.busca:focus-within { border-color: color-mix(in oklab, var(--cor-texto) 55%, var(--surface-border)); }
.busca input { width: 100%; border: 0; background: transparent; padding: 0; font-size: 0.875rem; color: var(--surface-foreground); outline: none; box-shadow: none; }
</style>
