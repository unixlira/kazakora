<script setup>
import { computed, ref, inject, onMounted, onUnmounted } from 'vue';
const allPaused = inject('blogMotionPaused', ref(false));
const props=defineProps({products:{type:Array,default:()=>[]},paused:Boolean,label:{type:String,default:'Nesta ideia'}});
const hidden=ref(false);function visibility(){hidden.value=document.hidden;}onMounted(()=>document.addEventListener('visibilitychange',visibility));onUnmounted(()=>document.removeEventListener('visibilitychange',visibility));
const safe=computed(()=>props.products.filter(p=>/^\/produtos\/[^/]+/.test(p.url || '') && p.name));
const price=p=>p.price!==null && p.price!==undefined && Number.isFinite(Number(p.price)) && Number(p.price)>0 ? new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(p.price)) : '';
</script>
<template><aside v-if="safe.length" class="product-rail" :class="{'motion-paused':paused||hidden||allPaused,'single-product':safe.length<2}" :aria-label="label"><h2>{{label}}</h2><div class="rail-window" tabindex="0" aria-label="Produtos relacionados; role para explorar"><div class="rail-track"><div v-for="copy in 2" :key="copy" class="rail-group" :aria-hidden="copy===2 ? 'true':undefined" :inert="copy===2 ? true : undefined"><a v-for="p in safe" :key="p.id" :href="p.url" class="editorial-product"><img v-if="p.image_url" :src="p.image_url" :alt="p.name" width="160" height="130"><span class="product-name">{{p.name}}</span><strong v-if="price(p)">{{price(p)}}</strong><span class="product-link">Ver produto ↗</span></a></div></div></div></aside></template>
