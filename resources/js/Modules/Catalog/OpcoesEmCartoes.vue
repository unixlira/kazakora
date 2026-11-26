<script setup>
// Opções do anúncio em cartões, no formato dos kits do Livro Falante (pedido do Lira 26/11/2026,
// Compressor 4 em 1: "o formato da variação igual ao do livro"). Mesma estrutura do "Escolha seu kit"
// da página de brinquedo (KitsBrinquedo, formato "lista"): selo no alto, caixinha de marcar, nome, o que
// vem, vantagens, de/por à direita e as pílulas "só + R$ X" e "Economize". Texto de cada cartão em
// products.cartoes_opcoes (CartoesDeOpcoes); preço SEMPRE o da própria opção, a mesma conta do bloco de
// preço da página e do checkout (com o cupom automático da Black Friday quando houver).
import { computed } from 'vue';
import { formatPrice } from '@/Shared/productCard';
import Icone from './brinquedo/Icone.vue';
import { porDaOpcao, deDaOpcao, economiaDaOpcao, ordenarOpcoes, selosDasOpcoes } from './opcoesEmCartoes';

const props = defineProps({
    // Pai e variações (os mesmos objetos que o bloco de compra usa).
    opcoes: { type: Array, required: true },
    // { titulo, itens: [{ id, nome, itens, vantagens, destaque, selo }] }
    cartoes: { type: Object, required: true },
    escolhidaId: { type: Number, default: null },
});
const emit = defineEmits(['escolher']);

const textos = computed(() => new Map((props.cartoes.itens ?? []).map((item) => [Number(item.id), item])));
const lista = computed(() => ordenarOpcoes(props.opcoes.filter((opcao) => textos.value.has(opcao.id))));
const selos = computed(() => selosDasOpcoes(lista.value, textos.value));
const maisBarata = computed(() => lista.value[0] ?? null);
const acrescimo = (opcao) => (maisBarata.value && maisBarata.value.id !== opcao.id
    ? Math.round((porDaOpcao(opcao) - porDaOpcao(maisBarata.value)) * 100) / 100
    : 0);
const nome = (opcao) => textos.value.get(opcao.id)?.nome || opcao.variation || opcao.name;
</script>

<template>
    <div class="oc-lista" role="radiogroup" :aria-label="cartoes.titulo">
        <button v-for="opcao in lista" :key="opcao.id" type="button" role="radio" class="oc-kit"
            :class="{ 'is-sel': opcao.id === escolhidaId, esgotado: opcao.stock < 1 }"
            :aria-checked="opcao.id === escolhidaId" :data-kzm="`opcao-cartao:${opcao.id}`" @click="emit('escolher', opcao)">
            <span v-if="selos[opcao.id]" class="oc-selo" :class="`tom-${selos[opcao.id].tom}`">
                <Icone v-if="selos[opcao.id].estrela" nome="star" :tamanho="12" :traco="2.4" />{{ selos[opcao.id].texto }}
            </span>
            <span class="oc-marca" aria-hidden="true"><Icone nome="check" :tamanho="16" :traco="3.4" /></span>
            <span class="oc-info">
                <span class="oc-nome">{{ nome(opcao) }}</span>
                <span class="oc-itens">{{ opcao.stock < 1 ? 'Esgotado' : (textos.get(opcao.id)?.itens || '') }}</span>
                <span v-if="opcao.stock > 0 && textos.get(opcao.id)?.vantagens?.length" class="oc-vantagens">
                    <span v-for="(vantagem, i) in textos.get(opcao.id).vantagens" :key="i">+ {{ vantagem }}</span>
                </span>
            </span>
            <span class="oc-precos">
                <s v-if="deDaOpcao(opcao)">{{ formatPrice(deDaOpcao(opcao)) }}</s>
                <b>{{ formatPrice(porDaOpcao(opcao)) }}</b>
                <small v-if="opcao.preco_cupom?.code">com cupom {{ opcao.preco_cupom.code }}</small>
            </span>
            <span class="oc-pilulas">
                <span v-if="acrescimo(opcao) > 0" class="oc-pilula azul">só + {{ formatPrice(acrescimo(opcao)) }}</span>
                <span v-if="economiaDaOpcao(opcao)" class="oc-pilula verde">Economize {{ formatPrice(economiaDaOpcao(opcao)) }}</span>
            </span>
        </button>
    </div>
</template>
