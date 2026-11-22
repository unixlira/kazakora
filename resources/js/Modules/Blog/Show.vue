<script setup>
import { Head } from '@inertiajs/vue3';
import BlogLayout from './BlogLayout.vue';
import EditorialStory from './Components/EditorialStory.vue';
import StoryCard from './Components/StoryCard.vue';
defineProps({post:{type:Object,required:true},products:{type:Array,default:()=>[]},comments:{type:Array,default:()=>[]},interaction:{type:Object,default:()=>({})},related:{type:Array,default:()=>[]}});
</script>
<template>
  <BlogLayout>
    <Head :title="`${post.title} | KazaKora`"/>
    <EditorialStory :post="post" :products="products" :comments="comments" :interaction="interaction"/>
    <section v-if="related.length" class="related" aria-labelledby="related-title">
      <h2 id="related-title" class="rule-title">Continue lendo</h2>
      <div class="card-grid is-three"><StoryCard v-for="r in related" :key="r.id" :post="r" teaser/></div>
    </section>
  </BlogLayout>
</template>
