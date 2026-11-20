<script setup>
import { ref, provide } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { COMPANY } from '@/Shared/company';
import './blog.css';
import './motion.css';
const menu = ref(false);
const allPaused = ref(false);
provide('blogMotionPaused', allPaused);
const page = usePage();
</script>
<template>
 <div class="blog-shell">
  <a class="skip" href="#blog-content">Pular para o conteúdo</a>
  <header class="blog-nav" @keydown.esc="menu=false">
   <a href="/" aria-label="KazaKora — início"><img :src="page.props.marca?.logoNav || '/images/marca/logo-nav.webp'" alt="KazaKora" width="160" height="28"></a>
   <span class="nav-caption">Ideias para a vida real</span>
   <button class="menu-toggle" :aria-expanded="menu" aria-controls="blog-menu" @click="menu=!menu">{{ menu ? 'Fechar' : 'Menu ☰' }}</button>
   <nav id="blog-menu" :class="{open:menu}" aria-label="Navegação principal">
    <a href="/#categorias">Departamentos</a><a href="/?todos=1#produtos">Produtos</a><a href="/blog" aria-current="page">Blog</a><a href="/rastreio">Rastrear pedido</a><a href="/fale-conosco">Fale conosco</a><a :href="page.props.auth?.user ? '/perfil' : '/entrar'">Minha conta</a>
   </nav>
  </header>
  <main id="blog-content"><slot /><div class="global-motion"><button :aria-pressed="allPaused" @click="allPaused=!allPaused">{{allPaused?'Retomar todos os produtos':'Pausar todos os produtos'}}</button></div></main>
  <footer class="blog-footer"><div><img :src="page.props.marca?.logoRodape || '/images/marca/logo-rodape.webp'" alt="KazaKora" width="160" height="28"><p>Curadoria de eletrônicos, gadgets e utensílios de cozinha.</p></div><nav aria-label="Comprar"><h2>Comprar</h2><a href="/#categorias">Departamentos</a><a href="/?todos=1#produtos">Produtos</a></nav><nav aria-label="Atendimento"><h2>Atendimento</h2><a href="/fale-conosco">Fale conosco</a><a :href="`mailto:${COMPANY.email}`">{{ COMPANY.email }}</a><a :href="COMPANY.whatsappLink">{{ COMPANY.whatsappDisplay }}</a></nav><nav aria-label="Institucional"><h2>Institucional</h2><a href="/quem-somos">Quem somos</a><a href="/politica-de-privacidade">Privacidade</a><a href="/termos-de-uso">Termos de uso</a><a href="/trocas-e-devolucoes">Trocas e devoluções</a></nav></footer>
 </div>
</template>
