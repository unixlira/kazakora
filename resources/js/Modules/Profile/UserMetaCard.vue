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
    customer: 'Super Cliente',
};

const roleLabel = computed(() => ROLE_LABELS[props.profileUser.role] ?? props.profileUser.role);

// Cliente não precisa ver o próprio cargo "Cliente"; admin vendo outro usuário
// continua com o seletor e a equipe continua vendo o cargo.
const showRole = computed(() => (props.canManageUsers && !props.isOwnProfile) || props.profileUser.role !== 'customer');

const changeRole = (event) => {
    router.patch(`/admin/usuarios-permissoes/usuarios/${props.profileUser.id}`, { role: event.target.value }, { preserveScroll: true });
};
</script>

<template>
    <div>
        <div class="flex flex-col items-center gap-5 text-center sm:flex-row sm:text-left">
            <AvatarManager :profile-user="profileUser" :editable="isOwnProfile" />

            <div>
                <h2 class="text-lg font-semibold">{{ profileUser.name }}</h2>
                <div class="mt-1 flex flex-col items-center gap-1 text-sm text-store-fg-muted sm:flex-row sm:flex-wrap sm:gap-x-3">
                    <select v-if="canManageUsers && !isOwnProfile" :value="profileUser.role" aria-label="Cargo"
                        class="rounded-full border border-store-border-strong bg-store-bg px-3 py-1 text-xs font-medium text-store-fg focus:border-store-accent focus:outline-none"
                        @change="changeRole">
                        <option v-for="role in roles" :key="role" :value="role">{{ ROLE_LABELS[role] ?? role }}</option>
                    </select>
                    <span v-else-if="showRole">{{ roleLabel }}</span>
                    <span v-if="showRole" class="hidden h-3.5 w-px bg-store-border-strong sm:block" aria-hidden="true"></span>
                    <span class="break-all">{{ profileUser.email }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
