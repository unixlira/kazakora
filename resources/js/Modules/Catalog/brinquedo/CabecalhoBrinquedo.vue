<script setup>
// Topo da página de brinquedo (pedido do Lira 24/11/2026): no lugar da faixa, do letreiro e do
// cabeçalho da loja, um topo de loja infantil (fundo creme, logo à esquerda, menu no meio com
// destaque colorido ao passar o mouse, lupa, conta e sacola à direita, faixa de quadradinhos
// embaixo e sombra ao rolar). No celular: menu à esquerda, logo no meio, lupa e sacola à direita.
// Os links levam para as páginas de verdade da KazaKora. O rodapé continua o da loja.
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import Icone from './Icone.vue';
import { LOGO_BRINQUEDO, NOME_LOJA_BRINQUEDO } from './marca';

const page = usePage();
const qtdCarrinho = computed(() => page.props.cart?.count ?? 0);
const logado = computed(() => !!page.props.auth?.user);

const LINKS = [
    { texto: 'Início', href: '/' },
    { texto: 'Brinquedos', href: '/?categoria=brinquedos-e-games#produtos' },
    { texto: 'Ofertas', href: '/ofertas' },
    { texto: 'Rastreio', href: '/rastreio' },
    { texto: 'Contato', href: '/fale-conosco' },
];

const rolou = ref(false);
let quadro = false;
const medir = () => { quadro = false; rolou.value = window.scrollY > 10; };
const aoRolar = () => { if (!quadro) { quadro = true; requestAnimationFrame(medir); } };
onMounted(() => { medir(); window.addEventListener('scroll', aoRolar, { passive: true }); });
onUnmounted(() => window.removeEventListener('scroll', aoRolar));

const buscaAberta = ref(false);
const termo = ref('');
const campoBusca = ref(null);
const abrirBusca = async () => {
    buscaAberta.value = !buscaAberta.value;
    if (buscaAberta.value) { await nextTick(); campoBusca.value?.focus(); }
};
const buscar = () => {
    const q = termo.value.trim();
    if (!q) return;
    router.visit(`/?search=${encodeURIComponent(q)}#produtos`);
};

const menuAberto = ref(false);
const alternarMenu = () => {
    menuAberto.value = !menuAberto.value;
    document.body.style.overflow = menuAberto.value ? 'hidden' : '';
};
onUnmounted(() => { document.body.style.overflow = ''; });
</script>

<template>
    <header class="pbt" :class="{ 'pbt-rolou': rolou }">
        <div class="pbt-linha">
            <button type="button" class="pbt-ico pbt-so-celular" aria-label="Abrir menu" :aria-expanded="menuAberto" @click="alternarMenu">
                <Icone nome="menu" :tamanho="24" />
            </button>

            <Link href="/" class="pbt-logo" :aria-label="`${NOME_LOJA_BRINQUEDO} — início`">
                <img :src="LOGO_BRINQUEDO" :alt="NOME_LOJA_BRINQUEDO" width="180" height="48">
            </Link>

            <nav class="pbt-nav" aria-label="Menu principal">
                <Link v-for="link in LINKS" :key="link.href" :href="link.href" class="pbt-nav-link">{{ link.texto }}</Link>
            </nav>

            <div class="pbt-icones">
                <button type="button" class="pbt-ico" aria-label="Buscar" :aria-expanded="buscaAberta" @click="abrirBusca"><Icone nome="search" :tamanho="22" /></button>
                <Link :href="logado ? '/perfil' : '/entrar'" class="pbt-ico pbt-so-computador" :aria-label="logado ? 'Minha conta' : 'Entrar'"><Icone nome="user" :tamanho="22" /></Link>
                <Link href="/carrinho" class="pbt-ico pbt-sacola" aria-label="Carrinho">
                    <Icone nome="shopping-bag" :tamanho="22" />
                    <span v-if="qtdCarrinho > 0" class="pbt-qtd">{{ qtdCarrinho }}</span>
                </Link>
            </div>
        </div>

        <form v-if="buscaAberta" class="pbt-busca" role="search" @submit.prevent="buscar">
            <input ref="campoBusca" v-model="termo" type="search" placeholder="O que você procura?" aria-label="Buscar na loja">
            <button type="submit" class="pbt-busca-btn" aria-label="Buscar"><Icone nome="search" :tamanho="20" /></button>
        </form>

        <!-- Menu do celular -->
        <Teleport to="body">
            <div v-if="menuAberto" class="pbt-gaveta-fundo" @click="alternarMenu"></div>
            <nav v-if="menuAberto" class="pbt-gaveta" aria-label="Menu">
                <button type="button" class="pbt-ico pbt-fechar" aria-label="Fechar menu" @click="alternarMenu"><Icone nome="x" :tamanho="22" /></button>
                <Link v-for="link in LINKS" :key="link.href" :href="link.href" class="pbt-gaveta-link" @click="alternarMenu">{{ link.texto }}</Link>
                <Link :href="logado ? '/perfil' : '/entrar'" class="pbt-gaveta-link" @click="alternarMenu">{{ logado ? 'Minha conta' : 'Entrar' }}</Link>
                <Link href="/carrinho" class="pbt-gaveta-link" @click="alternarMenu">Carrinho</Link>
            </nav>
        </Teleport>
    </header>
</template>
