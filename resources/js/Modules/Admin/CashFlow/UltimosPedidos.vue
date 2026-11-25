<script setup>
/**
 * Últimos pedidos dos sites (pedido do Lira em 25/11/2026): os 10 mais
 * recentes do período/loja filtrados, com o lucro líquido de cada um na
 * última coluna. A conta vem pronta do servidor (LucroPorPedido, peças da
 * ContributionMargin); aqui só se mostra. O "i" do lucro abre a conta linha
 * a linha. Tabela a partir de md; no celular vira lista.
 */
import { Link } from '@inertiajs/vue3';
import { formatPrice, useTemaEscuro } from '@/Shared/graficos';
import { ref } from 'vue';

defineProps({
    pedidos: { type: Array, default: () => [] },
    verTodos: { type: String, required: true },
});

const { channelColor } = useTemaEscuro();

const SITUACAO = {
    pago: { rotulo: 'Pago', classe: 'bg-success/15 text-success' },
    aguardando: { rotulo: 'Aguardando', classe: 'bg-warning/15 text-warning' },
    cancelado: { rotulo: 'Cancelado', classe: 'bg-[var(--surface-muted)] text-[var(--texto-suave)]' },
};

const dataHora = (iso) => new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo',
}).format(new Date(iso));

const linhasDaConta = (p) => {
    const c = p.conta;
    return [
        ['Receita', c.receita, false],
        ['Produto', -c.produto, false],
        ['Frete', -c.frete, false],
        [c.taxa_estimada ? 'Taxa (estimada)' : 'Taxa', -c.taxa, false],
        ['Imposto', -c.imposto, false],
        ['Insumos', -c.insumos, false],
        [`Anúncio (rateado${c.anuncio_pedidos_no_dia > 1 ? ` ÷ ${c.anuncio_pedidos_no_dia}` : ''})`, -c.anuncio, false],
        ['= Lucro', p.lucro, true],
    ];
};
const textoDaConta = (p) => linhasDaConta(p).map(([r, v]) => `${r} ${formatPrice(v)}`).join('; ');

const aberto = ref(null);
</script>

