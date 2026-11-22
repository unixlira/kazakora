<script setup>
import { Head } from '@inertiajs/vue3';
import BlogLayout from './BlogLayout.vue';
import EditorialStory from './Components/EditorialStory.vue';
defineProps({ posts: {type:Array,default:()=>[]}, filters: {type:Object,default:()=>({})}, hasMore:Boolean, nextPageUrl:String, previousPageUrl:String });
</script>
<template>
  <BlogLayout>
    <Head title="Ideias para a vida real | Blog KazaKora"/>
    <div class="blog-intro">
      <span class="eyebrow">Caderno KazaKora</span><h1>Ideias para a vida real.</h1>
      <p>Vídeos, receitas e descobertas para experimentar em casa.</p>
      <form action="/blog" method="get" class="action-row">
        <label>Categoria <input name="categoria" :value="filters.categoria || ''" maxlength="100" placeholder="Todas as categorias"></label>
        <label>Tipo <select name="tipo" :value="filters.tipo || ''"><option value="">Todos</option><option value="recipe">Receitas</option><option value="news">Notícias</option><option value="video">Vídeos</option></select></label>
        <button type="submit">Filtrar</button><a href="/blog">Limpar filtros</a>
      </form>
    </div>
    <section v-if="!posts.length" class="empty-state"><h2>Nenhuma publicação encontrada.</h2><p>Experimente outros filtros ou volte depois da revisão editorial.</p><a href="/?todos=1#produtos">Conheça o catálogo →</a></section>
    <EditorialStory v-for="post in posts" :key="post.id" :post="post" :interaction="post.interaction || {}"/>
    <nav aria-label="Páginas do caderno" class="action-row"><a v-if="previousPageUrl" :href="previousPageUrl">← Anteriores</a><a v-if="hasMore && nextPageUrl" :href="nextPageUrl">Próximas →</a></nav>
  </BlogLayout>
</template>
