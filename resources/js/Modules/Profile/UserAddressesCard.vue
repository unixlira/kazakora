<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Modal from '@/Shared/Modal.vue';
import { confirmDelete } from '@/Shared/notify';
import { maskCep, useCep } from '@/Shared/useCep';

const props = defineProps({
    addresses: {
        type: Array,
        default: () => [],
    },
    editable: {
        type: Boolean,
        default: true,
    },
});

const isOpen = ref(false);
const editing = ref(null);
const { loading: cepLoading, error: cepError, lookup } = useCep();

const emptyAddress = () => ({
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
    is_default: false,
});

const form = useForm(emptyAddress());

const openNew = () => {
    editing.value = null;
    form.defaults(emptyAddress());
    form.reset();
    form.clearErrors();
    form.is_default = props.addresses.length === 0;
    isOpen.value = true;
};

const openEdit = (address) => {
    editing.value = address;
    form.clearErrors();
    Object.keys(emptyAddress()).forEach((key) => {
        form[key] = address[key] ?? (key === 'is_default' ? false : '');
    });
    isOpen.value = true;
};

const closeModal = () => (isOpen.value = false);

const onZipInput = async (event) => {
    form.zip = maskCep(event.target.value);

    const found = await lookup(form.zip);
    if (found) {
        form.street = found.street || form.street;
        form.neighborhood = found.neighborhood || form.neighborhood;
        form.city = found.city || form.city;
        form.state = found.state || form.state;
    }
};

// Mesmo cuidado do checkout: UF vai maiúscula de verdade, não só no CSS.
const onStateInput = (event) => {
    form.state = event.target.value.toUpperCase().slice(0, 2);
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: closeModal };

    if (editing.value) {
        form.put(`/perfil/enderecos/${editing.value.id}`, options);
    } else {
        form.post('/perfil/enderecos', options);
    }
};

const makeDefault = (address) => {
    router.put(`/perfil/enderecos/${address.id}`, { ...address, is_default: true }, { preserveScroll: true });
};

const remove = async (address) => {
    if (address.tem_pedido) return;

    if (await confirmDelete({ text: 'O endereço será excluído da sua conta.', confirmButtonText: 'Sim, excluir' })) {
        router.delete(`/perfil/enderecos/${address.id}`, { preserveScroll: true });
    }
};

const streetLine = (address) => `${address.street}, ${address.number}${address.complement ? ` - ${address.complement}` : ''}`;

const inputClass = 'mt-1 w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3 py-2 text-sm focus:border-store-accent focus:outline-none focus:ring-1 focus:ring-store-accent';
</script>

