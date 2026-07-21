<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

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
    form.post('/checkout');
};
</script>

<template>
    <Head title="Checkout" />

    <AppLayout>
        <h1 class="text-2xl font-bold">Checkout</h1>

        <p v-if="items.length === 0" class="mt-4 text-gray-500">
            Seu carrinho está vazio.
        </p>

        <div v-else class="mt-6 grid grid-cols-1 gap-8 lg:grid-cols-3">
            <form class="space-y-4 lg:col-span-2" @submit.prevent="submit">
                <InputError :message="form.errors.cart" />

                <h2 class="font-semibold">Endereço de entrega</h2>

                <div>
                    <label for="shipping_name" class="block text-sm font-medium">Nome completo</label>
                    <input
                        id="shipping_name"
                        v-model="form.shipping_name"
                        type="text"
                        required
                        class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                    >
                    <InputError :message="form.errors.shipping_name" />
                </div>

                <div>
                    <label for="shipping_phone" class="block text-sm font-medium">Telefone</label>
                    <input
                        id="shipping_phone"
                        v-model="form.shipping_phone"
                        type="text"
                        required
                        class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                    >
                    <InputError :message="form.errors.shipping_phone" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="shipping_zip" class="block text-sm font-medium">CEP</label>
                        <input
                            id="shipping_zip"
                            v-model="form.shipping_zip"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                        >
                        <InputError :message="form.errors.shipping_zip" />
                    </div>

                    <div>
                        <label for="shipping_state" class="block text-sm font-medium">UF</label>
                        <input
                            id="shipping_state"
                            v-model="form.shipping_state"
                            type="text"
                            maxlength="2"
                            required
                            class="mt-1 w-full rounded border border-gray-300 px-3 py-2 uppercase"
                        >
                        <InputError :message="form.errors.shipping_state" />
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="col-span-2">
                        <label for="shipping_street" class="block text-sm font-medium">Rua</label>
                        <input
                            id="shipping_street"
                            v-model="form.shipping_street"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                        >
                        <InputError :message="form.errors.shipping_street" />
                    </div>

                    <div>
                        <label for="shipping_number" class="block text-sm font-medium">Número</label>
                        <input
                            id="shipping_number"
                            v-model="form.shipping_number"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                        >
                        <InputError :message="form.errors.shipping_number" />
                    </div>
                </div>

                <div>
                    <label for="shipping_complement" class="block text-sm font-medium">Complemento (opcional)</label>
                    <input
                        id="shipping_complement"
                        v-model="form.shipping_complement"
                        type="text"
                        class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                    >
                    <InputError :message="form.errors.shipping_complement" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="shipping_neighborhood" class="block text-sm font-medium">Bairro</label>
                        <input
                            id="shipping_neighborhood"
                            v-model="form.shipping_neighborhood"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                        >
                        <InputError :message="form.errors.shipping_neighborhood" />
                    </div>

                    <div>
                        <label for="shipping_city" class="block text-sm font-medium">Cidade</label>
                        <input
                            id="shipping_city"
                            v-model="form.shipping_city"
                            type="text"
                            required
                            class="mt-1 w-full rounded border border-gray-300 px-3 py-2"
                        >
                        <InputError :message="form.errors.shipping_city" />
                    </div>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded bg-gray-900 py-3 font-medium text-white hover:bg-gray-700 disabled:cursor-not-allowed disabled:bg-gray-300"
                >
                    Confirmar pedido
                </button>
            </form>

            <div class="h-fit rounded-lg border border-gray-200 bg-white p-4">
                <h2 class="font-semibold">Resumo do pedido</h2>

                <ul class="mt-4 space-y-2 text-sm">
                    <li
                        v-for="item in items"
                        :key="item.product.id"
                        class="flex justify-between"
                    >
                        <span>{{ item.product.name }} × {{ item.quantity }}</span>
                        <span>{{ formatPrice(item.subtotal) }}</span>
                    </li>
                </ul>

                <div class="mt-4 flex justify-between border-t border-gray-200 pt-4 font-bold">
                    <span>Total</span>
                    <span>{{ formatPrice(total) }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
