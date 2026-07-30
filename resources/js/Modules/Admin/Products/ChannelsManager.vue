<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
    channels: {
        type: Array,
        default: () => [],
    },
    channelListings: {
        type: Array,
        default: () => [],
    },
});

const channelLabels = {
    mercado_livre: 'Mercado Livre',
    shopee: 'Shopee',
    tiktok_shop: 'TikTok Shop',
};

const statusLabels = {
    draft: 'Não publicado',
    pending: 'Publicando…',
    published: 'Publicado',
    error: 'Erro',
};

const statusColors = {
    draft: 'bg-slate-100 text-slate-500',
    pending: 'bg-amber-100 text-amber-700',
    published: 'bg-emerald-100 text-emerald-700',
    error: 'bg-red-100 text-red-700',
};

const listingFor = (channel) => props.channelListings.find((listing) => listing.channel === channel);

const forms = Object.fromEntries(props.channels.map((channel) => {
    const listing = listingFor(channel);

    return [channel, useForm({
        is_enabled: listing?.is_enabled ?? false,
        attributes: listing?.attributes ? JSON.stringify(listing.attributes, null, 2) : '{}',
    })];
}));

const submit = (channel) => {
    const form = forms[channel];

    let attributes = {};
    try {
        attributes = JSON.parse(form.attributes || '{}');
    } catch (e) {
        alert('O campo de atributos precisa ser um JSON válido.');
        return;
    }

    form.transform((data) => ({ ...data, attributes })).put(
        `/admin/produtos/${props.product.id}/canais/${channel}`,
        { preserveScroll: true },
    );
};
</script>

<template>
    <div class="space-y-6">
        <div v-for="channel in channels" :key="channel" class="rounded border border-slate-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-semibold text-slate-700">{{ channelLabels[channel] ?? channel }}</h3>
                    <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs"
                        :class="statusColors[listingFor(channel)?.status ?? 'draft']">
                        {{ statusLabels[listingFor(channel)?.status ?? 'draft'] }}
                    </span>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                    <input v-model="forms[channel].is_enabled" type="checkbox">
                    Publicar neste canal
                </label>
            </div>

            <p v-if="listingFor(channel)?.last_error" class="mt-2 rounded bg-red-50 p-2 text-xs text-red-600">
                {{ listingFor(channel).last_error }}
            </p>

            <div class="mt-3">
                <label class="block text-xs font-medium uppercase text-slate-400">
                    Atributos específicos do canal (JSON)
                </label>
                <p v-if="channel === 'mercado_livre'" class="mt-1 text-xs text-slate-400">
                    Deixe "category_id" de fora para o sistema tentar identificar a categoria automaticamente pelo nome do produto ao publicar. Só preencha na mão se quiser forçar uma categoria específica.
                </p>
                <textarea v-model="forms[channel].attributes" rows="4"
                    class="mt-1 w-full rounded border border-slate-300 px-3 py-2 font-mono text-xs"
                    placeholder='{"condition": "new", "category_id": "..."}'
                />
            </div>

            <button type="button" :disabled="forms[channel].processing"
                class="mt-3 rounded bg-slate-700 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-600 disabled:cursor-not-allowed disabled:bg-slate-300"
                @click="submit(channel)">
                Salvar canal
            </button>
        </div>
    </div>
</template>