<template>
    <div class="flex flex-1 flex-col">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
            <div>
                <h2 class="text-lg font-semibold">Endereços</h2>
                <p class="text-sm text-store-fg-muted">Onde você recebe suas compras.</p>
            </div>
            <button v-if="editable" type="button"
                class="shrink-0 rounded-full bg-store-accent px-4 py-2 text-sm font-semibold text-store-accent-contrast hover:opacity-90"
                @click="openNew">
                <i class="fas fa-plus mr-2 text-xs" aria-hidden="true"></i>Novo endereço
            </button>
        </div>

        <div v-if="addresses.length === 0" class="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-store-border-strong px-6 py-10 text-center">
            <i class="fas fa-location-dot mb-3 text-2xl text-store-fg-muted" aria-hidden="true"></i>
            <p class="text-sm font-medium">Nenhum endereço cadastrado</p>
            <p class="mt-1 text-sm text-store-fg-muted">Cadastre um endereço para agilizar a próxima compra.</p>
        </div>

        <ul v-else class="flex flex-col gap-3">
            <li v-for="address in addresses" :key="address.id"
                class="rounded-xl border p-4"
                :class="address.is_default ? 'border-store-accent bg-store-accent-soft/40' : 'border-store-border'">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="text-sm font-semibold">{{ address.label || 'Endereço' }}</p>
                    <span v-if="address.is_default" class="rounded-full bg-store-accent px-2 py-0.5 text-[11px] font-semibold text-store-accent-contrast">Principal</span>
                    <span v-if="address.tem_pedido" :id="`endereco-${address.id}-usado`" class="rounded-full bg-store-bg-sunken px-2 py-0.5 text-[11px] font-medium text-store-fg-muted"
                        title="Pode ser editado, mas não excluído.">Já usado em pedido<span class="sr-only">: pode ser editado, mas não excluído.</span></span>
                </div>
                <p class="mt-2 text-sm">{{ address.recipient_name }}<span v-if="address.phone" class="text-store-fg-muted"> · {{ address.phone }}</span></p>
                <p class="text-sm text-store-fg-muted">{{ streetLine(address) }}</p>
                <p class="text-sm text-store-fg-muted">{{ address.neighborhood }} · {{ address.city }} - {{ address.state }} · CEP {{ address.zip }}</p>

                <div v-if="editable" class="mt-3 flex flex-wrap items-center gap-2 border-t border-store-border pt-3">
                    <button type="button" class="rounded-full border border-store-border-strong px-3 py-1.5 text-xs font-medium hover:bg-store-bg-sunken" @click="openEdit(address)">
                        <i class="fas fa-pen mr-1.5 text-[10px]" aria-hidden="true"></i>Editar
                    </button>
                    <button v-if="!address.is_default" type="button" class="rounded-full border border-store-border-strong px-3 py-1.5 text-xs font-medium hover:bg-store-bg-sunken" @click="makeDefault(address)">
                        Tornar principal
                    </button>
                    <button type="button"
                        class="ml-auto rounded-full px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:text-store-fg-muted disabled:hover:bg-transparent"
                        :disabled="address.tem_pedido"
                        :aria-describedby="address.tem_pedido ? `endereco-${address.id}-usado` : undefined"
                        :title="address.tem_pedido ? 'Já usado em pedido, não pode ser excluído.' : 'Excluir endereço'"
                        @click="remove(address)">
                        <i class="fas mr-1.5 text-[10px]" :class="address.tem_pedido ? 'fa-lock' : 'fa-trash'" aria-hidden="true"></i>Excluir
                    </button>
                </div>
            </li>
        </ul>
    </div>

    <Modal :open="isOpen" @close="closeModal">
        <h2 class="mb-1 text-xl font-semibold">{{ editing ? 'Editar endereço' : 'Novo endereço' }}</h2>
        <p class="mb-6 text-sm text-store-fg-muted">Digite o CEP que a gente completa rua, bairro e cidade.</p>

        <form class="grid grid-cols-1 gap-4 sm:grid-cols-6" @submit.prevent="submit">
            <div class="sm:col-span-3">
                <label for="end-apelido" class="text-sm font-medium">Apelido <span class="font-normal text-store-fg-muted">(opcional)</span></label>
                <input id="end-apelido" v-model="form.label" type="text" maxlength="60" placeholder="Casa, Trabalho..." autocomplete="off" :class="inputClass">
                <p v-if="form.errors.label" class="mt-1 text-xs text-red-600">{{ form.errors.label }}</p>
            </div>
            <div class="sm:col-span-3">
                <label for="end-cep" class="text-sm font-medium">CEP</label>
                <input id="end-cep" :value="form.zip" type="text" inputmode="numeric" maxlength="9" required placeholder="00000-000" autocomplete="postal-code" :class="inputClass" @input="onZipInput">
                <p v-if="cepLoading" class="mt-1 text-xs text-store-fg-muted">Buscando CEP...</p>
                <p v-else-if="cepError" class="mt-1 text-xs text-red-600">{{ cepError }}</p>
                <p v-if="form.errors.zip" class="mt-1 text-xs text-red-600">{{ form.errors.zip }}</p>
            </div>
            <div class="sm:col-span-4">
                <label for="end-rua" class="text-sm font-medium">Rua</label>
                <input id="end-rua" v-model="form.street" type="text" required autocomplete="address-line1" :class="inputClass">
                <p v-if="form.errors.street" class="mt-1 text-xs text-red-600">{{ form.errors.street }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="end-numero" class="text-sm font-medium">Número</label>
                <input id="end-numero" v-model="form.number" type="text" required maxlength="20" autocomplete="off" :class="inputClass">
                <p v-if="form.errors.number" class="mt-1 text-xs text-red-600">{{ form.errors.number }}</p>
            </div>
            <div class="sm:col-span-3">
                <label for="end-complemento" class="text-sm font-medium">Complemento <span class="font-normal text-store-fg-muted">(opcional)</span></label>
                <input id="end-complemento" v-model="form.complement" type="text" autocomplete="address-line2" :class="inputClass">
                <p v-if="form.errors.complement" class="mt-1 text-xs text-red-600">{{ form.errors.complement }}</p>
            </div>
            <div class="sm:col-span-3">
                <label for="end-bairro" class="text-sm font-medium">Bairro</label>
                <input id="end-bairro" v-model="form.neighborhood" type="text" required autocomplete="address-level3" :class="inputClass">
                <p v-if="form.errors.neighborhood" class="mt-1 text-xs text-red-600">{{ form.errors.neighborhood }}</p>
            </div>
            <div class="sm:col-span-4">
                <label for="end-cidade" class="text-sm font-medium">Cidade</label>
                <input id="end-cidade" v-model="form.city" type="text" required autocomplete="address-level2" :class="inputClass">
                <p v-if="form.errors.city" class="mt-1 text-xs text-red-600">{{ form.errors.city }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="end-uf" class="text-sm font-medium">UF</label>
                <input id="end-uf" :value="form.state" type="text" required maxlength="2" placeholder="SP" autocomplete="address-level1" :class="inputClass" @input="onStateInput">
                <p v-if="form.errors.state" class="mt-1 text-xs text-red-600">{{ form.errors.state }}</p>
            </div>
            <div class="sm:col-span-4">
                <label for="end-destinatario" class="text-sm font-medium">Quem recebe</label>
                <input id="end-destinatario" v-model="form.recipient_name" type="text" required autocomplete="name" :class="inputClass">
                <p v-if="form.errors.recipient_name" class="mt-1 text-xs text-red-600">{{ form.errors.recipient_name }}</p>
            </div>
            <div class="sm:col-span-2">
                <label for="end-telefone" class="text-sm font-medium">Telefone</label>
                <input id="end-telefone" v-model="form.phone" type="tel" required maxlength="20" placeholder="(11) 91234-5678" autocomplete="tel" :class="inputClass">
                <p v-if="form.errors.phone" class="mt-1 text-xs text-red-600">{{ form.errors.phone }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-6">
                <input v-model="form.is_default" type="checkbox" class="h-4 w-4 rounded border-store-border-strong text-store-accent focus:ring-store-accent"
                    :disabled="editing?.is_default">
                Usar como endereço principal
            </label>

            <div class="flex items-center justify-end gap-3 sm:col-span-6">
                <button type="button" class="rounded-full border border-store-border-strong px-5 py-2 text-sm font-medium hover:bg-store-bg-sunken" @click="closeModal">Cancelar</button>
                <button type="submit" :disabled="form.processing"
                    class="rounded-full bg-store-accent px-5 py-2 text-sm font-semibold text-store-accent-contrast hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                    {{ editing ? 'Salvar alterações' : 'Cadastrar endereço' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
