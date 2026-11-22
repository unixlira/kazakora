<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
defineProps({src:String,poster:String,captions:String});
const video=ref(null), playing=ref(false), muted=ref(true), volume=ref(1), time=ref(0), duration=ref(0), error=ref(''), captionsOn=ref(false);
async function toggle(){try{if(video.value.paused) { document.querySelectorAll('video').forEach(v=>{if(v!==video.value)v.pause()}); await video.value.play(); } else video.value.pause();}catch{error.value='Não foi possível reproduzir. Tente novamente.'}}
function sound(){video.value.muted=!video.value.muted; muted.value=video.value.muted;}
function changeVolume(){video.value.volume=Number(volume.value); video.value.muted=Number(volume.value)===0; muted.value=video.value.muted;}
function caption(){captionsOn.value=!captionsOn.value; for(const track of video.value.textTracks)track.mode=captionsOn.value?'showing':'hidden';}
function hidden(){if(document.hidden)video.value?.pause();}
onMounted(()=>document.addEventListener('visibilitychange',hidden));onUnmounted(()=>document.removeEventListener('visibilitychange',hidden));
</script>
<template><section class="inline-player" aria-label="Vídeo da publicação"><video ref="video" :src="src" :poster="poster" playsinline muted preload="metadata" disablepictureinpicture disableremoteplayback controlslist="nofullscreen nodownload noremoteplayback" @play="playing=true" @pause="playing=false" @ended="playing=false" @timeupdate="time=video.currentTime" @loadedmetadata="duration=video.duration" @error="error='Vídeo indisponível. O texto da publicação continua abaixo.'"><track v-if="captions" kind="captions" :src="captions" srclang="pt" label="Português"></video><div class="video-controls"><button @click="toggle" :aria-label="playing?'Pausar vídeo':'Reproduzir vídeo'">{{playing?'Ⅱ':'▶'}}</button><input aria-label="Posição do vídeo" type="range" min="0" :max="duration || 0" step="0.1" :value="time" @input="video.currentTime=Number($event.target.value)"><button @click="sound" :aria-label="muted?'Ativar som':'Silenciar'">{{muted?'Som off':'Som on'}}</button><input class="volume" aria-label="Volume" type="range" min="0" max="1" step="0.05" v-model="volume" @input="changeVolume"><button v-if="captions" :aria-pressed="captionsOn" @click="caption">CC</button></div><p v-if="error" role="alert">{{error}}</p></section></template>
