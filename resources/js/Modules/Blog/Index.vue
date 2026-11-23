<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import BlogLayout from './BlogLayout.vue';
import StoryCard from './Components/StoryCard.vue';
import ContextualProducts from './Components/ContextualProducts.vue';
import BlogIcon from './Components/BlogIcon.vue';
import { TYPES, todayLine, blogUrl } from './blogFormat';
const props = defineProps({ posts: {type:Array,default:()=>[]}, filters: {type:Object,default:()=>({})}, hasMore:Boolean, nextPageUrl:String, previousPageUrl:String, pagination:{type:Object,default:()=>({current:1,last:1,links:[]})}, products:{type:Array,default:()=>[]}, categories:{type:Array,default:()=>[]} });
const firstPage = computed(() => !props.previousPageUrl);
// Matéria de capa só na 1ª página; o resto vira grade, sempre na ordem de publicação (mais nova primeiro).
const lead = computed(() => firstPage.value ? props.posts[0] : null);
const rest = computed(() => firstPage.value ? props.posts.slice(1) : props.posts);
const sections = computed(() => [{ key: '', label: 'Tudo' }, ...Object.entries(TYPES).map(([key, t]) => ({ key, label: t.section }))]);
const currentCategory = computed(() => props.categories.find(c => c.slug === props.filters.categoria));
const today = todayLine();
</script>
<template>
  <BlogLayout>
    <Head title="Caderno KazaKora: novidades, receitas e achados para a casa"/>
    <section class="masthead">
      <div class="masthead-inner">
        <p class="masthead-date">{{ today }}</p>
        <h1 class="masthead-title">Caderno <em>KazaKora</em></h1>
        <p class="masthead-dek">Novidades, receitas e achados para a vida em casa.</p>
      </div>
      <nav class="sections" aria-label="Seções do caderno">
        <a v-for="s in sections" :key="s.key" :href="blogUrl({ categoria: filters.categoria, tipo: s.key })" :class="{ 'is-active': (filters.tipo || '') === s.key }" :aria-current="(filters.tipo || '') === s.key ? 'page' : undefined">{{ s.label }}</a>
      </nav>
    </section>

    <div v-if="filters.categoria" class="filter-bar">
      <span>Editoria: <strong>{{ currentCategory?.name || filters.categoria }}</strong></span>
      <a :href="blogUrl({ tipo: filters.tipo })" class="filter-clear"><BlogIcon name="close"/> Ver todas as editorias</a>
    </div>

    <section v-if="!posts.length" class="empty-state">
      <h2>Nenhuma matéria por aqui ainda.</h2>
      <p>Experimente outra seção ou volte em breve: tem pauta nova saindo da redação.</p>
      <a href="/blog" class="btn-primary">Ver o caderno inteiro</a>
    </section>

    <template v-else>
      <StoryCard v-if="lead" lead :post="lead" :interaction="lead.interaction || {}" class="lead-story"/>

      <div class="feed-layout">
        <section class="feed-main" aria-labelledby="feed-title">
          <h2 id="feed-title" class="rule-title" v-if="rest.length">{{ firstPage ? 'Mais recentes' : 'Matérias anteriores' }}</h2>
          <div class="card-grid">
            <StoryCard v-for="post in rest" :key="post.id" :post="post" :interaction="post.interaction || {}"/>
          </div>
          <nav v-if="previousPageUrl || (hasMore && nextPageUrl)" aria-label="Páginas do caderno" class="pager">
            <a v-if="previousPageUrl" :href="previousPageUrl" class="pager-btn" rel="prev"><BlogIcon name="arrow-left"/> <span class="pager-label">Mais novas</span></a>
            <ol v-if="pagination.last > 1" class="pager-pages">
              <li v-for="(l, i) in pagination.links" :key="i">
                <span v-if="l.gap" class="pager-gap" aria-hidden="true">…</span>
                <span v-else-if="l.current" class="pager-num is-current" aria-current="page">{{ l.page }}</span>
                <a v-else :href="l.url" class="pager-num" :aria-label="`Página ${l.page}`">{{ l.page }}</a>
              </li>
            </ol>
            <a v-if="hasMore && nextPageUrl" :href="nextPageUrl" class="pager-btn pager-next" rel="next"><span class="pager-label">Mais antigas</span> <BlogIcon name="arrow"/></a>
          </nav>
        </section>

        <aside class="feed-aside">
          <nav v-if="categories.length" class="aside-block" aria-label="Editorias">
            <p class="vitrine-label">Editorias</p>
            <ul class="cat-list">
              <li v-for="c in categories" :key="c.slug"><a :href="blogUrl({ categoria: c.slug, tipo: filters.tipo })" :class="{ 'is-active': c.slug === filters.categoria }" :aria-current="c.slug === filters.categoria ? 'true' : undefined"><span>{{ c.name }}</span><span class="cat-count">{{ c.count }}</span></a></li>
            </ul>
          </nav>
          <ContextualProducts :products="products" label="Citados nas matérias" :limit="4" class="aside-block"/>
        </aside>
      </div>
    </template>
  </BlogLayout>
</template>
