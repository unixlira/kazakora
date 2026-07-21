<script setup>
import GuestLayout from '@/Shared/Layouts/GuestLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <Head title="Recuperar senha" />

    <GuestLayout>
        <h1 class="text-xl font-bold">Recuperar senha</h1>

        <p class="mt-2 text-sm text-gray-500">
            Informe seu e-mail e enviaremos um link para redefinir sua senha.
        </p>

        <p v-if="flashSuccess" class="mt-4 rounded bg-green-50 p-3 text-sm text-green-700">
            {{ flashSuccess }}
        </p>

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
                    class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                >
                <InputError :message="form.errors.email" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded bg-gray-900 py-2 font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
            >
                Enviar link de redefinição
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-500">
            <Link href="/login" class="underline">Voltar ao login</Link>
        </p>
    </GuestLayout>
</template>
