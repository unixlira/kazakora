<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    serviceOptions: { type: Array, required: true },
    configured: { type: Boolean, required: true },
    // Presente só na tela de edição (/admin/correios/{id}/editar) — reabre
    // uma tentativa que falhou pra corrigir e tentar de novo, ver
    // CorreiosController::edit().
    editing: { type: Object, default: null },
});

const form = useForm(props.editing ? {
    order_id: props.editing.orderId,
    origin: props.editing.origin,
    external_order_id: props.editing.externalOrderId,
    customer: { ...props.editing.customer },
    address: { ...props.editing.address },
    service_code: props.editing.serviceCode,
    weight_grams: props.editing.weightGrams,
    dimensions: { ...props.editing.dimensions },
    content_items: props.editing.contentItems.map((item) => ({ ...item })),
} : {
    order_id: null,
    origin: null,
    external_order_id: null,
    customer: { name: '', document: '', phone: '', email: '' },
    address: { zip: '', street: '', number: '', complement: '', neighborhood: '', city: '', state: '' },
    service_code: props.serviceOptions[0]?.value ?? '',
    weight_grams: '',
    dimensions: { format: '2', height: '', width: '', length: '', diameter: '' },
    content_items: [{ conteudo: '', quantidade: 1, valor: '' }],
});

// Busca de pedido — pré-preenche cliente/endereço/itens, mas continua tudo
// editável depois (dados podem precisar de ajuste antes de ir pros
// Correios). Não usa Inertia pra essa busca: é só um preenchimento de
// formulário já aberto, não uma navegação.
const orderNumber = ref('');
const searching = ref(false);
const searchError = ref('');

const buscarPedido = async () => {
    if (! orderNumber.value.trim()) {
        return;
    }

    searching.value = true;
    searchError.value = '';

    try {
        const response = await fetch(`/admin/correios/buscar-pedido?numero=${encodeURIComponent(orderNumber.value.trim())}`, {
            headers: { Accept: 'application/json' },
        });
        const data = await response.json();

        if (! response.ok) {
            searchError.value = data.message ?? 'Pedido não encontrado.';
            return;
        }

        form.order_id = data.orderId;
        form.origin = data.origin;
        form.external_order_id = data.externalOrderId;
        form.customer = { ...data.customer };
        form.address = { ...data.address };

        if (data.items.length) {
            // `maxlength="60"` no <input> só trava digitação manual — nome de
            // produto vindo do pedido chega aqui por atribuição direta (JS),
            // que ignora o atributo, então precisa truncar na mão (mesmo
            // limite que o backend exige em content_items.*.conteudo).
            form.content_items = data.items.map((item) => ({
                conteudo: item.conteudo.slice(0, 60),
                quantidade: item.quantidade,
                valor: item.valor,
            }));
        }
    } catch (error) {
        searchError.value = 'Falha ao buscar o pedido. Tente novamente.';
    } finally {
        searching.value = false;
    }
};

const addItem = () => {
    form.content_items.push({ conteudo: '', quantidade: 1, valor: '' });
};

const removeItem = (index) => {
    form.content_items.splice(index, 1);
};

const submit = () => {
    if (props.editing) {
        form.put(`/admin/correios/${props.editing.id}`);
    } else {
        form.post('/admin/correios');
    }
};
</script>

