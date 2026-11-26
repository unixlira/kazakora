<script setup>
// Escada de kits (pedido do Lira 26/11/2026: a oferta do Compressor 4 em 1 também no KazaKora).
// Cada kit é um produto próprio (preço, estoque, fotos e vídeo próprios); o controller manda em
// "kits" os cartões de todos: { titulo, texto, itens: [{ id, slug, url, nome, itens, partes,
// acrescenta, destaque, selo, foto, preco: { de, por } (reais), estoque, disponivel, atual }] }.
// Escolher outro kit abre a página dele (lá o Comprar leva aquele kit ao carrinho), igual ao FerraKora.
// "lista": dentro da caixa de compra. "cartoes": seção com foto e o que vem em cada kit.
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatPrice } from '@/Shared/productCard';

const props = defineProps({
    kits: { type: Object, default: null },
    formato: { type: String, default: 'lista' },
});

const itens = computed(() => props.kits?.itens ?? []);
const economia = (kit) => (kit.preco?.de && kit.preco.de > kit.preco.por ? kit.preco.de - kit.preco.por : 0);
const fotoPequena = (kit) => kit.foto?.thumb_url || kit.foto?.media_url || kit.foto?.url || null;
const fotoGrande = (kit) => kit.foto?.media_url || kit.foto?.url || null;
</script>

<template>
    <div v-if="itens.length >= 2 && formato === 'lista'" class="kits-escada" role="radiogroup" aria-label="Escolha o seu kit">
        <span class="subtitulo">Escolha o seu kit</span>
        <component :is="kit.atual ? 'span' : Link" v-for="kit in itens" :key="kit.id"
            :href="kit.atual ? undefined : kit.url" role="radio" :aria-checked="kit.atual ? 'true' : 'false'"
            class="kit-opcao" :class="{ atual: kit.atual, destaque: kit.destaque, esgotado: !kit.disponivel }"
            :data-kzm="kit.atual ? undefined : `kit:${kit.id}`">
            <span v-if="kit.selo" class="kit-selo" :class="{ destaque: kit.destaque }"><i v-if="kit.destaque" class="fas fa-star" aria-hidden="true"></i>{{ kit.selo }}</span>
            <span class="kit-marca" aria-hidden="true"><i class="fas fa-check"></i></span>
            <img v-if="fotoPequena(kit)" class="kit-foto" :src="fotoPequena(kit)" alt="" width="56" height="56" loading="lazy" decoding="async">
            <span class="kit-info">
                <span class="kit-nome">{{ kit.nome }}</span>
                <span class="kit-itens">{{ kit.disponivel ? kit.itens : 'Esgotado' }}</span>
            </span>
            <span class="kit-lado">
                <span class="kit-precos">
                    <s v-if="kit.preco?.de">{{ formatPrice(kit.preco.de) }}</s>
                    <b>{{ formatPrice(kit.preco.por) }}</b>
                </span>
                <span v-if="kit.disponivel && economia(kit) > 0" class="kit-pilula">Economize {{ formatPrice(economia(kit)) }}</span>
            </span>
        </component>
    </div>

    <section v-else-if="itens.length >= 2" id="kits" data-kzm="kits" class="card kits-cartoes">
        <h2>{{ kits.titulo || 'Escolha o seu kit' }}</h2>
        <p v-if="kits.texto" class="kits-texto">{{ kits.texto }}</p>
        <div class="kits-grade">
            <article v-for="kit in itens" :key="kit.id" class="kit-cartao" :class="{ destaque: kit.destaque, atual: kit.atual }">
                <span v-if="kit.selo" class="kit-selo" :class="{ destaque: kit.destaque }"><i v-if="kit.destaque" class="fas fa-star" aria-hidden="true"></i>{{ kit.selo }}</span>
                <img v-if="fotoGrande(kit)" class="kit-cartao-foto" :src="fotoGrande(kit)" :alt="kit.nome" width="600" height="600" loading="lazy" decoding="async">
                <h3>{{ kit.nome }}</h3>
                <p v-if="kit.acrescenta" class="kit-acrescenta">{{ kit.acrescenta }}</p>
                <ul v-if="kit.partes?.length" class="kit-partes">
                    <li v-for="parte in kit.partes" :key="parte"><i class="fas fa-check" aria-hidden="true"></i> {{ parte }}</li>
                </ul>
                <div class="kit-cartao-preco">
                    <span class="kit-precos">
                        <s v-if="kit.preco?.de">{{ formatPrice(kit.preco.de) }}</s>
                        <b>{{ formatPrice(kit.preco.por) }}</b>
                    </span>
                    <span v-if="kit.disponivel && economia(kit) > 0" class="kit-pilula">Economize {{ formatPrice(economia(kit)) }}</span>
                </div>
                <span v-if="!kit.disponivel" class="btn kit-botao esgotado">Esgotado</span>
                <a v-else-if="kit.atual" href="#comprar" class="btn kit-botao" :data-kzm="`kit-cartao:${kit.id}`">Quero o {{ kit.nome }}</a>
                <Link v-else :href="kit.url" class="btn kit-botao" :data-kzm="`kit-cartao:${kit.id}`">Quero o {{ kit.nome }}</Link>
            </article>
        </div>
    </section>
</template>