<template>
    <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="fc-ultimos">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <div>
                <h2 id="fc-ultimos" class="font-semibold">Últimos pedidos dos sites</h2>
                <p class="text-xs text-[var(--texto-suave)]">Os 10 mais recentes do período. Lucro líquido já com produto, frete, taxa, imposto, insumos e anúncio do dia rateado.</p>
            </div>
            <Link :href="verTodos" class="text-sm font-semibold text-primary hover:underline">Ver todos <i class="fas fa-arrow-right text-xs" aria-hidden="true"></i></Link>
        </div>

        <p v-if="!pedidos.length" class="py-6 text-center text-sm text-[var(--texto-suave)]">Nenhum pedido dos sites neste período.</p>

        <!-- Computador: tabela compacta, sem rolagem lateral -->
        <table v-if="pedidos.length" class="hidden w-full table-fixed text-sm tabular-nums md:table">
            <caption class="sr-only">Últimos pedidos dos sites com o lucro líquido de cada um</caption>
            <colgroup>
                <col class="w-[4.5rem]"><col class="w-[6.5rem]"><col class="w-[6.5rem]"><col class="w-[6.5rem]">
                <col><col class="w-[6.5rem]"><col class="w-[6.5rem]"><col class="w-[8rem]">
            </colgroup>
            <thead>
                <tr class="border-b border-[var(--surface-border)] text-[11px] text-[var(--texto-suave)]">
                    <th scope="col" class="py-1.5 pr-2 text-left font-semibold">Pedido</th>
                    <th scope="col" class="px-2 py-1.5 text-left font-semibold">Data/hora</th>
                    <th scope="col" class="px-2 py-1.5 text-left font-semibold">Cliente</th>
                    <th scope="col" class="px-2 py-1.5 text-left font-semibold">Loja</th>
                    <th scope="col" class="px-2 py-1.5 text-left font-semibold">Itens</th>
                    <th scope="col" class="px-2 py-1.5 text-right font-semibold">Pago</th>
                    <th scope="col" class="px-2 py-1.5 text-left font-semibold">Situação</th>
                    <th scope="col" class="py-1.5 pl-2 text-right font-semibold">Lucro líquido</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="p in pedidos" :key="p.id" class="border-b border-[var(--surface-muted)]">
                    <td class="py-1.5 pr-2"><Link :href="`/admin/pedidos/${p.id}`" class="font-semibold hover:text-primary hover:underline">#{{ p.id }}</Link></td>
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ dataHora(p.criado_em) }}</td>
                    <td class="truncate px-2 py-1.5" :title="p.cliente">{{ p.cliente }}</td>
                    <td class="px-2 py-1.5">
                        <span class="inline-flex max-w-full items-center gap-1 truncate rounded-full border border-[var(--surface-border)] px-2 py-0.5 text-[11px] font-semibold">
                            <i class="inline-block h-2 w-2 shrink-0 rounded-full" :style="{ background: channelColor(p.canal) }" aria-hidden="true"></i>{{ p.loja }}
                        </span>
                    </td>
                    <td class="truncate px-2 py-1.5" :title="p.itens">{{ p.itens }}</td>
                    <td class="px-2 py-1.5 text-right" :class="{ 'text-[var(--texto-suave)]': p.situacao !== 'pago' }">{{ formatPrice(p.pago) }}</td>
                    <td class="px-2 py-1.5">
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="SITUACAO[p.situacao].classe">{{ SITUACAO[p.situacao].rotulo }}</span>
                    </td>
                    <td class="py-1.5 pl-2 text-right">
                        <span v-if="p.lucro === null" class="text-[var(--texto-suave)]" :title="p.sem_lucro">—<span v-if="p.situacao === 'aguardando'" class="block text-[10px] leading-tight">{{ p.sem_lucro }}</span></span>
                        <span v-else class="relative inline-block" @mouseenter="aberto = p.id" @mouseleave="aberto = null">
                            <button type="button" class="font-semibold underline decoration-dotted underline-offset-2"
                                :class="p.lucro < 0 ? 'text-error' : 'text-success'"
                                :aria-describedby="`conta-${p.id}`" :aria-label="`Lucro líquido ${formatPrice(p.lucro)}. ${textoDaConta(p)}`"
                                @focus="aberto = p.id" @blur="aberto = null" @keydown.esc="aberto = null">{{ formatPrice(p.lucro) }}</button>
                            <span v-show="aberto === p.id" :id="`conta-${p.id}`" role="tooltip"
                                class="absolute right-0 top-full z-50 mt-1 w-60 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3 text-left text-xs shadow-lg">
                                <span v-for="[rotulo, valor, total] in linhasDaConta(p)" :key="rotulo"
                                    class="flex justify-between gap-3" :class="total ? 'mt-1 border-t border-[var(--surface-border)] pt-1 font-bold' : ''">
                                    <span>{{ rotulo }}</span><span>{{ formatPrice(valor) }}</span>
                                </span>
                            </span>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Celular: lista -->
        <ul v-if="pedidos.length" class="divide-y divide-[var(--surface-muted)] md:hidden">
            <li v-for="p in pedidos" :key="p.id" class="py-2.5">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <Link :href="`/admin/pedidos/${p.id}`" class="font-semibold hover:underline">#{{ p.id }}</Link>
                            <span class="inline-flex items-center gap-1 text-[11px] font-semibold">
                                <i class="inline-block h-2 w-2 rounded-full" :style="{ background: channelColor(p.canal) }" aria-hidden="true"></i>{{ p.loja }}
                            </span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="SITUACAO[p.situacao].classe">{{ SITUACAO[p.situacao].rotulo }}</span>
                        </div>
                        <p class="truncate text-xs text-[var(--texto-suave)]">{{ dataHora(p.criado_em) }} · {{ p.cliente }} · {{ p.itens }}</p>
                    </div>
                    <div class="shrink-0 text-right tabular-nums">
                        <p class="text-[11px] text-[var(--texto-suave)]">pago {{ formatPrice(p.pago) }}</p>
                        <p v-if="p.lucro === null" class="text-sm text-[var(--texto-suave)]">— <span v-if="p.situacao === 'aguardando'" class="text-[10px]">{{ p.sem_lucro }}</span></p>
                        <button v-else type="button" class="text-sm font-semibold" :class="p.lucro < 0 ? 'text-error' : 'text-success'"
                            :aria-expanded="aberto === `m${p.id}`" :aria-label="`Lucro líquido ${formatPrice(p.lucro)}. Ver a conta`"
                            @click="aberto = aberto === `m${p.id}` ? null : `m${p.id}`">{{ formatPrice(p.lucro) }} <i class="fas fa-chevron-down text-[10px]" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div v-if="p.lucro !== null && aberto === `m${p.id}`" class="mt-2 rounded-lg bg-[var(--surface-muted)] p-2 text-xs tabular-nums">
                    <span v-for="[rotulo, valor, total] in linhasDaConta(p)" :key="rotulo"
                        class="flex justify-between gap-3" :class="total ? 'mt-1 border-t border-[var(--surface-border)] pt-1 font-bold' : ''">
                        <span>{{ rotulo }}</span><span>{{ formatPrice(valor) }}</span>
                    </span>
                </div>
            </li>
        </ul>

        <p v-if="pedidos.length" class="mt-2 text-[11px] text-[var(--texto-suave)]">
            Anúncio rateado = gasto de anúncios da loja no dia ÷ pedidos pagos da loja nesse dia. Taxa estimada (Pix 0,99%, cartão 4,98%, boleto R$ 3,49) até a taxa real do Mercado Pago chegar.
        </p>
    </section>
</template>
