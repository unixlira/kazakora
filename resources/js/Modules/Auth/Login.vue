<script setup>
import GuestLayout from '@/Shared/Layouts/GuestLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
        default: false,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/entrar', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Entrar" />

    <GuestLayout>
        <h1 class="text-xl font-bold">Entrar</h1>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="email" class="block text-sm font-medium">E-mail</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    autofocus
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
                    autocomplete="current-password"
                    class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 focus:border-[#F28B00] focus:outline-none focus:ring-1 focus:ring-[#F28B00]"
                >
                <InputError :message="form.errors.password" />
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2">
                    <input v-model="form.remember" type="checkbox">
                    Lembrar-me
                </label>

                <Link v-if="canResetPassword" href="/esqueci-senha" class="font-medium text-[#F28B00] hover:underline">
                    Esqueceu a senha?
                </Link>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-[#F28B00] py-2.5 font-medium text-white transition-colors hover:bg-[#d97a00] disabled:cursor-not-allowed disabled:bg-gray-300"
            >
                Entrar
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
            Não tem uma conta?
            <Link href="/cadastro" class="font-medium text-[#F28B00] hover:underline">Cadastre-se</Link>
        </p>
    </GuestLayout>
</template>
