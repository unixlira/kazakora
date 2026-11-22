<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import RodapeLoja from '@/Shared/Components/RodapeLoja.vue';
import BlogIcon from './Components/BlogIcon.vue';
import './blog.css';
const menu = ref(false);
const page = usePage();
</script>
<template>
 <div class="blog-shell">
  <a class="skip" href="#blog-content">Pular para o conteúdo</a>
  <header class="blog-nav" @keydown.esc="menu=false">
   <div class="blog-nav-inner">
    <a href="/" class="blog-logo" aria-label="KazaKora — início"><img :src="page.props.marca?.logoNav || '/images/marca/logo-nav.webp'" alt="KazaKora" width="160" height="28"></a>
    <a href="/blog" class="blog-nav-tag">Caderno</a>
    <button type="button" class="menu-toggle" :aria-expanded="menu" aria-controls="blog-menu" :aria-label="menu ? 'Fechar menu' : 'Abrir menu'" @click="menu=!menu"><BlogIcon :name="menu ? 'close' : 'menu'"/></button>
    <nav id="blog-menu" :class="{open:menu}" aria-label="Navegação principal">
     <a href="/#categorias">Departamentos</a><a href="/?todos=1#produtos">Produtos</a><a href="/blog" aria-current="page" class="is-current">Caderno</a><a href="/rastreio">Rastrear pedido</a><a :href="page.props.auth?.user ? '/perfil' : '/entrar'" class="nav-account">{{ page.props.auth?.user ? 'Minha conta' : 'Entrar' }}</a>
    </nav>
   </div>
  </header>
  <main id="blog-content" class="blog-main"><slot /></main>
  <!-- Mesmo rodapé da loja (componente compartilhado com o AppLayout). -->
  <div class="font-store"><RodapeLoja /></div>
 </div>
</template>
