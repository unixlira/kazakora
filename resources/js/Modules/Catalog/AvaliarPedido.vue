<script setup>
// Página própria de avaliação do pedido (pedido do Lira 2026-10-18): o e-mail
// pós-compra traz aqui, sem login. O cliente dá as estrelas de cada produto e,
// ao enviar, ganha na hora o cupom com o desconto da nota (tabela do admin).
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    token: { type: String, required: true },
    pedido: { type: String, required: true },
    nome: { type: String, default: 'cliente' },
    produtos: { type: Array, default: () => [] },
    // % de desconto por nota: posição 0 = 1 estrela ... posição 4 = 5 estrelas.
    tabela: { type: Array, default: () => [] },
    inicial: { type: Object, default: () => ({}) },
    resultado: { type: Object, default: null },
});

const ROTULOS = ['Péssimo', 'Ruim', 'Regular', 'Bom', 'Excelente'];

const form = useForm({
    avaliacoes: props.produtos.map((produto) => ({
        product_id: produto.id,
        rating: (props.inicial.produto === produto.id || props.produtos.length === 1) && props.inicial.nota
            ? props.inicial.nota
            : produto.nota ?? 0,
        comment: produto.comentario ?? '',
    })),
});

const passando = ref({});
const notaVisivel = (indice) => passando.value[indice] || form.avaliacoes[indice].rating;

const faltam = computed(() => form.avaliacoes.filter((a) => !a.rating).length);
const notaDoPedido = computed(() => {
    const dadas = form.avaliacoes.map((a) => a.rating).filter(Boolean);
    return dadas.length ? Math.round(dadas.reduce((s, n) => s + n, 0) / dadas.length) : 0;
});
const formatar = (valor) => Number(valor).toLocaleString('pt-BR', { maximumFractionDigits: 2 });
const descontoDaNota = computed(() => (notaDoPedido.value && props.tabela.length ? props.tabela[notaDoPedido.value - 1] : 0));

function enviar() {
    if (faltam.value) return;
    // Sem preserveScroll: o cartão do cupom aparece do topo.
    form.post(`/avaliar-pedido/${props.token}`);
}

const copiado = ref(false);
async function copiar() {
    try {
        await navigator.clipboard.writeText(props.resultado.cupom.codigo);
        copiado.value = true;
        setTimeout(() => { copiado.value = false; }, 2000);
    } catch {
        copiado.value = false;
    }
}
</script>

