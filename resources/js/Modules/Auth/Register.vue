<script setup>
import GuestLayout from '@/Shared/Layouts/GuestLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/cadastro', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Criar conta" />

    <GuestLayout>
        <h1 class="text-xl font-bold">Criar conta</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="name" class="block text-sm font-medium">Nome</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-[#F28B00] focus:outline-none focus:ring-1 focus:ring-[#F28B00]"
                >
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <label for="email" class="block text-sm font-medium">E-mail</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autocomplete="username"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-[#F28B00] focus:outline-none focus:ring-1 focus:ring-[#F28B00]"
                >
                <InputError :message="form.errors.email" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium">Senha</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    autocomplete="new-password"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-[#F28B00] focus:outline-none focus:ring-1 focus:ring-[#F28B00]"
                >
                <InputError :message="form.errors.password" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium">Confirmar senha</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-[#F28B00] focus:outline-none focus:ring-1 focus:ring-[#F28B00]"
                >
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-[#F28B00] py-2.5 font-medium text-white transition-colors hover:bg-[#d97a00] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
                Criar conta
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
            Já tem uma conta?
            <Link href="/entrar" class="font-medium text-[#F28B00] hover:underline">Entrar</Link>
        </p>
    </GuestLayout>
</template>
