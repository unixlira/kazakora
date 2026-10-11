<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import UserMetaCard from '@/Modules/Profile/UserMetaCard.vue';
import UserInfoCard from '@/Modules/Profile/UserInfoCard.vue';
import UserSecurityCard from '@/Modules/Profile/UserSecurityCard.vue';
import { Head } from '@inertiajs/vue3';

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
</script>

<template>
    <Head :title="isOwnProfile ? 'Meu Perfil' : `Perfil de ${profileUser.name}`" />

    <AppLayout>
        <div class="mx-auto max-w-[900px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">{{ isOwnProfile ? 'Meu Perfil' : profileUser.name }}</h1>

            <div class="mt-8 flex flex-col gap-6">
                <UserMetaCard :profile-user="profileUser" :is-own-profile="isOwnProfile" :can-manage-users="canManageUsers" :roles="roles" />
                <UserInfoCard :profile-user="profileUser" :is-own-profile="isOwnProfile" />
                <UserSecurityCard v-if="isOwnProfile" />
            </div>
        </div>
    </AppLayout>
</template>
