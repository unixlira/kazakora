<script setup>
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import Modal from '@/Shared/Modal.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { maskCep, useCep } from '@/Shared/useCep';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    items: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    productsDiscount: { type: Number, default: 0 },
    addresses: { type: Array, default: () => [] },
    shippingMethods: { type: Array, default: () => [] },
    guest: { type: Object, default: null },
    draft: { type: Object, default: null },
});

const page = usePage();
const isAuthenticated = computed(() => !!page.props.auth?.user);

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);

const defaultAddress = computed(() => props.addresses[0] ?? null);

const form = useForm({
    address_id: props.draft?.address_id ?? defaultAddress.value?.id ?? null,
    new_address: props.draft?.new_address ?? {
        label: '',
        recipient_name: '',
        phone: '',
        zip: '',
        street: '',
        number: '',
        complement: '',
        neighborhood: '',
        city: '',
        state: '',
    },
    guest: props.guest ? (props.draft?.guest ?? { name: '', email: '', cpf: '' }) : null,
    shipping_method_id: props.draft?.shipping_method_id ?? props.shippingMethods[0]?.id ?? null,
});

const useNewAddress = ref(!form.address_id);
const showAddressPicker = ref(false);

const { loading: cepLoading, error: cepError, lookup: lookupCep } = useCep();

const onCepInput = async (event) => {
    form.new_address.zip = maskCep(event.target.value);

    if (form.new_address.zip.replace(/\D/g, '').length !== 8) {
        return;
    }

    const result = await lookupCep(form.new_address.zip);

    if (result) {
        form.new_address.street = result.street;
        form.new_address.neighborhood = result.neighborhood;
        form.new_address.city = result.city;
        form.new_address.state = result.state;
    }
};

const selectedAddress = computed(() => props.addresses.find((address) => address.id === form.address_id) ?? null);

const selectExistingAddress = (address) => {
    form.address_id = address.id;
    useNewAddress.value = false;
    showAddressPicker.value = false;
};

const selectNewAddress = () => {
    form.address_id = null;
    useNewAddress.value = true;
    showAddressPicker.value = false;
};

const addressLine = (address) =>
    `${address.street}, ${address.number}${address.complement ? ` - ${address.complement}` : ''} · ${address.neighborhood} · ${address.city} - ${address.state} · CEP ${address.zip}`;

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';

const shippingCost = computed(() => {
    const method = props.shippingMethods.find((m) => m.id === form.shipping_method_id);
    return method ? Number(method.price) : 0;
});

const finalTotal = computed(() => props.total + shippingCost.value);

const submit = () => {
    if (! useNewAddress.value) {
        form.new_address = null;
    } else {
        form.address_id = null;
    }

    form.post('/finalizacao/entrega');
};
</script>

