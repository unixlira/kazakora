<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { maskCep, useCep } from '@/Shared/useCep';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    channels: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const channelLabels = {
    loja: 'Site (venda manual)',
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
    amazon: 'Amazon',
    shein: 'Shein',
};

const statusLabels = {
    paid: 'Pago',
    shipped: 'Enviado',
    completed: 'Concluído',
};

const form = useForm({
    origin: props.channels[0] ?? 'loja',
    external_order_id: '',
    status: 'paid',
    buyer_document: '',
    buyer_name: '',
    buyer_phone: '',
    buyer_email: '',
    buyer_whatsapp: '',
    shipping_zip: '',
    shipping_street: '',
    shipping_number: '',
    shipping_complement: '',
    shipping_neighborhood: '',
    shipping_city: '',
    shipping_state: '',
    shipping_cost: 0,
    items: [],
});

const { loading: cepLoading, error: cepError, lookup: lookupCep } = useCep();

const onCepInput = async (event) => {
    form.shipping_zip = maskCep(event.target.value);

    if (form.shipping_zip.replace(/\D/g, '').length !== 8) {
        return;
    }

    const result = await lookupCep(form.shipping_zip);

    if (result) {
        form.shipping_street = result.street;
        form.shipping_neighborhood = result.neighborhood;
        form.shipping_city = result.city;
        form.shipping_state = result.state;
    }
};

const itemFilters = ref([]);

const filteredProducts = (index) => {
    const term = (itemFilters.value[index] ?? '').trim().toLowerCase();

    if (!term) {
        return props.products;
    }

    return props.products.filter((product) =>
        product.name.toLowerCase().includes(term) || (product.sku ?? '').toLowerCase().includes(term));
};

const addItem = () => {
    form.items.push({ product_id: null, quantity: 1, unit_price: 0 });
    itemFilters.value.push('');
};

const removeItem = (index) => {
    form.items.splice(index, 1);
    itemFilters.value.splice(index, 1);
};

const onProductSelected = (index) => {
    const product = props.products.find((item) => item.id === form.items[index].product_id);

    if (product) {
        form.items[index].unit_price = product.price;
    }
};

const formatPrice = (value) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);

const subtotal = computed(() => form.items.reduce((sum, item) => sum + (item.quantity || 0) * (item.unit_price || 0), 0));
const total = computed(() => subtotal.value + Number(form.shipping_cost || 0));

const submit = () => {
    form.post('/admin/pedidos/criar');
};
</script>

