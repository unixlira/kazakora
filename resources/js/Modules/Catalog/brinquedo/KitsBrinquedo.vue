<script setup>
// Kits da página de brinquedo (pedido do Lira 24/11/2026), com a estrutura e as medidas da referência:
// "lista" na caixa de compra (cartão com caixinha de marcar, selo no canto, nome, itens, de/por e
// pílulas de preço por brinquedo e economia) e "cartoes" na seção "Escolha o tamanho da diversão"
// (selo no alto, foto quadrada, nome, itens, preço, pílulas e botão verde de largura total).
import { computed } from 'vue';
import { formatPrice } from '@/Shared/productCard';
import Icone from './Icone.vue';
import { ICONES } from './icones';
import { acrescimoDoKit, argumentoDoKit, composicaoDoKit, economia, unidadesDoKit, fotoDoKit, itensDoKit, nomeCurtoDoKit, ordenarKits, porUnidade, precoDeDoKit, precoDoKit, selosDosKits, srcsetDoKit } from './kits';

const props = defineProps({
    opcoes: { type: Array, required: true },
    escolhidaId: { type: Number, default: null },
    formato: { type: String, default: 'lista' },
});
const emit = defineEmits(['escolher']);

const kits = computed(() => ordenarKits(props.opcoes));
const selos = computed(() => selosDosKits(props.opcoes));
// Partes do kit para a faixa da foto: "Livro + Lousa Mágica LED" → ["Livro", "Lousa Mágica LED"].
// Com partes no conteúdo da página (kit_partes), cada uma traz o próprio ícone.
const iconeDaParte = (parte) => (/livro/i.test(parte) ? 'book-open' : /lousa|desenh/i.test(parte) ? 'palette' : /quebra|puzzle|cabe/i.test(parte) ? 'puzzle' : 'gift');
const partesDoKit = (kit) => (kit.kit_partes?.length
    ? kit.kit_partes.map((p) => ({ texto: p.texto, icone: ICONES[p.icone] ? p.icone : 'gift' }))
    : composicaoDoKit(kit).split(/\s\+\s/).map((p) => p.trim()).filter(Boolean).map((texto) => ({ texto, icone: iconeDaParte(texto) })));
// "R$ X por brinquedo" só faz sentido com 2 ou mais brinquedos no kit (com 1 repetiria o preço).
</script>

<template>
    <div v-if="formato === 'lista'" class="kb-lista" role="radiogroup" aria-label="Escolha o seu kit">
        <button v-for="kit in kits" :key="kit.id" type="button" role="radio" class="kb-kit"
            :class="{ 'is-sel': kit.id === escolhidaId, esgotado: kit.stock < 1 }"
            :aria-checked="kit.id === escolhidaId" :data-kzm="`kit:${kit.id}`" @click="emit('escolher', kit)">
            <span v-if="selos[kit.id]" class="kb-selo" :class="`tom-${selos[kit.id].tom}`">
                <Icone v-if="selos[kit.id].estrela" nome="star" :tamanho="12" :traco="2.4" />{{ selos[kit.id].texto }}
            </span>
            <span class="kb-marca" aria-hidden="true"><Icone nome="check" :tamanho="16" :traco="3.4" /></span>
            <span class="kb-info">
                <span class="kb-nome">{{ nomeCurtoDoKit(kit) }}</span>
                <span class="kb-itens">{{ kit.stock < 1 ? 'Esgotado' : itensDoKit(kit) }}</span>
                <span v-if="kit.kit_acrescenta && kit.stock > 0" class="kb-acresc">{{ kit.kit_acrescenta }}</span>
            </span>
            <span class="kb-precos">
                <s v-if="precoDeDoKit(kit)">{{ formatPrice(precoDeDoKit(kit)) }}</s>
                <b>{{ formatPrice(precoDoKit(kit)) }}</b>
            </span>
            <span class="kb-pilulas">
                <span v-if="kit.kit_acrescenta && acrescimoDoKit(kit, opcoes)" class="kb-pilula azul">só + {{ formatPrice(acrescimoDoKit(kit, opcoes)) }}</span>
                <span v-else-if="unidadesDoKit(kit) > 1" class="kb-pilula azul">{{ formatPrice(porUnidade(kit)) }} por brinquedo</span>
                <span v-if="economia(kit)" class="kb-pilula verde">Economize {{ formatPrice(economia(kit)) }}</span>
            </span>
        </button>
    </div>

    <div v-else class="kb-grid pb-reveal" :class="{ escada: kits.some((k) => k.kit_acrescenta) }">
        <article v-for="kit in kits" :key="kit.id" class="kb-kc" :class="{ 'is-hot': selos[kit.id]?.tom === 'amarelo', 'is-best': selos[kit.id]?.tom === 'verde' }">
            <span v-if="selos[kit.id]" class="kb-kc-selo" :class="`tom-${selos[kit.id].tom}`">
                <Icone v-if="selos[kit.id].estrela" nome="star" :tamanho="12" :traco="2.4" />{{ selos[kit.id].texto }}
            </span>
            <div class="kb-kc-img">
                <img v-if="fotoDoKit(kit)" :src="fotoDoKit(kit)" :srcset="srcsetDoKit(kit)" sizes="(max-width: 900px) 92vw, 340px" width="1200" height="1200" :alt="nomeCurtoDoKit(kit)" loading="lazy" decoding="async">
                <!-- Faixa "o que vem" no alto da foto, com um ícone por brinquedo (como a referência). -->
                <span class="kb-kc-faixa" aria-hidden="true">
                    <template v-for="(item, j) in partesDoKit(kit)" :key="j"><b v-if="j" class="kb-kc-mais">+</b><span><Icone :nome="item.icone" :tamanho="18" :traco="2.4" />{{ item.texto }}</span></template>
                </span>
            </div>
            <h3 class="kb-kc-nome">{{ nomeCurtoDoKit(kit) }}</h3>
            <p class="kb-kc-sub">{{ itensDoKit(kit) }}</p>
            <p v-if="argumentoDoKit(kit)" class="kb-kc-arg">{{ argumentoDoKit(kit) }}</p>
            <div class="kb-kc-preco"><b>{{ formatPrice(precoDoKit(kit)) }}</b><s v-if="precoDeDoKit(kit)">{{ formatPrice(precoDeDoKit(kit)) }}</s></div>
            <div class="kb-kc-chips">
                <span v-if="kit.kit_acrescenta && acrescimoDoKit(kit, opcoes)" class="kb-pilula azul">só + {{ formatPrice(acrescimoDoKit(kit, opcoes)) }}</span>
                <span v-else-if="unidadesDoKit(kit) > 1" class="kb-pilula azul">{{ formatPrice(porUnidade(kit)) }} por brinquedo</span>
                <span v-if="economia(kit)" class="kb-pilula verde">Economize {{ formatPrice(economia(kit)) }}</span>
            </div>
            <button type="button" class="pb-btn kb-kc-btn" :disabled="kit.stock < 1" :data-kzm="`kit-cartao:${kit.id}`" @click="emit('escolher', kit)">
                {{ kit.stock < 1 ? 'Esgotado' : `Quero o ${nomeCurtoDoKit(kit)}` }}
            </button>
        </article>
    </div>
</template>
