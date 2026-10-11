<script setup>
// Meus Pedidos (pedido 2026-10-11): cards coloridos; na primeira linha as fotos
// redondas dos produtos, encavaladas pela metade quando há mais de um (igual ML).
// Clicar abre o detalhe da compra (/pedidos/{id}) com tudo e o suporte.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    orders: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
const formatDate = (value) => new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));

const STATUS = {
    pending: { label: 'Pendente', cor: '#64748b', fundo: '#f1f5f9', icone: 'fa-hourglass-half' },
    awaiting_payment: { label: 'Aguardando pagamento', cor: '#b45309', fundo: '#fef3c7', icone: 'fa-clock' },
    paid: { label: 'Pagamento aprovado', cor: '#047857', fundo: '#d1fae5', icone: 'fa-box-open' },
    shipped: { label: 'A caminho', cor: '#0369a1', fundo: '#e0f2fe', icone: 'fa-truck-fast' },
    completed: { label: 'Entregue', cor: '#15803d', fundo: '#dcfce7', icone: 'fa-house-circle-check' },
    cancelled: { label: 'Cancelado', cor: '#b91c1c', fundo: '#fee2e2', icone: 'fa-circle-xmark' },
};
const status = (codigo) => STATUS[codigo] ?? STATUS.pending;
</script>

<template>
    <Head title="Meus Pedidos" />

    <AppLayout>
        <div class="mx-auto max-w-[900px] px-4 py-10 md:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-[#F27A2A] to-[#e0480f] text-xl text-white shadow-md">
                    <i class="fas fa-bag-shopping"></i>
                </span>
                <div>
                    <h1 class="font-display text-3xl font-semibold">Meus Pedidos</h1>
                    <p class="text-sm text-store-fg-muted">Acompanhe seus pedidos e fale com o suporte em cada compra.</p>
                </div>
            </div>

            <div v-if="orders.data.length === 0" class="mt-10 rounded-3xl border-2 border-dashed border-store-border p-10 text-center">
                <i class="fas fa-basket-shopping text-5xl text-[#F27A2A] opacity-70"></i>
                <p class="mt-3 font-semibold">Você ainda não fez nenhuma compra.</p>
                <Link href="/" class="mt-4 inline-flex rounded-full bg-[#0FB930] px-6 py-2.5 text-sm font-bold text-white no-underline hover:brightness-95">Ver produtos</Link>
            </div>

            <ul class="mt-8 flex flex-col gap-4">
                <li v-for="order in orders.data" :key="order.id">
                    <Link :href="`/pedidos/${order.id}`"
                        class="group flex overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised no-underline shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg">
                        <span class="w-1.5 shrink-0" :style="{ backgroundColor: status(order.status).cor }"></span>

                        <div class="flex flex-1 flex-wrap items-center gap-4 p-4 sm:flex-nowrap sm:p-5">
                            <div class="min-w-0 flex-1">
                                <!-- 1ª linha: fotos redondas encavaladas + nome -->
                                <div class="flex items-center gap-3">
                                    <div class="flex shrink-0 items-center">
                                        <span v-for="(item, indice) in order.itens.slice(0, 3)" :key="indice"
                                            class="h-12 w-12 overflow-hidden rounded-full border-2 border-white bg-white shadow-md ring-1 ring-store-border"
                                            :class="indice > 0 ? '-ml-6' : ''" :style="{ zIndex: 10 - indice }">
                                            <img v-if="item.imagem" :src="item.imagem" :alt="item.nome" class="h-full w-full object-cover" loading="lazy">
                                            <span v-else class="flex h-full w-full items-center justify-center bg-store-bg-sunken"><i class="fas fa-box text-store-fg-faint"></i></span>
                                        </span>
                                        <span v-if="order.itens.length > 3"
                                            class="-ml-6 flex h-12 w-12 items-center justify-center rounded-full border-2 border-white bg-[#111] text-xs font-bold text-white shadow-md">+{{ order.itens.length - 3 }}</span>
                                    </div>
                                    <p class="min-w-0 truncate font-semibold text-store-fg">{{ order.itens[0]?.nome }}<span v-if="order.itens.length > 1" class="font-normal text-store-fg-muted"> e mais {{ order.itens.length - 1 }}</span></p>
                                </div>
                                <div class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
                                        :style="{ color: status(order.status).cor, backgroundColor: status(order.status).fundo }">
                                        <i class="fas" :class="status(order.status).icone"></i> {{ status(order.status).label }}
                                    </span>
                                    <span class="text-xs text-store-fg-muted">
                                        <span class="font-bold italic text-[#F27A2A]">{{ order.numero }}</span> · {{ formatDate(order.criado_em) }} · {{ order.quantidade }} {{ order.quantidade === 1 ? 'item' : 'itens' }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex w-full items-center justify-between gap-3 sm:w-auto sm:flex-col sm:items-end">
                                <span class="text-lg font-extrabold text-store-fg">{{ formatPrice(order.total) }}</span>
                                <span class="inline-flex items-center gap-1 text-sm font-semibold text-[#F27A2A] group-hover:underline">Ver compra <i class="fas fa-chevron-right text-xs"></i></span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>

            <div v-if="orders.last_page > 1" class="mt-8 flex justify-center gap-2">
                <Link v-for="link in orders.links" :key="link.label" :href="link.url ?? ''" preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-sm no-underline"
                    :class="link.active ? 'bg-[#F27A2A] font-bold text-white' : link.url ? 'border border-store-border text-store-fg' : 'pointer-events-none opacity-40'"
                    v-html="link.label" />
            </div>
        </div>
    </AppLayout>
</template>