<template>
    <Head title="Confira a forma de entrega" />

    <AppLayout>
        <div class="mx-auto max-w-[1100px] px-4 py-12 md:px-6">
            <h1 class="font-display text-3xl font-semibold">Confira a forma de entrega</h1>

            <p v-if="items.length === 0" class="mt-12 text-center text-store-fg-muted">
                Seu carrinho está vazio.
                <Link href="/" class="mt-3 block font-medium text-store-accent hover:underline">Ver catálogo</Link>
            </p>

            <div v-else class="mt-8 grid gap-10 lg:grid-cols-[1.4fr_1fr] lg:items-start">
                <div>
                    <p v-if="form.errors.cart" class="mb-4 text-sm text-red-600">{{ form.errors.cart }}</p>
                    <p v-if="form.errors.shipping_method_id" class="mb-4 text-sm text-red-600">{{ form.errors.shipping_method_id }}</p>
                    <p v-if="shippingMethods.length === 0" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-300">
                        Nenhuma forma de envio disponível no momento. Entre em contato com a loja antes de continuar.
                    </p>

                    <!-- Endereço -->
                    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <div class="flex items-start gap-3">
                            <input type="radio" checked disabled class="mt-1.5 h-4 w-4 accent-store-accent">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-semibold text-store-fg">Enviar para o endereço</span>
                                    <span class="font-store text-sm font-semibold" :class="shippingCost === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-store-fg'">
                                        {{ shippingCost === 0 ? 'Frete Grátis' : formatPrice(shippingCost) }}
                                    </span>
                                </div>

                                <template v-if="!useNewAddress && selectedAddress">
                                    <p class="mt-1 text-sm text-store-fg-muted">{{ addressLine(selectedAddress) }}</p>
                                    <span v-if="selectedAddress.label" class="mt-1 inline-block rounded-full bg-store-bg-sunken px-2 py-0.5 text-xs text-store-fg-muted">
                                        {{ selectedAddress.label }}
                                    </span>
                                </template>
                                <p v-else class="mt-1 text-sm text-store-fg-muted">Endereço novo — preencha abaixo.</p>
                            </div>
                        </div>

                        <hr class="my-4 border-store-border">

                        <button type="button" class="text-sm font-medium text-store-accent hover:underline" @click="showAddressPicker = true">
                            Alterar ou escolher outro endereço
                        </button>
                    </div>

                    <!-- Visitante: identificação -->
                    <div v-if="guest" class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h2 class="mb-4 text-sm font-semibold uppercase text-store-fg-muted">Seus dados</h2>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="text-sm font-medium">Nome completo</label>
                                <input v-model="form.guest.name" type="text" required :class="inputClass">
                                <InputError :message="form.errors['guest.name']" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">E-mail</label>
                                <input v-model="form.guest.email" type="email" required :class="inputClass">
                                <InputError :message="form.errors['guest.email']" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">CPF</label>
                                <input v-model="form.guest.cpf" type="text" required :class="inputClass">
                                <InputError :message="form.errors['guest.cpf']" />
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-store-fg-muted">
                            Vamos criar sua conta automaticamente ao finalizar a compra, com esse e-mail — você recebe um link por e-mail pra definir sua senha depois.
                        </p>
                    </div>

                    <!-- Novo endereço -->
                    <div v-if="useNewAddress" class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h2 class="mb-4 text-sm font-semibold uppercase text-store-fg-muted">Endereço de entrega</h2>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label class="text-sm font-medium">Apelido (opcional)</label>
                                <input v-model="form.new_address.label" type="text" placeholder="Casa, Trabalho..." :class="inputClass">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-sm font-medium">Nome do destinatário</label>
                                <input v-model="form.new_address.recipient_name" type="text" required :class="inputClass">
                                <InputError :message="form.errors['new_address.recipient_name']" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">Telefone</label>
                                <input v-model="form.new_address.phone" type="tel" required :class="inputClass">
                                <InputError :message="form.errors['new_address.phone']" />
                            </div>
                            <div>
                                <label class="text-sm font-medium">CEP</label>
                                <div class="relative">
                                    <input :value="form.new_address.zip" type="text" inputmode="numeric" maxlength="9" placeholder="00000-000" required :class="inputClass" @input="onCepInput">
                                    <i v-if="cepLoading" class="fas fa-spinner absolute right-3 top-1/2 -translate-y-1/2 animate-spin text-xs text-store-fg-muted"></i>
                                </div>
                                <InputError :message="form.errors['new_address.zip']" />
                                <p v-if="cepError" class="mt-1 text-xs text-red-600">{{ cepError }}</p>
                            </div>
                            <div class="sm:col-span-2 sm:grid sm:grid-cols-3 sm:gap-4">
                                <div class="sm:col-span-2">
                                    <label class="text-sm font-medium">Rua</label>
                                    <input v-model="form.new_address.street" type="text" required :class="inputClass">
                                    <InputError :message="form.errors['new_address.street']" />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">Número</label>
                                    <input v-model="form.new_address.number" type="text" required :class="inputClass">
                                    <InputError :message="form.errors['new_address.number']" />
                                </div>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="text-sm font-medium">Complemento</label>
                                <input v-model="form.new_address.complement" type="text" :class="inputClass">
                            </div>
                            <div>
                                <label class="text-sm font-medium">Bairro</label>
                                <input v-model="form.new_address.neighborhood" type="text" required :class="inputClass">
                                <InputError :message="form.errors['new_address.neighborhood']" />
                            </div>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="col-span-2">
                                    <label class="text-sm font-medium">Cidade</label>
                                    <input v-model="form.new_address.city" type="text" required :class="inputClass">
                                    <InputError :message="form.errors['new_address.city']" />
                                </div>
                                <div>
                                    <label class="text-sm font-medium">UF</label>
                                    <input v-model="form.new_address.state" type="text" maxlength="2" required class="uppercase" :class="inputClass">
                                    <InputError :message="form.errors['new_address.state']" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Forma de envio -->
                    <div v-if="shippingMethods.length > 1" class="mt-6 rounded-2xl border border-store-border bg-store-bg-raised p-5">
                        <h2 class="mb-3 text-sm font-semibold uppercase text-store-fg-muted">Forma de envio</h2>
                        <label v-for="method in shippingMethods" :key="method.id"
                            class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-colors"
                            :class="form.shipping_method_id === method.id ? 'border-store-accent bg-store-accent/5' : 'border-store-border hover:border-store-border-strong'">
                            <input v-model="form.shipping_method_id" type="radio" :value="method.id" class="h-4 w-4 accent-store-accent">
                            <span class="flex-1 text-sm">{{ method.name }} · até {{ method.estimated_days }} dia{{ method.estimated_days === 1 ? '' : 's' }}</span>
                            <span class="font-store text-sm font-semibold" :class="Number(method.price) === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-store-fg'">
                                {{ Number(method.price) === 0 ? 'Frete Grátis' : formatPrice(method.price) }}
                            </span>
                        </label>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <button type="button" :disabled="form.processing || shippingMethods.length === 0"
                            class="rounded-lg bg-store-accent px-6 py-3 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                            @click="submit">
                            Continuar
                        </button>
                    </div>
                </div>

                <!-- Resumo da compra -->
                <div class="rounded-2xl border border-store-border bg-store-bg-raised p-6 lg:sticky lg:top-24">
                    <h2 class="mb-4 font-display text-lg font-semibold">Resumo da compra</h2>
                    <div class="flex flex-col gap-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-store-fg-muted">Produto</span>
                            <span class="font-store-mono">{{ formatPrice(total + productsDiscount) }}</span>
                        </div>
                        <div v-if="productsDiscount > 0" class="flex justify-between text-emerald-600 dark:text-emerald-400">
                            <span>Desconto do produto</span>
                            <span class="font-store-mono">-{{ formatPrice(productsDiscount) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-store-fg-muted">Frete</span>
                            <span class="font-store-mono" :class="shippingCost === 0 ? 'text-emerald-600 dark:text-emerald-400' : ''">
                                {{ shippingCost === 0 ? 'Grátis' : formatPrice(shippingCost) }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-4 flex items-baseline justify-between border-t border-store-border pt-4">
                        <span class="font-semibold">Total</span>
                        <span class="text-lg font-semibold text-store-accent">{{ formatPrice(finalTotal) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Escolher/alterar endereço -->
        <Modal :open="showAddressPicker" max-width="max-w-[520px]" @close="showAddressPicker = false">
            <h3 class="font-display text-xl font-semibold">Escolher endereço</h3>
            <div class="mt-4 flex flex-col gap-3">
                <button v-for="address in addresses" :key="address.id" type="button"
                    class="rounded-xl border p-4 text-left transition-colors"
                    :class="form.address_id === address.id && !useNewAddress ? 'border-store-accent bg-store-accent/5' : 'border-store-border hover:border-store-border-strong'"
                    @click="selectExistingAddress(address)">
                    <span v-if="address.label" class="mb-1 inline-block rounded-full bg-store-bg-sunken px-2 py-0.5 text-xs text-store-fg-muted">{{ address.label }}</span>
                    <p class="text-sm font-medium">{{ address.recipient_name }}</p>
                    <p class="text-sm text-store-fg-muted">{{ addressLine(address) }}</p>
                </button>

                <button type="button"
                    class="rounded-xl border border-dashed p-4 text-center text-sm font-medium text-store-accent transition-colors"
                    :class="useNewAddress ? 'border-store-accent bg-store-accent/5' : 'border-store-border-strong hover:border-store-accent'"
                    @click="selectNewAddress">
                    <i class="fas fa-plus mr-1.5 text-xs"></i>
                    Cadastrar novo endereço
                </button>
            </div>
        </Modal>
    </AppLayout>
</template>
