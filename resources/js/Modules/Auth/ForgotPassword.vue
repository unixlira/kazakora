<script setup>
import AuthLayout from '@/Shared/Layouts/AuthLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);

const form = useForm({
    email: '',
});

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';

const submit = () => {
    form.post('/esqueci-senha');
};
</script>

<template>
    <Head title="Recuperar senha" />

    <AuthLayout>
        <h1 class="font-display text-xl font-semibold">Recuperar senha</h1>

        <p class="mt-2 text-sm text-store-fg-muted">
            Informe seu e-mail e enviaremos um link para redefinir sua senha.
        </p>

        <p v-if="flashSuccess" class="mt-4 rounded-lg bg-store-accent-soft p-3 text-sm text-store-accent-strong">
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
                    :class="inputClass"
                >
                <InputError :message="form.errors.email" />
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full rounded-lg bg-store-accent py-2.5 font-medium text-store-accent-contrast transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Enviar link de redefinição
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-store-fg-muted">
            <Link href="/entrar" class="font-medium text-store-accent hover:underline">Voltar ao login</Link>
        </p>
    </AuthLayout>
</template>
