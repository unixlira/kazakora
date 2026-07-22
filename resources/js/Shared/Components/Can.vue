<script setup>
import { usePermissions } from '@/Shared/usePermissions';

const props = defineProps({
    permission: { type: String, required: true },
});

const { can } = usePermissions();
</script>

<template>
    <slot v-if="can(props.permission)" />
</template>
