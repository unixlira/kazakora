<script setup>
import AuthLayout from '@/Shared/Layouts/AuthLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Segunda etapa do login do admin (2026-11-25): código de 6 dígitos por e-mail.
const props = defineProps({
    email: { type: String, required: true },
    validadeMinutos: { type: Number, default: 10 },
    lembrarDias: { type: Number, default: 30 },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const reenviando = ref(false);

const form = useForm({
    codigo: '',
    lembrar: false,
});

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-center font-mono text-2xl tracking-[0.5em] focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';

const submit = () => {
    form.post('/entrar/codigo', {
        onError: () => form.reset('codigo'),
    });
};

const reenviar = () => {
    router.post('/entrar/codigo/reenviar', {}, {
        preserveScroll: true,
        onStart: () => { reenviando.value = true; },
        onFinish: () => { reenviando.value = false; },
    });
};
</script>

<template>
    <Head title="Código de acesso" />

    <AuthLayout>
        <h1 class="font-display text-xl font-semibold">Confirme que é você</h1>

        <p class="mt-2 text-sm text-store-fg-muted">
            Enviamos um código de 6 números para <strong>{{ props.email }}</strong>.
            Ele vale {{ props.validadeMinutos }} minutos.
        </p>

        <p v-if="flashSuccess" class="mt-4 rounded-lg bg-store-accent-soft p-3 text-sm text-store-accent-strong">
            {{ flashSuccess }}
        </p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
            <div>
                <label for="codigo" class="block text-sm font-medium">Código</label>
                <input
                    id="codigo"
                    v-model="form.codigo"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    maxlength="6"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    :class="inputClass"
                >
                <InputError :message="form.errors.codigo" />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.lembrar" type="checkbox">
                Lembrar este dispositivo por {{ props.lembrarDias }} dias
            </label>

            <button
                type="submit"
                :disabled="form.processing || form.codigo.length < 6"
                class="w-full rounded-lg bg-store-accent py-2.5 font-medium text-store-accent-contrast transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Entrar
            </button>
        </form>

        <div class="mt-6 flex items-center justify-between text-sm">
            <button
                type="button"
                :disabled="reenviando"
                class="font-medium text-store-accent hover:underline disabled:opacity-50"
                @click="reenviar"
            >
                Reenviar código
            </button>
            <Link href="/entrar" class="text-store-fg-muted hover:underline">Voltar</Link>
        </div>
    </AuthLayout>
</template>
