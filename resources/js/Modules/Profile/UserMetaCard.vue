<script setup>
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import AvatarManager from '@/Modules/Profile/AvatarManager.vue';

const props = defineProps({
    profileUser: {
        type: Object,
        required: true,
    },
    isOwnProfile: {
        type: Boolean,
        required: true,
    },
    canManageUsers: {
        type: Boolean,
        required: true,
    },
    roles: {
        type: Array,
        default: () => [],
    },
});

const ROLE_LABELS = {
    admin: 'Administrador',
    manager: 'Gerente',
    subscriber: 'Assinante',
    customer: 'Cliente',
};

const roleLabel = computed(() => ROLE_LABELS[props.profileUser.role] ?? props.profileUser.role);

const changeRole = (event) => {
    router.patch(`/admin/usuarios-permissoes/usuarios/${props.profileUser.id}`, { role: event.target.value }, { preserveScroll: true });
};
</script>

<template>
    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-6">
        <div class="flex flex-col items-center gap-6 text-center sm:flex-row sm:text-left">
            <AvatarManager :profile-user="profileUser" :editable="isOwnProfile" />

            <div>
                <h4 class="text-lg font-semibold">{{ profileUser.name }}</h4>
                <div class="mt-1 flex flex-col items-center gap-1 text-sm text-store-fg-muted sm:flex-row sm:gap-3">
                    <select v-if="canManageUsers && !isOwnProfile" :value="profileUser.role"
                        class="rounded-full border border-store-border-strong bg-store-bg px-3 py-1 text-xs font-medium text-store-fg focus:border-store-accent focus:outline-none"
                        @change="changeRole">
                        <option v-for="role in roles" :key="role" :value="role">{{ ROLE_LABELS[role] ?? role }}</option>
                    </select>
                    <span v-else>{{ roleLabel }}</span>
                    <span class="hidden h-3.5 w-px bg-store-border-strong sm:block"></span>
                    <span>{{ profileUser.email }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
