<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    icon: {
        type: String,
        required: true,
    },
    label: {
        type: String,
        required: true,
    },
    color: {
        type: String,
        default: 'slate',
    },
    href: {
        type: String,
        default: null,
    },
});

defineEmits(['click']);

// Same alert palette as StatusBadge.vue (bg-{color}-100/text-{color}-700,
// dark variants included) so action icons and status pills read as one
// consistent color system across the admin.
const COLOR_CLASSES = {
    slate: 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300',
    blue: 'bg-blue-100 text-blue-700 hover:bg-blue-200 dark:bg-blue-900/50 dark:text-blue-300',
    red: 'bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/50 dark:text-red-300',
    amber: 'bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/50 dark:text-yellow-300',
    violet: 'bg-purple-100 text-purple-700 hover:bg-purple-200 dark:bg-purple-900/50 dark:text-purple-300',
};
</script>

<template>
    <Link v-if="href" :href="href" :title="label" :aria-label="label"
        class="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors"
        :class="COLOR_CLASSES[color] ?? COLOR_CLASSES.slate">
        <i class="fas text-sm" :class="icon"></i>
    </Link>
    <button v-else type="button" :title="label" :aria-label="label"
        class="inline-flex h-8 w-8 items-center justify-center rounded-full transition-colors"
        :class="COLOR_CLASSES[color] ?? COLOR_CLASSES.slate"
        @click="$emit('click')">
        <i class="fas text-sm" :class="icon"></i>
    </button>
</template>
