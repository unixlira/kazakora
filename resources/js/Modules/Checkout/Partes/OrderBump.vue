<script setup>
// Order bump (pedido do Lira 2026-11-24): um produto a mais, com desconto só nesta compra,
// logo antes do "Finalizar pedido". Um toque marca/desmarca e o total muda na hora; sem modal.
// O preço mostrado vem do servidor e o pedido confere de novo (OrderBump.php).
// Sem ícone de raio: o Lira mandou tirar raios do checkout.
import { computed } from 'vue';

const props = defineProps({
    oferta: { type: Object, required: true },
    modelValue: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const temDesconto = computed(() => Number(props.oferta.preco) < Number(props.oferta.preco_de));
const selo = computed(() => `-${Math.round(Number(props.oferta.desconto_pct) || 0)}%`);
const alternar = () => {
    if (!props.disabled) emit('update:modelValue', !props.modelValue);
};
</script>

<template>
    <section data-order-bump class="mt-5 overflow-hidden rounded-2xl border-2 border-dashed transition"
        :class="modelValue ? 'border-[#00843f] bg-emerald-50' : 'border-[#e8590c] bg-[#fff7ed]'">
        <p class="flex items-center justify-between gap-2 px-3 py-1.5 text-[11px] font-extrabold uppercase tracking-wide text-white"
            :class="modelValue ? 'bg-[#00843f]' : 'bg-[#e8590c]'">
            <span><i class="fa-solid fa-gift mr-1" aria-hidden="true"></i>Oferta especial</span>
            <span class="normal-case tracking-normal">só nesta compra</span>
        </p>

        <label class="flex cursor-pointer gap-3 p-3" :class="{ 'cursor-not-allowed opacity-60': disabled }">
            <input type="checkbox" class="sr-only" :checked="modelValue" :disabled="disabled" data-order-bump-check @change="alternar">
            <img v-if="oferta.imagem" :src="oferta.imagem" :alt="oferta.nome" width="64" height="64" loading="lazy" decoding="async"
                class="h-16 w-16 shrink-0 rounded-xl border border-slate-200 bg-white object-cover">
            <span v-else class="flex h-16 w-16 shrink-0 items-center justify-center rounded-xl bg-white" aria-hidden="true"><i class="fa-solid fa-box text-slate-400"></i></span>

            <span class="min-w-0 flex-1">
                <span class="flex items-start gap-2">
                    <!-- Checkbox grande desenhado (o input real está acima, só para leitores de tela e teclado). -->
                    <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-md border-2"
                        :class="modelValue ? 'border-[#00843f] bg-[#00843f]' : 'border-[#e8590c] bg-white'" aria-hidden="true">
                        <i v-if="modelValue" class="fa-solid fa-check text-sm text-white"></i>
                    </span>
                    <span class="text-[15px] font-extrabold leading-tight text-slate-900">Sim! Quero levar junto</span>
                </span>
                <span class="mt-1 block text-sm leading-snug text-slate-700">{{ oferta.nome }}</span>
                <span class="mt-1 flex flex-wrap items-baseline gap-x-2">
                    <s v-if="temDesconto" class="text-xs text-slate-500"><span class="sr-only">De </span>{{ formatPrice(oferta.preco_de) }}</s>
                    <strong class="text-lg font-extrabold text-[#00843f]"><span class="sr-only">Por </span>{{ formatPrice(oferta.preco) }}</strong>
                    <span v-if="temDesconto" class="rounded-full bg-[#00843f] px-2 py-0.5 text-[11px] font-extrabold text-white">{{ selo }}</span>
                </span>
                <span v-if="oferta.beneficio" class="mt-1 block text-xs leading-snug text-slate-600">
                    <i class="fa-solid fa-circle-check mr-1 text-[#00843f]" aria-hidden="true"></i>{{ oferta.beneficio }}
                </span>
                <span class="mt-1 block text-[11px] text-slate-500">Vai na mesma caixa, sem frete a mais.</span>
            </span>
        </label>
    </section>
</template>