<template>
    <Head title="Novo pedido manual" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">Adicionar pedido manualmente</h1>
        <p class="mb-4 text-sm text-slate-500">
            Use isso pra registrar uma venda que não passou pelo checkout do site nem por uma integração de
            marketplace conectada (ex: canal ainda sem credencial configurada, venda combinada por fora). O pedido
            criado aqui segue o mesmo fluxo dos demais — estoque é debitado e a nota fiscal sai sozinha se o status
            já for "Pago".
        </p>

        <form class="max-w-4xl space-y-6" @submit.prevent="submit">
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Origem</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Canal</label>
                        <select v-model="form.origin" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <option v-for="channel in props.channels" :key="channel" :value="channel">
                                {{ channelLabels[channel] ?? channel }}
                            </option>
                        </select>
                        <InputError :message="form.errors.origin" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">ID do pedido no canal (opcional)</label>
                        <input v-model="form.external_order_id" type="text" placeholder="Deixe em branco se não vier de um marketplace"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.external_order_id" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Status</label>
                        <select v-model="form.status" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <option v-for="status in props.statuses" :key="status" :value="status">
                                {{ statusLabels[status] ?? status }}
                            </option>
                        </select>
                        <InputError :message="form.errors.status" />
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Comprador</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Nome</label>
                        <input v-model="form.buyer_name" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.buyer_name" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">CPF/CNPJ</label>
                        <input :value="form.buyer_document" type="text" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            @input="form.buyer_document = maskCpfCnpj($event.target.value)">
                        <InputError :message="form.errors.buyer_document" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Telefone</label>
                        <input :value="form.buyer_phone" type="text" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            @input="form.buyer_phone = maskPhone($event.target.value)">
                        <InputError :message="form.errors.buyer_phone" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">WhatsApp (opcional)</label>
                        <input :value="form.buyer_whatsapp" type="text"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                            @input="form.buyer_whatsapp = maskPhone($event.target.value)">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">E-mail (opcional)</label>
                        <input v-model="form.buyer_email" type="email" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.buyer_email" />
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Endereço de entrega</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-600">CEP</label>
                        <input :value="form.shipping_zip" type="text" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" @input="onCepInput">
                        <p v-if="cepLoading" class="mt-1 text-xs text-slate-400">Buscando...</p>
                        <p v-if="cepError" class="mt-1 text-xs text-red-600">{{ cepError }}</p>
                        <InputError :message="form.errors.shipping_zip" />
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-slate-600">Rua</label>
                        <input v-model="form.shipping_street" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_street" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Número</label>
                        <input v-model="form.shipping_number" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_number" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Complemento</label>
                        <input v-model="form.shipping_complement" type="text" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Bairro</label>
                        <input v-model="form.shipping_neighborhood" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_neighborhood" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Cidade</label>
                        <input v-model="form.shipping_city" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_city" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-600">UF</label>
                        <input v-model="form.shipping_state" type="text" maxlength="2" required
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm uppercase">
                        <InputError :message="form.errors.shipping_state" />
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Itens</h2>
                <InputError :message="form.errors.items" />

                <div v-if="form.items.length" class="space-y-3">
                    <div v-for="(item, index) in form.items" :key="index"
                        class="grid grid-cols-1 gap-3 rounded border border-[var(--surface-border)] p-3 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-end">
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Produto</label>
                            <input v-model="itemFilters[index]" type="text" placeholder="Filtrar..."
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-1.5 text-xs">
                            <select v-model.number="item.product_id" required
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm"
                                @change="onProductSelected(index)">
                                <option :value="null" disabled>Selecione um produto</option>
                                <option v-for="product in filteredProducts(index)" :key="product.id" :value="product.id">
                                    {{ product.sku }} — {{ product.name }} (estoque: {{ product.stock }})
                                </option>
                            </select>
                            <InputError :message="form.errors[`items.${index}.product_id`]" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Quantidade</label>
                            <input v-model.number="item.quantity" type="number" min="1" step="1" required
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <InputError :message="form.errors[`items.${index}.quantity`]" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-500">Preço unitário</label>
                            <input v-model.number="item.unit_price" type="number" min="0" step="0.01" required
                                class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <InputError :message="form.errors[`items.${index}.unit_price`]" />
                        </div>
                        <button type="button" class="flex h-10 w-10 items-center justify-center rounded text-red-600 hover:bg-red-50"
                            aria-label="Remover item" @click="removeItem(index)">
                            <i class="fas fa-trash text-sm"></i>
                        </button>
                    </div>
                </div>
                <p v-else class="text-sm text-slate-500">Nenhum item adicionado ainda.</p>

                <button type="button" class="mt-3 rounded border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="addItem">
                    <i class="fas fa-plus mr-1.5 text-xs"></i>
                    Adicionar item
                </button>
            </div>

            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-4">
                <h2 class="mb-3 font-semibold">Totais</h2>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="block text-sm font-medium text-slate-600">Frete</label>
                        <input v-model.number="form.shipping_cost" type="number" min="0" step="0.01"
                            class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <InputError :message="form.errors.shipping_cost" />
                    </div>
                    <div>
                        <span class="block text-sm font-medium text-slate-600">Subtotal</span>
                        <p class="mt-1 py-2 text-sm">{{ formatPrice(subtotal) }}</p>
                    </div>
                    <div>
                        <span class="block text-sm font-medium text-slate-600">Total</span>
                        <p class="mt-1 py-2 text-sm font-semibold">{{ formatPrice(total) }}</p>
                    </div>
                </div>
            </div>

            <div>
                <button type="submit" :disabled="form.processing || form.items.length === 0"
                    class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                    Criar pedido
                </button>
            </div>
        </form>
    </AdminLayout>
</template>
