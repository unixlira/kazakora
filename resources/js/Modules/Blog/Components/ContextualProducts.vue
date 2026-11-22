<script setup>
// Vitrine discreta: produtos ligados à matéria (ou às matérias da página). Sem movimento automático.
import { computed } from 'vue';
import { brl } from '../blogFormat';
const props=defineProps({products:{type:Array,default:()=>[]},label:{type:String,default:'Na vitrine'},note:{type:String,default:''},limit:{type:Number,default:6}});
const safe=computed(()=>props.products.filter(p=>/^\/produtos\/[^/]+/.test(p.url || '') && p.name).slice(0,props.limit));
</script>
<template>
  <aside v-if="safe.length" class="vitrine" :aria-label="label">
    <p class="vitrine-label">{{label}}</p>
    <p v-if="note" class="vitrine-note">{{note}}</p>
    <ul class="vitrine-list">
      <li v-for="p in safe" :key="p.id">
        <a :href="p.url" class="vitrine-item">
          <span class="vitrine-thumb"><img v-if="p.image_url" :src="p.image_url" :alt="p.name" width="72" height="72" loading="lazy"></span>
          <span class="vitrine-text"><span class="vitrine-name">{{p.name}}</span><strong v-if="brl(p.price)" class="vitrine-price">{{brl(p.price)}}</strong><span class="vitrine-cta">Ver na loja</span></span>
        </a>
      </li>
    </ul>
  </aside>
</template>
