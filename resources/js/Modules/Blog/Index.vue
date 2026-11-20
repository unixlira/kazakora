<script setup>
import { Head } from '@inertiajs/vue3';
import BlogLayout from './BlogLayout.vue';
import EditorialStory from './Components/EditorialStory.vue';
defineProps({posts:{type:Array,default:()=>[]},filters:{type:Object,default:()=>({})}});
</script>
<template><BlogLayout><Head title="Ideias para a vida real | Blog KazaKora"/><div class="blog-intro"><span class="eyebrow">Caderno KazaKora</span><h1>Ideias para a vida real.</h1><p>Vídeos, receitas e descobertas para experimentar em casa.</p></div><section v-if="!posts.length" class="empty-state"><h2>Nosso caderno está começando.</h2><p>As publicações aparecerão aqui depois da revisão editorial.</p><a href="/?todos=1#produtos">Enquanto isso, conheça o catálogo →</a></section><EditorialStory v-for="post in posts" :key="post.id" :post="post"/></BlogLayout></template>
