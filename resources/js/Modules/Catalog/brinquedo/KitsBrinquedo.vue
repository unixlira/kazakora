<script setup>
// Kits da página de brinquedo (pedido do Lira 24/11/2026). Dois formatos:
// "lista" na caixa de compra (cartão selecionável com caixinha de marcar) e
// "cartoes" na seção "Escolha o seu kit" mais abaixo (foto, preço e botão).
import { computed } from 'vue';
import ProductCard from '@/Shared/Components/ProductCard.vue';
import Icone from './Icone.vue';
import { formatPrice } from '@/Shared/productCard';
import { economia, itensDoKit, nomeDoKit, ordenarKits, porUnidade, precoDeDoKit, precoDoKit, selosDosKits, unidadesDoKit } from './kits';

const props = defineProps({
    opcoes: { type: Array, required: true },
    escolhidaId: { type: Number, default: null },
    formato: { type: String, default: 'lista' },
});
const emit = defineEmits(['escolher']);

const kits = computed(() => ordenarKits(props.opcoes));
const selos = computed(() => selosDosKits(props.opcoes));
</script>

<template>
    <div v-if="formato === 'lista'" class="kb-lista" role="radiogroup" aria-label="Escolha o seu kit">
        <button v-for="kit in kits" :key="kit.id" type="button" role="radio" class="kb-kit"
            :class="{ 'is-sel': kit.id === escolhidaId, 'is-hot': selos[kit.id]?.tom === 'amarelo', esgotado: kit.stock < 1 }"
            :aria-checked="kit.id === escolhidaId" :data-kzm="`kit:${kit.id}`" @click="emit('escolher', kit)">
            <span v-if="selos[kit.id]" class="kb-selo" :class="`tom-${selos[kit.id].tom}`">
                <Icone v-if="selos[kit.id].estrela" nome="star" :tamanho="13" />{{ selos[kit.id].texto }}
            </span>
            <span class="kb-marca" aria-hidden="true"><Icone nome="check" :tamanho="18" :traco="3" /></span>
            <span class="kb-info">
                <strong class="kb-nome">{{ nomeDoKit(kit) }}</strong>
                <span class="kb-itens">{{ kit.stock < 1 ? 'Esgotado' : itensDoKit(kit) }}</span>
                <span class="kb-pilulas">
                    <span v-if="unidadesDoKit(kit) > 1" class="kb-pilula azul">{{ formatPrice(porUnidade(kit)) }} por brinquedo</span>
                    <span v-if="economia(kit)" class="kb-pilula verde">Economize {{ formatPrice(economia(kit)) }}</span>
                </span>
            </span>
            <span class="kb-precos">
                <s v-if="precoDeDoKit(kit)">{{ formatPrice(precoDeDoKit(kit)) }}</s>
                <b>{{ formatPrice(precoDoKit(kit)) }}</b>
            </span>
        </button>
    </div>

    <!-- Cartões da seção "Escolha o tamanho da diversão": o MESMO card do "Você também pode gostar"
         (pedido do Lira 24/11/2026), com nome, selo e economia do kit; o clique escolhe o kit. -->
    <div v-else class="kb-cartoes-vitrine pb-reveal">
        <div v-for="kit in kits" :key="kit.id" class="kb-cartao-vitrine">
            <ProductCard :product="kit" :nome="nomeDoKit(kit)" :selo="selos[kit.id] ?? null" escolher
                :selecionado="kit.id === escolhidaId" @escolher="emit('escolher', kit)">
                <span class="kb-pilulas kb-pilulas-card">
                    <span v-if="unidadesDoKit(kit) > 1" class="kb-pilula azul">{{ formatPrice(porUnidade(kit)) }} por brinquedo</span>
                    <span v-else class="kb-pilula azul">{{ itensDoKit(kit) }}</span>
                    <span v-if="economia(kit)" class="kb-pilula verde">Economize {{ formatPrice(economia(kit)) }}</span>
                </span>
            </ProductCard>
        </div>
    </div>
</template>
