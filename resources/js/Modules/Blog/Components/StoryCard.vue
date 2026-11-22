<script setup>
import { computed } from 'vue';
import SocialActions from './SocialActions.vue';
import BlogIcon from './BlogIcon.vue';
import { typeLabel, shortDate, longDate, readingMinutes } from '../blogFormat';
const props = defineProps({ post: { type: Object, required: true }, interaction: { type: Object, default: () => ({}) }, lead: Boolean, teaser: Boolean });
const href = computed(() => `/blog/${encodeURIComponent(props.post.slug)}`);
const minutes = computed(() => readingMinutes(props.post.body || props.post.excerpt));
</script>
<template>
  <article class="story-card" :class="[lead ? 'is-lead' : 'is-card', `type-${post.type}`]">
    <a :href="href" class="story-card-media" tabindex="-1" aria-hidden="true">
      <img v-if="post.poster_url" :src="post.poster_url" alt="" :loading="lead ? 'eager' : 'lazy'" :fetchpriority="lead ? 'high' : undefined" width="640" height="480">
      <span v-else class="story-card-fallback">{{ typeLabel(post.type) }}</span>
      <span v-if="post.type === 'video'" class="story-card-play"><BlogIcon name="play"/></span>
    </a>
    <div class="story-card-body">
      <p class="kicker"><span class="kicker-type">{{ typeLabel(post.type) }}</span><span v-if="post.category_name || post.category" class="kicker-cat">{{ post.category_name || post.category }}</span></p>
      <h2 v-if="lead" class="story-card-title"><a :href="href">{{ post.title }}</a></h2>
      <h3 v-else class="story-card-title"><a :href="href">{{ post.title }}</a></h3>
      <p v-if="post.excerpt" class="story-card-excerpt">{{ post.excerpt }}</p>
      <p class="story-card-meta">
        <time :datetime="post.published_at">{{ lead ? longDate(post.published_at) : shortDate(post.published_at) }}</time>
        <span v-if="!teaser" aria-hidden="true">·</span><span v-if="!teaser"><BlogIcon name="clock"/> {{ minutes }} min</span>
      </p>
      <div v-if="!teaser" class="story-card-foot">
        <a v-if="lead" :href="href" class="btn-primary">Ler matéria <BlogIcon name="arrow"/></a>
        <SocialActions compact :post="post" :interaction="interaction"/>
      </div>
    </div>
  </article>
</template>