<template>
    <Head title="Avalie sua compra">
        <meta name="robots" content="noindex, nofollow">
    </Head>

    <AppLayout>
        <section class="mx-auto max-w-2xl px-4 py-10 md:py-14">
            <template v-if="resultado">
                <div class="rounded-2xl border border-store-border bg-store-bg-raised p-6 text-center shadow-sm md:p-8">
                    <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-50 text-2xl text-green-600">
                        <i class="fas fa-check"></i>
                    </div>
                    <h1 class="mt-4 font-display text-2xl font-semibold md:text-3xl">Obrigado pela avaliação, {{ nome }}!</h1>
                    <p class="mt-2 text-store-fg-muted">
                        Sua nota para o pedido #{{ pedido }}:
                        <span class="whitespace-nowrap text-amber-400"><i v-for="n in 5" :key="n" :class="n <= resultado.nota ? 'fas fa-star' : 'far fa-star'"></i></span>
                    </p>

                    <div v-if="resultado.cupom" class="mt-6 rounded-xl border-2 border-dashed border-[#f27a2a] bg-orange-50 px-4 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-store-fg-muted">Seu cupom para a próxima compra</p>
                        <p class="mt-2 font-mono text-3xl font-bold tracking-[0.15em]">{{ resultado.cupom.codigo }}</p>
                        <p class="mt-1 font-bold text-green-700">{{ resultado.cupom.desconto }}</p>
                        <p v-if="resultado.cupom.minimo" class="mt-1 text-sm text-store-fg-muted">
                            Em compras a partir de R$ {{ resultado.cupom.minimo.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) }}
                        </p>
                        <p v-if="resultado.cupom.validade" class="mt-1 text-sm text-store-fg-muted">Válido até {{ resultado.cupom.validade }}, uso único</p>
                        <div class="mt-4 flex flex-wrap justify-center gap-2">
                            <button type="button" class="rounded-full border border-store-border bg-store-bg-raised px-5 py-2.5 text-sm font-semibold hover:border-store-fg" @click="copiar">
                                <i class="far fa-copy"></i> {{ copiado ? 'Copiado!' : 'Copiar código' }}
                            </button>
                            <a :href="resultado.cupom.link" class="rounded-full bg-store-accent px-5 py-2.5 text-sm font-semibold text-white hover:bg-store-accent-strong">
                                Usar meu cupom
                            </a>
                        </div>
                    </div>
                    <a v-else href="/" class="mt-6 inline-block rounded-full bg-store-accent px-5 py-2.5 text-sm font-semibold text-white hover:bg-store-accent-strong">
                        Voltar para a loja
                    </a>
                </div>
            </template>

            <template v-else>
                <p class="text-sm font-semibold uppercase tracking-[0.2em] text-store-fg-muted">Pedido #{{ pedido }}</p>
                <h1 class="mt-2 font-display text-3xl font-semibold">Oi, {{ nome }}! O que achou da sua compra?</h1>
                <p class="mt-2 text-store-fg-muted">Toque nas estrelas para dar a sua nota. Leva menos de um minuto e ajuda outros clientes a escolher.</p>

                <div v-if="tabela.length" class="mt-6 rounded-xl border border-store-border bg-orange-50/60 p-4">
                    <p class="text-sm font-semibold">Sua avaliação vale um cupom para a próxima compra</p>
                    <ul class="mt-3 grid grid-cols-5 gap-2 text-center">
                        <li v-for="(percentual, i) in tabela" :key="i"
                            class="rounded-lg border bg-store-bg-raised px-1 py-2 transition"
                            :class="notaDoPedido === i + 1 ? 'border-amber-400 ring-2 ring-amber-300' : 'border-store-border'">
                            <span class="block text-xs text-amber-400"><i v-for="n in i + 1" :key="n" class="fas fa-star"></i></span>
                            <span class="mt-1 block text-sm font-bold text-green-700">{{ formatar(percentual) }}%</span>
                        </li>
                    </ul>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="enviar">
                    <article v-for="(produto, indice) in produtos" :key="produto.id" class="rounded-2xl border border-store-border bg-store-bg-raised p-4 shadow-sm md:p-5">
                        <div class="flex items-center gap-4">
                            <img v-if="produto.imagem" :src="produto.imagem" alt="" class="h-16 w-16 shrink-0 rounded-xl border border-store-border object-cover">
                            <div v-else class="h-16 w-16 shrink-0 rounded-xl bg-gray-100"></div>
                            <p class="font-medium leading-snug">{{ produto.nome }}</p>
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2" @mouseleave="passando[indice] = 0">
                            <div class="flex gap-1" role="radiogroup" :aria-label="`Nota para ${produto.nome}`">
                                <button v-for="estrela in 5" :key="estrela" type="button" role="radio"
                                    :aria-checked="form.avaliacoes[indice].rating === estrela"
                                    :aria-label="`${estrela} ${estrela === 1 ? 'estrela' : 'estrelas'}`"
                                    class="p-0.5 text-3xl transition-transform hover:scale-110 md:text-4xl"
                                    @mouseenter="passando[indice] = estrela"
                                    @click="form.avaliacoes[indice].rating = estrela">
                                    <i :class="estrela <= notaVisivel(indice) ? 'fas fa-star text-amber-400' : 'far fa-star text-store-fg-faint'"></i>
                                </button>
                            </div>
                            <span class="text-sm font-semibold text-store-fg-muted">{{ notaVisivel(indice) ? ROTULOS[notaVisivel(indice) - 1] : 'Escolha a nota' }}</span>
                        </div>

                        <label class="mt-4 block">
                            <span class="text-sm text-store-fg-muted">Quer contar mais? (opcional)</span>
                            <textarea v-model="form.avaliacoes[indice].comment" rows="3" maxlength="1000"
                                class="mt-1 w-full rounded-xl border border-store-border px-3 py-2 text-sm focus:border-store-fg focus:outline-none"
                                placeholder="O que você mais gostou? Chegou como esperava?"></textarea>
                        </label>
                    </article>

                    <p v-if="form.errors.avaliacoes" class="text-sm text-red-600">{{ form.errors.avaliacoes }}</p>

                    <div class="rounded-2xl border border-store-border bg-store-bg-raised p-4 md:p-5">
                        <p v-if="faltam" class="text-sm text-store-fg-muted">
                            {{ faltam === 1 ? 'Falta a nota de 1 produto.' : `Faltam as notas de ${faltam} produtos.` }}
                        </p>
                        <p v-else-if="descontoDaNota" class="text-sm">
                            Sua nota vale <strong class="text-green-700">{{ formatar(descontoDaNota) }}% de desconto</strong> na próxima compra.
                        </p>
                        <button type="submit" :disabled="form.processing || faltam > 0"
                            class="mt-3 w-full rounded-full bg-store-accent px-6 py-3 font-semibold text-white hover:bg-store-accent-strong disabled:cursor-not-allowed disabled:opacity-40">
                            {{ form.processing ? 'Enviando...' : (tabela.length ? 'Enviar avaliação e ver meu cupom' : 'Enviar avaliação') }}
                        </button>
                    </div>
                </form>
            </template>
        </section>
    </AppLayout>
</template>
