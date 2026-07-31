<script setup>
import AuthLayout from '@/Shared/Layouts/AuthLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';

const submit = () => {
    form.post('/cadastro', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Criar conta" />

    <AuthLayout>
        <h1 class="font-display text-xl font-semibold">Criar conta</h1>

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
                    :class="inputClass"
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
                    :class="inputClass"
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
                    :class="inputClass"
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
                    :class="inputClass"
                >
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-store-accent py-2.5 font-medium text-store-accent-contrast transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Criar conta
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-store-fg-muted">
            Já tem uma conta?
            <Link href="/entrar" class="font-medium text-store-accent hover:underline">Entrar</Link>
        </p>
    </AuthLayout>
</template>
