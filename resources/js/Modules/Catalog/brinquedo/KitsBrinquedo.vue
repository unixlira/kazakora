<script setup>
// Kits da página de brinquedo (pedido do Lira 24/11/2026). Dois formatos:
// "lista" na caixa de compra (cartão selecionável com caixinha de marcar) e
// "cartoes" na seção "Escolha o seu kit" mais abaixo (foto, preço e botão).
import { computed } from 'vue';
import { formatPrice } from '@/Shared/productCard';
import { economia, fotoDoKit, itensDoKit, nomeDoKit, ordenarKits, porUnidade, precoDeDoKit, precoDoKit, selosDosKits, unidadesDoKit } from './kits';

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
                <i v-if="selos[kit.id].estrela" class="fas fa-star" aria-hidden="true"></i>{{ selos[kit.id].texto }}
            </span>
            <span class="kb-marca" aria-hidden="true"><i class="fas fa-check"></i></span>
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

    <div v-else class="kb-cartoes">
        <article v-for="kit in kits" :key="kit.id" class="kb-cartao" :class="{ 'is-hot': selos[kit.id]?.tom === 'amarelo' }">
            <span v-if="selos[kit.id]" class="kb-selo" :class="`tom-${selos[kit.id].tom}`">
                <i v-if="selos[kit.id].estrela" class="fas fa-star" aria-hidden="true"></i>{{ selos[kit.id].texto }}
            </span>
            <div class="kb-foto">
                <img v-if="fotoDoKit(kit)" :src="fotoDoKit(kit)" :alt="nomeDoKit(kit)" loading="lazy" decoding="async">
            </div>
            <h3>{{ nomeDoKit(kit) }}</h3>
            <p class="kb-itens">{{ itensDoKit(kit) }}</p>
            <p class="kb-linha-preco"><b>{{ formatPrice(precoDoKit(kit)) }}</b><s v-if="precoDeDoKit(kit)">{{ formatPrice(precoDeDoKit(kit)) }}</s></p>
            <span class="kb-pilulas">
                <span v-if="unidadesDoKit(kit) > 1" class="kb-pilula azul">{{ formatPrice(porUnidade(kit)) }} por brinquedo</span>
                <span v-if="economia(kit)" class="kb-pilula verde">Economize {{ formatPrice(economia(kit)) }}</span>
            </span>
            <button type="button" class="pb-btn" :disabled="kit.stock < 1" :data-kzm="`kit-cartao:${kit.id}`" @click="emit('escolher', kit)">
                {{ kit.stock < 1 ? 'Esgotado' : `Quero o ${nomeDoKit(kit)}` }}
            </button>
        </article>
    </div>
</template>
