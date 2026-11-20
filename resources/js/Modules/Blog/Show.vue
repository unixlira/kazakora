<script setup>
import { Head } from '@inertiajs/vue3';
import BlogLayout from './BlogLayout.vue';
import EditorialStory from './Components/EditorialStory.vue';
defineProps({post:{type:Object,required:true},products:{type:Array,default:()=>[]},comments:{type:Array,default:()=>[]},interaction:{type:Object,default:()=>({})}});
</script>
<template><BlogLayout><Head :title="`${post.title} | KazaKora`"/><div class="blog-breadcrumb"><a href="/blog">← Todas as ideias</a><span> / {{post.type==='recipe'?'Receitas':'Caderno'}}</span></div><EditorialStory detail :post="post" :products="products" :comments="comments" :interaction="interaction"/></BlogLayout></template>
