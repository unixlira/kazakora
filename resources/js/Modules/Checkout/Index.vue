<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    total: {
        type: Number,
        default: 0,
    },
    user: {
        type: Object,
        required: true,
    },
});

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const form = useForm({
    shipping_name: props.user.name,
    shipping_phone: '',
    shipping_zip: '',
    shipping_street: '',
    shipping_number: '',
    shipping_complement: '',
    shipping_neighborhood: '',
    shipping_city: '',
    shipping_state: '',
});

const submit = () => {
    form.post('/finalizacao');
};

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';
</script>

<template>
    <Head title="Finalizar compra" />

    <AppLayout>
        <div class="mx-auto max-w-[1100px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Finalizar compra</h1>

            <p v-if="items.length === 0" class="mt-12 text-center text-store-fg-muted">
                Seu carrinho está vazio.
                <Link href="/" class="mt-3 block font-medium text-store-accent hover:underline">Ver catálogo</Link>
            </p>

            <div v-else class="mt-8 grid gap-10 lg:grid-cols-[1.4fr_1fr]">
                <div>
                    <p v-if="form.errors.cart" class="mb-4 text-sm text-red-600">{{ form.errors.cart }}</p>

                    <h2 class="mb-4 text-lg font-semibold">Endereço de entrega</h2>
                    <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium">Nome completo</label>
                            <input v-model="form.shipping_name" type="text" required :class="inputClass">
                            <p v-if="form.errors.shipping_name" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_name }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium">Telefone</label>
                            <input v-model="form.shipping_phone" type="tel" required :class="inputClass">
                            <p v-if="form.errors.shipping_phone" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_phone }}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium">CEP</label>
                            <input v-model="form.shipping_zip" type="text" required :class="inputClass">
                            <p v-if="form.errors.shipping_zip" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_zip }}</p>
                        </div>
                        <div class="sm:col-span-2 sm:grid sm:grid-cols-3 sm:gap-4">
                            <div class="sm:col-span-2">
                                <label class="text-sm font-medium">Rua</label>
                                <input v-model="form.shipping_street" type="text" required :class="inputClass">
                                <p v-if="form.errors.shipping_street" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_street }}</p>
                            </div>
                            <div>
                                <label class="text-sm font-medium">Número</label>
                                <input v-model="form.shipping_number" type="text" required :class="inputClass">
                                <p v-if="form.errors.shipping_number" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_number }}</p>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-sm font-medium">Complemento</label>
                            <input v-model="form.shipping_complement" type="text" :class="inputClass">
                        </div>
                        <div>
                            <label class="text-sm font-medium">Bairro</label>
                            <input v-model="form.shipping_neighborhood" type="text" required :class="inputClass">
                            <p v-if="form.errors.shipping_neighborhood" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_neighborhood }}</p>
                        </div>
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <label class="text-sm font-medium">Cidade</label>
                                <input v-model="form.shipping_city" type="text" required :class="inputClass">
                                <p v-if="form.errors.shipping_city" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_city }}</p>
                            </div>
                            <div>
                                <label class="text-sm font-medium">UF</label>
                                <input v-model="form.shipping_state" type="text" maxlength="2" required class="uppercase" :class="inputClass">
                                <p v-if="form.errors.shipping_state" class="mt-1 text-xs text-red-600">{{ form.errors.shipping_state }}</p>
                            </div>
                        </div>

                        <button type="submit" :disabled="form.processing"
                            class="mt-2 rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50 sm:col-span-2 sm:w-fit">
                            Confirmar pedido
                        </button>
                    </form>
                </div>

                <div class="h-fit rounded-2xl border border-store-border bg-store-bg-raised p-6">
                    <h2 class="mb-4 text-lg font-semibold">Resumo do pedido</h2>
                    <div class="flex flex-col gap-3 text-sm">
                        <div v-for="item in items" :key="item.product.id" class="flex justify-between">
                            <span>{{ item.product.name }} × {{ item.quantity }}</span>
                            <span class="font-store-mono">{{ formatPrice(item.subtotal) }}</span>
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline justify-between border-t border-store-border pt-4">
                        <span class="font-semibold">Total</span>
                        <span class="text-lg font-semibold text-store-accent">{{ formatPrice(total) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
