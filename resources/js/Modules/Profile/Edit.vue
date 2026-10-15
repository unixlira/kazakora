<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import UserMetaCard from '@/Modules/Profile/UserMetaCard.vue';
import UserInfoCard from '@/Modules/Profile/UserInfoCard.vue';
import UserSecurityCard from '@/Modules/Profile/UserSecurityCard.vue';
import UserAddressesCard from '@/Modules/Profile/UserAddressesCard.vue';
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
    addresses: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <Head :title="isOwnProfile ? 'Meu Perfil' : `Perfil de ${profileUser.name}`" />

    <AppLayout>
        <div class="mx-auto max-w-[1180px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">{{ isOwnProfile ? 'Meu Perfil' : profileUser.name }}</h1>

            <!-- Pedido 2026-10-14: perfil + dados pessoais à esquerda,
                 endereços à direita e segurança embaixo. -->
            <div class="mt-8 grid grid-cols-1 items-stretch gap-6 lg:grid-cols-12">
                <section class="rounded-2xl border border-store-border bg-store-bg-raised p-6 lg:col-span-5">
                    <UserMetaCard :profile-user="profileUser" :is-own-profile="isOwnProfile" :can-manage-users="canManageUsers" :roles="roles" />
                    <hr class="my-6 border-store-border">
                    <UserInfoCard :profile-user="profileUser" :is-own-profile="isOwnProfile" />
                </section>

                <section class="lg:col-span-7">
                    <UserAddressesCard :addresses="addresses" :editable="isOwnProfile" />
                </section>

                <section v-if="isOwnProfile" class="lg:col-span-12">
                    <UserSecurityCard />
                </section>
            </div>
        </div>
    </AppLayout>
</template>
