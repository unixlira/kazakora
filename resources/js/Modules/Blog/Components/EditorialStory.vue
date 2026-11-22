<script setup>
import { computed } from 'vue';
import InlineVideo from './InlineVideo.vue';
import ContextualProducts from './ContextualProducts.vue';
import SocialActions from './SocialActions.vue';
import { typeLabel, TYPES, longDate, readingMinutes, paragraphs, blogUrl } from '../blogFormat';
const props=defineProps({post:Object,products:{type:Array,default:()=>[]},comments:{type:Array,default:()=>[]},interaction:{type:Object,default:()=>({})}});
const linked=computed(()=>props.products.length?props.products:props.post.products || []);
const blocks=computed(()=>paragraphs(props.post.body));
const minutes=computed(()=>readingMinutes(props.post.body));
const commentDate=iso=>longDate(iso);
</script>
<template>
  <article class="article" :class="`type-${post.type}`">
    <header class="article-head">
      <nav class="crumbs" aria-label="Você está em"><a href="/blog">Caderno</a><span aria-hidden="true">/</span><a :href="blogUrl({ tipo: post.type })">{{ TYPES[post.type]?.section || 'Matérias' }}</a></nav>
      <p class="kicker"><span class="kicker-type">{{ typeLabel(post.type) }}</span><a v-if="post.category" class="kicker-cat" :href="blogUrl({ categoria: post.category })">{{ post.category_name || post.category }}</a></p>
      <h1 class="article-title">{{ post.title }}</h1>
      <p v-if="post.excerpt" class="article-dek">{{ post.excerpt }}</p>
      <p class="article-byline"><span class="byline-name">Redação KazaKora</span><span aria-hidden="true">·</span><time :datetime="post.published_at">{{ longDate(post.published_at) }}</time><span aria-hidden="true">·</span><span>{{ minutes }} min de leitura</span></p>
    </header>

    <div class="article-layout">
      <div class="article-main">
        <InlineVideo v-if="post.video_url" :src="post.video_url" :poster="post.poster_url" :captions="post.captions_url"/>
        <figure v-else-if="post.poster_url" class="article-figure"><img :src="post.poster_url" :alt="post.title" width="960" height="640" fetchpriority="high"></figure>

        <div class="plain-body article-body">
          <template v-for="(para, i) in blocks" :key="i">
            <h2 v-if="para.kind === 'h'" class="body-subhead">{{ para.text }}</h2>
            <p v-else :class="{ 'has-label': para.label }"><strong v-if="para.label" class="body-label">{{ para.label }}:</strong> {{ para.text }}</p>
          </template>
        </div>
        <p v-if="post.source_url" class="article-source">Fonte: <a :href="post.source_url" rel="noopener noreferrer" target="_blank">{{ post.source_name || 'consultar fonte' }}</a></p>

        <div class="article-products-mobile"><ContextualProducts :products="linked" label="Nesta matéria"/></div>

        <SocialActions :post="post" :interaction="interaction">
          <section id="comentarios" class="comments" aria-labelledby="comments-title">
            <h2 id="comments-title" class="rule-title">Conversa <span class="comments-count">{{ comments.length }}</span></h2>
            <p v-if="!comments.length" class="muted">Ninguém comentou ainda. Conte a sua experiência!</p>
            <ol v-else class="comment-list">
              <li v-for="c in comments" :key="c.id" class="comment">
                <span class="comment-avatar" aria-hidden="true">{{ (c.display_name || '?').trim().charAt(0).toUpperCase() }}</span>
                <div><p class="comment-head"><strong>{{ c.display_name }}</strong><time :datetime="c.created_at">{{ commentDate(c.created_at) }}</time></p><p class="comment-body">{{ c.body }}</p></div>
              </li>
            </ol>
          </section>
        </SocialActions>
      </div>
      <div class="article-aside"><ContextualProducts :products="linked" label="Nesta matéria" note="Produtos citados no texto, à venda na KazaKora."/></div>
    </div>
  </article>
</template>