<template>
    <Head :title="editing ? 'Corrigir e tentar de novo — Correios' : 'Gerar QR Code — Correios'" />

    <AdminLayout>
        <div class="mb-6">
            <h1 class="mb-1 text-2xl font-bold">{{ editing ? 'Corrigir e tentar de novo' : 'Gerar QR Code de pré-postagem' }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">Cria a pré-postagem direto na API dos Correios e gera o QR Code pra levar na agência.</p>
        </div>

        <div v-if="editing?.errorMessage" class="mb-6 rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
            <i class="fas fa-triangle-exclamation mr-1.5"></i>
            <strong>Falhou da última vez:</strong> {{ editing.errorMessage }}
        </div>

        <div v-if="!configured" class="mb-6 rounded-xl border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">
            <i class="fas fa-triangle-exclamation mr-1.5"></i>
            Credenciais dos Correios ainda não configuradas (CORREIOS_NUMERO_USUARIO/CODIGO_ACESSO no .env). A geração vai falhar até isso ser preenchido.
        </div>

        <div class="mx-auto max-w-3xl space-y-6">
            <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h3 class="mb-1 text-base font-semibold">Puxar dados de um pedido (opcional)</h3>
                <p class="mb-3 text-xs text-slate-400">Número interno ou o número do pedido no marketplace — preenche cliente, endereço e itens abaixo (tudo continua editável).</p>
                <div class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Número do pedido</label>
                        <input v-model="orderNumber" type="text" placeholder="Ex: 215 ou 2000017855498108"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm"
                            @keyup.enter="buscarPedido" />
                    </div>
                    <button type="button" :disabled="searching"
                        class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)] disabled:opacity-50"
                        @click="buscarPedido">
                        <i class="fas fa-magnifying-glass mr-1.5"></i>
                        {{ searching ? 'Buscando…' : 'Buscar' }}
                    </button>
                </div>
                <p v-if="searchError" class="mt-2 text-sm text-error">{{ searchError }}</p>
                <p v-if="form.external_order_id" class="mt-2 text-sm text-success">
                    <i class="fas fa-circle-check mr-1"></i> Pedido {{ form.external_order_id }} carregado.
                </p>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h3 class="mb-4 text-base font-semibold">Destinatário</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Nome completo</label>
                            <input v-model="form.customer.name" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors['customer.name']" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">CPF/CNPJ</label>
                            <input v-model="form.customer.document" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Telefone</label>
                            <input v-model="form.customer.phone" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">E-mail</label>
                            <input v-model="form.customer.email" type="email"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h3 class="mb-4 text-base font-semibold">Endereço de entrega</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">CEP</label>
                            <input v-model="form.address.zip" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors['address.zip']" />
                        </div>
                        <div class="sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Logradouro</label>
                            <input v-model="form.address.street" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors['address.street']" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Número</label>
                            <input v-model="form.address.number" type="text" maxlength="6"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors['address.number']" />
                        </div>
                        <div class="sm:col-span-4">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Complemento</label>
                            <input v-model="form.address.complement" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                        </div>
                        <div class="sm:col-span-3">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Bairro</label>
                            <input v-model="form.address.neighborhood" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors['address.neighborhood']" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Cidade</label>
                            <input v-model="form.address.city" type="text"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors['address.city']" />
                        </div>
                        <div class="sm:col-span-1">
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">UF</label>
                            <input v-model="form.address.state" type="text" maxlength="2"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm uppercase" />
                            <InputError :message="form.errors['address.state']" />
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h3 class="mb-4 text-base font-semibold">Envio</h3>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Serviço</label>
                            <select v-model="form.service_code"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm">
                                <option v-for="option in serviceOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Peso (gramas)</label>
                            <input v-model.number="form.weight_grams" type="number" min="1" max="30000"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            <InputError :message="form.errors.weight_grams" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Formato</label>
                            <select v-model="form.dimensions.format"
                                class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm">
                                <option value="2">Caixa / Pacote</option>
                                <option value="1">Envelope</option>
                                <option value="3">Rolo / Cilindro</option>
                            </select>
                        </div>

                        <template v-if="form.dimensions.format === '2'">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Altura (cm)</label>
                                <input v-model.number="form.dimensions.height" type="number" min="0" step="0.1"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Largura (cm)</label>
                                <input v-model.number="form.dimensions.width" type="number" min="0" step="0.1"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Comprimento (cm)</label>
                                <input v-model.number="form.dimensions.length" type="number" min="0" step="0.1"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                        </template>
                        <template v-else-if="form.dimensions.format === '3'">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Diâmetro (cm)</label>
                                <input v-model.number="form.dimensions.diameter" type="number" min="0" step="0.1"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Comprimento (cm)</label>
                                <input v-model.number="form.dimensions.length" type="number" min="0" step="0.1"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                        </template>
                    </div>
                </div>

                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h3 class="mb-1 text-base font-semibold">Declaração de conteúdo</h3>
                    <p class="mb-4 text-xs text-slate-400">O que tem dentro do pacote — exigido pelos Correios.</p>

                    <div class="flex flex-col gap-3">
                        <div v-for="(item, index) in form.content_items" :key="index" class="flex items-end gap-3">
                            <div class="flex-1">
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Conteúdo</label>
                                <input v-model="item.conteudo" type="text" maxlength="60"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                                <InputError :message="form.errors[`content_items.${index}.conteudo`]" />
                            </div>
                            <div class="w-24">
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Qtd.</label>
                                <input v-model.number="item.quantidade" type="number" min="1"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                            <div class="w-32">
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Valor unit. (R$)</label>
                                <input v-model.number="item.valor" type="number" min="0.01" step="0.01"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm" />
                            </div>
                            <button type="button" class="mb-1 flex h-10 w-10 items-center justify-center rounded-lg text-error hover:bg-red-50 dark:hover:bg-red-900/30"
                                aria-label="Remover item" @click="removeItem(index)">
                                <i class="fas fa-trash text-sm"></i>
                            </button>
                        </div>
                    </div>

                    <button type="button" class="mt-3 rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]"
                        @click="addItem">
                        <i class="fas fa-plus mr-1.5 text-xs"></i>
                        Adicionar item
                    </button>
                </div>

                <div class="flex justify-end">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-primary px-6 py-2.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        <i class="fas fa-qrcode mr-1.5"></i>
                        {{ form.processing ? 'Gerando…' : (editing ? 'Tentar de novo' : 'Gerar QR Code') }}
                    </button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
