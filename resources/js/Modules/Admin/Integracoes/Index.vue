<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    integrations: { type: Array, default: () => [] },
});

const mercadoLivreTabs = [
    { label: 'Vendas', href: '/admin/integracoes/mercado-livre/vendas' },
    { label: 'Anúncios', href: '/admin/integracoes/mercado-livre/anuncios' },
    { label: 'Tipos de envio', href: '/admin/integracoes/mercado-livre/envios' },
    { label: 'Devoluções', href: '/admin/integracoes/mercado-livre/devolucoes' },
    { label: 'Impressão Full', href: '/admin/integracoes/mercado-livre/impressao-full' },
];

const importingShopeeProducts = ref(false);

const importShopeeProducts = () => {
    importingShopeeProducts.value = true;
    router.post('/admin/integracoes/shopee/importar-produtos', {}, {
        preserveScroll: true,
        onFinish: () => { importingShopeeProducts.value = false; },
    });
};

const disconnect = async (integration) => {
    if (await confirmDelete({
        title: `Desconectar ${integration.name}?`,
        text: 'A loja vai parar de sincronizar produtos, estoque e pedidos com esse canal.',
        confirmButtonText: 'Sim, desconectar',
    })) {
        router.delete(`/admin/integracoes/${integration.channel}`, { preserveScroll: true });
    }
};

// Amazon: app privado (não publicado na loja de apps) não usa o redirect
// OAuth — o vendedor gera o refresh token direto no Seller Central
// (Partner Network > Develop Apps > Autorizar) e cola aqui.
const showAmazonForm = ref(false);
const amazonForm = ref({ refresh_token: '', seller_id: '' });
const connectingAmazon = ref(false);

const connectAmazon = () => {
    connectingAmazon.value = true;
    router.post('/admin/integracoes/amazon/conectar', amazonForm.value, {
        preserveScroll: true,
        onFinish: () => { connectingAmazon.value = false; },
        onSuccess: () => { showAmazonForm.value = false; amazonForm.value = { refresh_token: '', seller_id: '' }; },
    });
};
</script>

<template>
    <Head title="Integrações" />

    <AdminLayout>
        <div class="mb-6 flex items-center justify-between">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Integrações</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">Marketplaces conectados à loja.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link href="/admin/integracoes/webhooks"
                    class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-lightprimary">
                    Logs de webhook
                </Link>
                <Link href="/admin/integracoes/teste-impressao"
                    class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-lightprimary">
                    Testar impressão de etiquetas
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <div v-for="integration in props.integrations" :key="integration.channel"
                class="flex flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm transition-shadow hover:shadow-md">
                <div class="flex items-start justify-between">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-lightprimary text-primary">
                        <i :class="integration.icon" class="text-xl"></i>
                    </div>
                    <StatusBadge :status="integration.connected ? 'active' : 'inactive'"
                        :label="integration.connected ? 'Conectado' : 'Não conectado'" />
                </div>

                <h3 class="mt-4 text-lg font-semibold">{{ integration.name }}</h3>
                <p class="mt-1 flex-1 text-sm text-slate-500 dark:text-slate-400">{{ integration.description }}</p>

                <p v-if="integration.connected && integration.accountLabel" class="mt-3 text-xs text-slate-400">
                    Conta: {{ integration.accountLabel }}
                </p>

                <div v-if="integration.connected && integration.channel === 'mercado_livre'" class="mt-3 flex flex-wrap gap-1.5">
                    <Link v-for="tab in mercadoLivreTabs" :key="tab.href" :href="tab.href"
                        class="rounded-full bg-lightprimary px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary hover:text-white">
                        {{ tab.label }}
                    </Link>
                </div>

                <div v-if="integration.connected && integration.channel === 'shopee'" class="mt-3 flex flex-wrap gap-1.5">
                    <button type="button" :disabled="importingShopeeProducts" @click="importShopeeProducts"
                        class="rounded-full bg-lightprimary px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary hover:text-white disabled:opacity-50">
                        {{ importingShopeeProducts ? 'Importando...' : 'Importar produtos' }}
                    </button>
                    <Link href="/admin/integracoes/webhooks?channel=shopee"
                        class="rounded-full bg-lightprimary px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary hover:text-white">
                        Logs de webhook
                    </Link>
                </div>

                <div class="mt-4">
                    <template v-if="!integration.connected && integration.available && integration.manualConnect">
                        <button type="button" class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                            @click="showAmazonForm = !showAmazonForm">
                            {{ showAmazonForm ? 'Cancelar' : 'Conectar com refresh token' }}
                        </button>
                        <form v-if="showAmazonForm" class="mt-3 space-y-2" @submit.prevent="connectAmazon">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Refresh token (Seller Central &gt; Autorizar)</label>
                                <input v-model="amazonForm.refresh_token" type="text" required
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500 dark:text-slate-400">Selling Partner ID (opcional)</label>
                                <input v-model="amazonForm.seller_id" type="text"
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-1.5 text-sm" />
                            </div>
                            <button type="submit" :disabled="connectingAmazon"
                                class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                                {{ connectingAmazon ? 'Conectando...' : 'Confirmar conexão' }}
                            </button>
                            <a href="/api/amazon/auth" class="block text-center text-xs text-primary hover:underline">
                                ou autorizar via OAuth (apps publicados)
                            </a>
                        </form>
                    </template>
                    <a v-else-if="!integration.connected && integration.available" :href="integration.connectHref"
                        class="inline-flex w-full items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis">
                        Conectar
                    </a>
                    <button v-else-if="integration.connected" type="button"
                        class="w-full rounded-lg border border-error px-4 py-2 text-sm font-medium text-error hover:bg-error/10"
                        @click="disconnect(integration)">
                        Desconectar
                    </button>
                    <span v-else class="block w-full rounded-lg border border-[var(--surface-border)] px-4 py-2 text-center text-sm text-slate-400">
                        Em breve
                    </span>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
