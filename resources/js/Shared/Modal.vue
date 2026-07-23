<script setup>
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: 'max-w-[640px]',
    },
});

const emit = defineEmits(['close']);

const onKeydown = (event) => {
    if (event.key === 'Escape' && props.open) emit('close');
};

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

watch(
    () => props.open,
    (isOpen) => {
        document.body.style.overflow = isOpen ? 'hidden' : '';
    },
);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-store-fg/50 backdrop-blur-sm" @click="emit('close')"></div>
            <div class="relative w-full overflow-y-auto rounded-2xl bg-store-bg-raised p-6 shadow-xl lg:p-8"
                style="max-height: 90vh;" :class="maxWidth">
                <button type="button" class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-store-fg-muted hover:bg-store-bg-sunken"
                    aria-label="Fechar" @click="emit('close')">
                    <i class="fas fa-xmark"></i>
                </button>
                <slot />
            </div>
        </div>
    </Teleport>
</template>
