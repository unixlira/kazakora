<script setup>
// Detalhe da compra (pedido 2026-10-11): tudo da venda num lugar só — etapas,
// itens com foto, valores, pagamento com o ID da transação (para falar com o
// suporte), nota fiscal, entrega e o chat "Falar com suporte" com a Manuela.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import SuporteChat from '@/Shared/Components/SuporteChat.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    pedido: { type: Object, required: true },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatDate = (value) => (value ? new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(value)) : '—');

const STATUS = {
    awaiting_payment: { label: 'Aguardando pagamento', cor: '#b45309', icone: 'fa-clock' },
    paid: { label: 'Pagamento aprovado', cor: '#047857', icone: 'fa-box-open' },
    shipped: { label: 'A caminho', cor: '#0369a1', icone: 'fa-truck-fast' },
    completed: { label: 'Entregue', cor: '#15803d', icone: 'fa-house-circle-check' },
    cancelled: { label: 'Cancelado', cor: '#b91c1c', icone: 'fa-circle-xmark' },
    pending: { label: 'Pendente', cor: '#64748b', icone: 'fa-hourglass-half' },
};
const status = computed(() => STATUS[props.pedido.status] ?? STATUS.pending);
const ICONES = ['fa-receipt', 'fa-circle-check', 'fa-file-invoice', 'fa-box-open', 'fa-truck-fast', 'fa-house-circle-check'];
const etapaAtual = computed(() => props.pedido.etapas.findIndex((e) => !e.feito));
const cancelado = computed(() => props.pedido.status === 'cancelled');

const copiado = ref(null);
const copiar = async (valor, chave) => {
    try {
        await navigator.clipboard.writeText(valor);
        copiado.value = chave;
        setTimeout(() => { copiado.value = null; }, 2000);
    } catch {
        // sem permissão de área de transferência: o texto continua visível
    }
};

const METODO = { pix: 'Pix', card: 'Cartão de crédito', boleto: 'Boleto' };
const dataCurta = (valor) => (valor ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'America/Sao_Paulo' }).format(new Date(valor)) : null);
const PAGAMENTO_STATUS = { captured: 'Aprovado', authorized: 'Aprovado', succeeded: 'Aprovado', refunded: 'Estornado', requires_confirmation: 'Aguardando', failed: 'Recusado', cancelled: 'Cancelado' };
const chatAberto = ref(false);
</script>

