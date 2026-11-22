<script setup>
import { ref, computed } from 'vue';
import BlogIcon from './BlogIcon.vue';
const props=defineProps({post:Object,interaction:{type:Object,default:()=>({})},compact:Boolean});
const liked=ref(!!props.interaction.liked), count=ref(props.interaction.likesCount ?? props.post.likes_count), busy=ref(false), body=ref(''), message=ref(''), shareDialog=ref(null), shareMessage=ref('');
const url=computed(()=>`${window.location.origin}/blog/${encodeURIComponent(props.post.slug)}`);
const whatsappUrl=computed(()=>`https://wa.me/?text=${encodeURIComponent(`${props.post.title} ${url.value}`)}`);
const canNativeShare=computed(()=>typeof navigator!=='undefined' && typeof navigator.share==='function');
const commentsCount=computed(()=>Number(props.post.comments_count) || 0);
async function request(path,method,data){const token=document.querySelector('meta[name="csrf-token"]')?.content;const match=document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);const response=await fetch(`/blog/${encodeURIComponent(props.post.slug)}/${path}`,{method,credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json',...(token?{'X-CSRF-TOKEN':token}:{}),...(match?{'X-XSRF-TOKEN':decodeURIComponent(match[1])}:{})},...(data?{body:JSON.stringify(data)}:{})});let json={};try{json=await response.json();}catch{}if(!response.ok)throw new Error(response.status===429?'Muitas tentativas. Aguarde e tente novamente.':response.status===401||response.status===419?'Sua sessão expirou. Entre novamente; seu texto foi preservado.':json.errors?.body?.[0]||json.message||'Não foi possível enviar. Tente novamente.');return json;}
async function like(){if(props.interaction.canInteract !== true)return;busy.value=true;message.value='';try{const result=await request('like',liked.value?'DELETE':'PUT');liked.value=result.liked;count.value=result.likesCount;}catch(e){message.value=e.message;}finally{busy.value=false;}}
async function comment(){if(props.interaction.canInteract !== true)return;busy.value=true;message.value='';try{const result=await request('comments','POST',{body:body.value});if(result.status!=='pending' && result.comment?.status!=='pending')throw new Error('Resposta inesperada; não foi possível confirmar a revisão.');message.value='Comentário recebido para revisão. Só ficará público após aprovação.';body.value='';}catch(e){message.value=e.message;}finally{busy.value=false;}}
async function share(){shareMessage.value='';try{if(canNativeShare.value){await navigator.share({title:props.post.title,url:url.value});shareMessage.value='Pronto, compartilhado.';}else await copy();}catch(e){shareMessage.value=e.name==='AbortError'?'Compartilhamento cancelado.':'Não foi possível compartilhar. Copie o endereço abaixo.';}}
async function copy(){try{await navigator.clipboard.writeText(url.value);shareMessage.value='Link copiado.';}catch{shareMessage.value='Não foi possível copiar. Selecione o endereço e copie manualmente.';}}
</script>
<template>
  <section class="social-actions" :class="compact ? 'is-compact' : 'is-full'" aria-label="Interações">
    <div class="social-row">
      <button v-if="interaction.canInteract" type="button" class="social-btn like-btn" :class="{'is-liked':liked}" @click="like" :disabled="busy" :aria-pressed="liked" :aria-label="`${liked?'Descurtir':'Curtir'} · ${Number.isFinite(count)?count:0} curtidas`">
        <BlogIcon name="heart" :filled="liked"/><span v-if="!compact">{{liked?'Curtido':'Curtir'}}</span><span class="social-count" v-if="Number.isFinite(count)">{{count}}</span>
      </button>
      <a v-else class="social-btn like-btn" :href="interaction.loginUrl || '/entrar'" :aria-label="`Entre para curtir · ${Number.isFinite(count)?count:0} curtidas`" title="Entre para curtir e comentar">
        <BlogIcon name="heart"/><span v-if="!compact">Entre para curtir</span><span class="social-count" v-if="Number.isFinite(count)">{{count}}</span>
      </a>
      <a class="social-btn" :href="compact ? `/blog/${post.slug}#comentarios` : `#editorial-comment-${post.id}`" :aria-label="`${commentsCount} comentários${compact?'':' · comentar'}`">
        <BlogIcon name="comment"/><span v-if="!compact">Comentar</span><span class="social-count">{{commentsCount}}</span>
      </a>
      <template v-if="compact">
        <button type="button" class="social-btn share-btn" @click="shareDialog.showModal()" aria-label="Compartilhar"><BlogIcon name="share"/></button>
      </template>
      <template v-else>
        <span class="social-sep" aria-hidden="true"></span>
        <a class="social-btn whatsapp-btn" :href="whatsappUrl" target="_blank" rel="noopener noreferrer"><BlogIcon name="whatsapp"/><span>WhatsApp</span></a>
        <button type="button" class="social-btn" @click="copy"><BlogIcon name="link"/><span>Copiar link</span></button>
        <button v-if="canNativeShare" type="button" class="social-btn" @click="share"><BlogIcon name="share"/><span>Mais opções</span></button>
      </template>
    </div>
    <p class="social-status" aria-live="polite">{{message}}<template v-if="!compact && shareMessage"> {{shareMessage}}</template></p>
    <slot/>
    <form v-if="interaction.canInteract && !compact" class="comment-form" @submit.prevent="comment">
      <label :for="`editorial-comment-${post.id}`">Deixe seu comentário</label>
      <textarea :id="`editorial-comment-${post.id}`" v-model="body" required maxlength="2000" rows="3" placeholder="Conte como foi na sua casa…"></textarea>
      <div class="comment-form-foot"><p class="muted">Todo comentário passa por revisão antes de aparecer. Sem links ou ofensas.</p><button class="btn-primary" :disabled="busy || !body.trim()" type="submit">{{busy?'Enviando…':'Enviar para revisão'}}</button></div>
    </form>
    <p v-else-if="!compact" class="comment-login"><a :href="interaction.loginUrl || '/entrar'">Entre na sua conta</a> para curtir e comentar.</p>
    <dialog ref="shareDialog" class="share-dialog" :aria-labelledby="`share-title-${post.id}`">
      <div class="share-dialog-head"><h2 :id="`share-title-${post.id}`">Compartilhar esta matéria</h2><button type="button" class="icon-btn" @click="shareDialog.close()" aria-label="Fechar"><BlogIcon name="close"/></button></div>
      <p class="share-dialog-title">{{post.title}}</p>
      <div class="share-options">
        <a class="social-btn whatsapp-btn" :href="whatsappUrl" target="_blank" rel="noopener noreferrer"><BlogIcon name="whatsapp"/><span>WhatsApp</span></a>
        <button type="button" class="social-btn" @click="copy"><BlogIcon name="link"/><span>Copiar link</span></button>
        <button v-if="canNativeShare" type="button" class="social-btn" @click="share"><BlogIcon name="share"/><span>Mais opções</span></button>
      </div>
      <input aria-label="Endereço da matéria" :value="url" readonly @focus="$event.target.select()">
      <p class="social-status" aria-live="polite">{{shareMessage}}</p>
    </dialog>
  </section>
</template>