<template>
    <Head :title="`Compra ${pedido.numero}`" />

    <AppLayout>
        <div class="mx-auto max-w-[1080px] px-4 py-8 md:px-6">
            <Link href="/pedidos" class="text-sm font-medium text-store-fg-muted no-underline hover:text-[#F27A2A]"><i class="fas fa-arrow-left mr-1"></i> Meus Pedidos</Link>

            <!-- Cabeçalho -->
            <section class="relative isolate mt-3 overflow-hidden rounded-3xl bg-[#111] p-6 text-white shadow-lg md:p-8">
                <span aria-hidden="true" class="absolute -right-10 -top-16 -z-10 h-48 w-48 rounded-full bg-[#F27A2A] opacity-50 blur-3xl"></span>
                <span aria-hidden="true" class="absolute -bottom-20 left-10 -z-10 h-40 w-56 rounded-full bg-[#0FB930] opacity-30 blur-3xl"></span>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-white/60">Compra</p>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="text-2xl font-black uppercase italic tracking-tight text-[#F27A2A] md:text-3xl">{{ pedido.numero }}</span>
                            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/10 text-sm hover:bg-white/20" aria-label="Copiar número" @click="copiar(pedido.numero, 'numero')">
                                <i class="fas" :class="copiado === 'numero' ? 'fa-check text-[#22c55e]' : 'fa-copy'"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-sm text-white/70">Feita em {{ formatDate(pedido.criado_em) }}</p>
                    </div>
                    <div class="text-right">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-sm font-bold" :style="{ color: status.cor }">
                            <i class="fas" :class="status.icone"></i> {{ status.label }}
                        </span>
                        <p class="mt-2 text-3xl font-extrabold">{{ formatPrice(pedido.valores.total) }}</p>
                    </div>
                </div>

                <!-- Etapas -->
                <ol v-if="!cancelado" class="mt-6 grid grid-cols-6 gap-1">
                    <li v-for="(etapa, i) in pedido.etapas" :key="etapa.titulo" class="flex flex-col items-center text-center">
                        <span class="flex h-9 w-9 items-center justify-center rounded-full text-sm"
                            :class="etapa.feito ? 'bg-[#0FB930] text-white' : (i === etapaAtual ? 'border-2 border-[#0FB930] text-[#22c55e]' : 'bg-white/10 text-white/40')">
                            <i class="fa-solid" :class="ICONES[i]"></i>
                        </span>
                        <span class="mt-1.5 text-[10px] leading-tight md:text-xs" :class="etapa.feito || i === etapaAtual ? 'text-white' : 'text-white/40'">{{ etapa.titulo }}</span>
                    </li>
                </ol>
                <p v-else class="mt-5 rounded-xl bg-red-500/20 px-4 py-3 text-sm font-medium text-red-100"><i class="fas fa-circle-xmark mr-1"></i> Esta compra foi cancelada.</p>
            </section>

            <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_360px]">
                <div class="flex flex-col gap-6">
                    <!-- Itens -->
                    <section class="rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                        <h2 class="flex items-center gap-2 font-bold"><i class="fas fa-bag-shopping text-[#F27A2A]"></i> Produtos</h2>
                        <ul class="mt-3 divide-y divide-store-border">
                            <li v-for="(item, i) in pedido.itens" :key="i" class="flex items-center gap-3 py-3">
                                <span class="h-14 w-14 shrink-0 overflow-hidden rounded-xl border border-store-border bg-white">
                                    <img v-if="item.imagem" :src="item.imagem" :alt="item.nome" class="h-full w-full object-cover" loading="lazy">
                                </span>
                                <div class="min-w-0 flex-1">
                                    <Link v-if="item.slug" :href="`/produtos/${item.slug}`" class="line-clamp-2 text-sm font-medium text-store-fg no-underline hover:text-[#F27A2A]">{{ item.nome }}</Link>
                                    <p v-else class="line-clamp-2 text-sm font-medium">{{ item.nome }}</p>
                                    <p class="text-xs text-store-fg-muted">{{ item.quantidade }} × {{ formatPrice(item.preco) }}</p>
                                </div>
                                <span class="text-sm font-bold">{{ formatPrice(item.subtotal) }}</span>
                            </li>
                        </ul>
                        <dl class="mt-3 space-y-1.5 border-t border-store-border pt-3 text-sm">
                            <div class="flex justify-between"><dt class="text-store-fg-muted">Produtos</dt><dd>{{ formatPrice(pedido.valores.subtotal) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-store-fg-muted">Frete</dt><dd class="font-semibold text-[#0FB930]">{{ pedido.valores.frete > 0 ? formatPrice(pedido.valores.frete) : 'Grátis ⚡FULL' }}</dd></div>
                            <div v-if="pedido.valores.desconto_cupom > 0" class="flex justify-between"><dt class="text-store-fg-muted">Cupom</dt><dd class="text-[#0FB930]">− {{ formatPrice(pedido.valores.desconto_cupom) }}</dd></div>
                            <div v-if="pedido.valores.desconto_plataforma > 0" class="flex justify-between"><dt class="text-store-fg-muted">Desconto da plataforma</dt><dd class="text-[#0FB930]">− {{ formatPrice(pedido.valores.desconto_plataforma) }}</dd></div>
                            <div v-if="pedido.valores.desconto_pix > 0" class="flex justify-between"><dt class="text-store-fg-muted">Desconto Pix</dt><dd class="text-[#0FB930]">− {{ formatPrice(pedido.valores.desconto_pix) }}</dd></div>
                            <div class="flex justify-between border-t border-store-border pt-2 text-base font-extrabold"><dt>Total</dt><dd>{{ formatPrice(pedido.valores.total) }}</dd></div>
                        </dl>
                    </section>

                    <!-- Suporte -->
                    <section id="suporte">
                        <div v-if="!chatAberto" class="flex flex-wrap items-center gap-4 rounded-2xl bg-gradient-to-r from-[#F27A2A] to-[#e0480f] p-5 text-white shadow-md">
                            <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white/20 text-2xl"><i class="fas fa-headset"></i></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-lg font-bold">Precisa de ajuda com esta compra?</p>
                                <p class="text-sm opacity-90">A Manuela responde na hora e, se precisar, abre um chamado com a nossa equipe.</p>
                            </div>
                            <button type="button" class="rounded-full bg-white px-5 py-2.5 text-sm font-bold text-[#e0480f] shadow hover:brightness-95" @click="chatAberto = true">
                                <i class="far fa-comments mr-1"></i> Falar com suporte
                            </button>
                        </div>
                        <SuporteChat v-else :order-id="pedido.id" />
                    </section>
                </div>

                <aside class="flex flex-col gap-4">
                    <!-- Pagamento -->
                    <section class="rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                        <h2 class="flex items-center gap-2 font-bold"><i class="fas fa-credit-card text-sky-500"></i> Pagamento</h2>
                        <template v-if="pedido.pagamento">
                            <p class="mt-2 flex items-center justify-between text-sm"><span class="text-store-fg-muted">Forma</span><span class="font-semibold"><i v-if="pedido.pagamento.metodo === 'pix'" class="fa-brands fa-pix mr-1 text-[#32BCAD]"></i>{{ METODO[pedido.pagamento.metodo] ?? pedido.pagamento.metodo }}</span></p>
                            <p class="mt-1 flex items-center justify-between text-sm"><span class="text-store-fg-muted">Situação</span><span class="font-semibold">{{ PAGAMENTO_STATUS[pedido.pagamento.status] ?? pedido.pagamento.status }}</span></p>
                            <p class="mt-1 flex items-center justify-between text-sm"><span class="text-store-fg-muted">Valor</span><span class="font-semibold">{{ formatPrice(pedido.pagamento.valor) }}</span></p>
                            <!-- Boleto aguardando (2026-10-14): linha digitável e PDF de novo aqui. -->
                            <div v-if="pedido.pagamento.boleto" class="mt-3 rounded-xl border border-orange-200 bg-orange-50 p-3 text-slate-800">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-[#c2410c]">Boleto<template v-if="pedido.pagamento.boleto.vencimento"> · vence em {{ dataCurta(pedido.pagamento.boleto.vencimento) }}</template></p>
                                <div v-if="pedido.pagamento.boleto.linha_digitavel" class="mt-1 flex items-start justify-between gap-2">
                                    <code class="break-all font-mono text-sm">{{ pedido.pagamento.boleto.linha_digitavel }}</code>
                                    <button type="button" class="text-sm text-[#F27A2A]" aria-label="Copiar linha digitável" @click="copiar(pedido.pagamento.boleto.linha_digitavel, 'boleto')">
                                        <i class="fas" :class="copiado === 'boleto' ? 'fa-check text-[#0FB930]' : 'fa-copy'"></i>
                                    </button>
                                </div>
                                <a v-if="pedido.pagamento.boleto.url" :href="pedido.pagamento.boleto.url" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-[#c2410c] hover:underline">
                                    <i class="fas fa-file-pdf"></i> Abrir o boleto (PDF)
                                </a>
                                <p class="mt-1 text-[11px] text-slate-500">O banco leva até 3 dias úteis para confirmar o pagamento.</p>
                            </div>
                            <div v-if="pedido.pagamento.transacao" class="mt-3 rounded-xl bg-store-bg-sunken p-3">
                                <p class="text-[11px] font-bold uppercase tracking-wide text-store-fg-muted">ID da transação</p>
                                <div class="mt-1 flex items-center justify-between gap-2">
                                    <code class="truncate font-mono text-sm">{{ pedido.pagamento.transacao }}</code>
                                    <button type="button" class="text-sm text-[#F27A2A]" aria-label="Copiar ID da transação" @click="copiar(pedido.pagamento.transacao, 'transacao')">
                                        <i class="fas" :class="copiado === 'transacao' ? 'fa-check text-[#0FB930]' : 'fa-copy'"></i>
                                    </button>
                                </div>
                                <p class="mt-1 text-[11px] text-store-fg-faint">Informe este número ao falar com o suporte.</p>
                            </div>
                        </template>
                        <p v-else class="mt-2 text-sm text-store-fg-muted">Sem pagamento registrado.</p>
                    </section>

                    <!-- Nota fiscal -->
                    <section class="rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                        <h2 class="flex items-center gap-2 font-bold"><i class="fas fa-file-invoice text-violet-500"></i> Nota fiscal</h2>
                        <template v-if="pedido.nota">
                            <p class="mt-2 text-sm">Nº <b>{{ pedido.nota.numero }}</b> · série {{ pedido.nota.serie }}</p>
                            <p class="mt-1 break-all font-mono text-[11px] text-store-fg-muted">{{ pedido.nota.chave }}</p>
                            <div class="mt-3 flex gap-2">
                                <a v-if="pedido.nota.pdf" :href="pedido.nota.pdf" class="flex-1 rounded-lg bg-violet-600 px-3 py-2 text-center text-xs font-bold text-white no-underline hover:brightness-95"><i class="fas fa-file-pdf mr-1"></i> PDF</a>
                                <a v-if="pedido.nota.xml" :href="pedido.nota.xml" class="flex-1 rounded-lg border border-violet-600 px-3 py-2 text-center text-xs font-bold text-violet-600 no-underline hover:bg-violet-50"><i class="fas fa-code mr-1"></i> XML</a>
                            </div>
                        </template>
                        <p v-else class="mt-2 text-sm text-store-fg-muted">A nota sai logo depois da confirmação do pagamento.</p>
                    </section>

                    <!-- Entrega -->
                    <section class="rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                        <h2 class="flex items-center gap-2 font-bold"><i class="fas fa-location-dot text-[#F27A2A]"></i> Entrega</h2>
                        <p class="mt-2 text-sm font-semibold">{{ pedido.entrega.nome }}</p>
                        <p class="text-sm text-store-fg-muted">{{ pedido.entrega.endereco }}<br>{{ pedido.entrega.bairro_cidade }}<br>CEP {{ pedido.entrega.cep }}</p>
                        <div v-if="pedido.entrega.codigo_rastreio" class="mt-3 rounded-xl bg-sky-50 p-3 dark:bg-sky-950/40">
                            <p class="text-[11px] font-bold uppercase tracking-wide text-sky-700 dark:text-sky-300">Código de rastreio</p>
                            <div class="mt-1 flex items-center justify-between gap-2">
                                <code class="font-mono text-sm font-bold">{{ pedido.entrega.codigo_rastreio }}</code>
                                <button type="button" class="text-sm text-sky-700" aria-label="Copiar código" @click="copiar(pedido.entrega.codigo_rastreio, 'rastreio')">
                                    <i class="fas" :class="copiado === 'rastreio' ? 'fa-check text-[#0FB930]' : 'fa-copy'"></i>
                                </button>
                            </div>
                            <p v-if="pedido.entrega.transportadora" class="text-xs text-store-fg-muted">{{ pedido.entrega.transportadora }}</p>
                        </div>
                        <Link :href="pedido.entrega.rastreio_url" class="mt-3 block rounded-lg border border-store-border px-3 py-2 text-center text-sm font-semibold text-store-fg no-underline hover:border-[#F27A2A]"><i class="fas fa-route mr-1"></i> Acompanhar entrega</Link>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
