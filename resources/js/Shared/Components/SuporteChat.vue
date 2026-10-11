<script setup>
// Chat "Falar com suporte" da compra (pedido 2026-10-11). A Manuela responde
// primeiro (pela fila do servidor) e abre o chamado quando o caso é sério;
// quando um atendente assume, as mensagens dele aparecem como "Equipe KazaKora".
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    orderId: { type: Number, required: true },
});

const chamado = ref(null);
const mensagens = ref([]);
const texto = ref('');
const enviando = ref(false);
const erro = ref(null);
const lista = ref(null);
let timer = null;

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

const rolar = () => nextTick(() => { if (lista.value) lista.value.scrollTop = lista.value.scrollHeight; });

const carregar = async () => {
    try {
        const resposta = await fetch(`/pedidos/${props.orderId}/suporte`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!resposta.ok) return;
        const dados = await resposta.json();
        const novas = dados.mensagens.length !== mensagens.value.length;
        chamado.value = dados.chamado;
        mensagens.value = dados.mensagens;
        if (novas) rolar();
    } catch {
        // rede caiu: tenta de novo no próximo ciclo
    }
};

const enviar = async () => {
    const conteudo = texto.value.trim();
    if (!conteudo || enviando.value) return;
    enviando.value = true;
    erro.value = null;
    try {
        const resposta = await fetch(`/pedidos/${props.orderId}/suporte`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ mensagem: conteudo }),
        });
        if (!resposta.ok) throw new Error(resposta.status === 429 ? 'Muitas mensagens seguidas. Espere um pouquinho.' : 'Não foi possível enviar agora.');
        const dados = await resposta.json();
        chamado.value = dados.chamado;
        mensagens.value = dados.mensagens;
        texto.value = '';
        rolar();
    } catch (e) {
        erro.value = e.message;
    } finally {
        enviando.value = false;
    }
};

const digitando = computed(() => {
    const ultima = [...mensagens.value].reverse().find((m) => m.autor !== 'sistema');
    return ultima?.autor === 'cliente' && !chamado.value?.humano_ativo;
});

const hora = (iso) => new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', day: '2-digit', month: '2-digit' }).format(new Date(iso));

const STATUS = { conversa: 'Atendimento com a Manuela', aberto: 'Chamado aberto', em_atendimento: 'Com a equipe KazaKora', resolvido: 'Resolvido', fechado: 'Fechado' };

onMounted(() => {
    carregar();
    timer = setInterval(carregar, 4000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-2xl border border-store-border bg-store-bg-raised shadow-sm">
        <div class="flex items-center gap-3 bg-gradient-to-r from-[#0FB930] to-[#0a8a23] px-4 py-3 text-white">
            <span class="flex h-10 w-10 items-center justify-center rounded-full bg-white text-lg font-black text-[#0FB930]">M</span>
            <div class="min-w-0 flex-1">
                <p class="font-bold leading-tight">Suporte KazaKora</p>
                <p class="truncate text-xs opacity-90">
                    {{ chamado ? STATUS[chamado.status] : 'A Manuela responde na hora' }}<template v-if="chamado"> · {{ chamado.numero }}</template>
                </p>
            </div>
            <span class="flex items-center gap-1 rounded-full bg-white/20 px-2 py-0.5 text-[11px] font-bold"><span class="h-2 w-2 rounded-full bg-white"></span> online</span>
        </div>

        <div ref="lista" class="flex max-h-[420px] min-h-[220px] flex-col gap-2 overflow-y-auto bg-[#f6f7f9] p-4 dark:bg-store-bg">
            <div v-if="!mensagens.length" class="m-auto max-w-[280px] text-center text-sm text-store-fg-muted">
                <i class="far fa-comments text-3xl text-[#0FB930]"></i>
                <p class="mt-2">Oi! Eu sou a <b>Manuela</b>. Me conte como posso ajudar com esta compra.</p>
            </div>

            <template v-for="m in mensagens" :key="m.id">
                <p v-if="m.autor === 'sistema'" class="mx-auto my-1 rounded-full bg-slate-200 px-3 py-1 text-center text-[11px] text-slate-600 dark:bg-slate-700 dark:text-slate-200">{{ m.conteudo }}</p>
                <div v-else class="flex" :class="m.autor === 'cliente' ? 'justify-end' : 'justify-start'">
                    <div class="max-w-[85%] rounded-2xl px-3.5 py-2 text-sm shadow-sm"
                        :class="m.autor === 'cliente' ? 'rounded-br-md bg-[#F27A2A] text-white' : (m.autor === 'atendente' ? 'rounded-bl-md bg-sky-100 text-sky-950' : 'rounded-bl-md bg-white text-slate-800')">
                        <p v-if="m.autor !== 'cliente'" class="mb-0.5 text-[11px] font-bold" :class="m.autor === 'atendente' ? 'text-sky-700' : 'text-[#0FB930]'">
                            {{ m.autor === 'atendente' ? 'Equipe KazaKora' : 'Manuela' }}
                        </p>
                        <p class="whitespace-pre-line">{{ m.conteudo }}</p>
                        <p class="mt-0.5 text-right text-[10px] opacity-70">{{ hora(m.enviada_em) }}</p>
                    </div>
                </div>
            </template>

            <div v-if="digitando" class="flex items-center gap-2 text-xs text-store-fg-muted">
                <span class="flex gap-1 rounded-2xl bg-white px-3 py-2 shadow-sm">
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-[#0FB930]"></span>
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-[#0FB930] [animation-delay:.15s]"></span>
                    <span class="h-1.5 w-1.5 animate-bounce rounded-full bg-[#0FB930] [animation-delay:.3s]"></span>
                </span>
                Manuela está digitando…
            </div>
        </div>

        <form class="flex items-end gap-2 border-t border-store-border bg-store-bg-raised p-3" @submit.prevent="enviar">
            <textarea v-model="texto" rows="1" maxlength="2000" placeholder="Escreva sua mensagem…"
                class="max-h-32 min-h-[44px] flex-1 resize-none rounded-xl border border-store-border-strong bg-store-bg px-3 py-2.5 text-sm text-store-fg focus:border-[#0FB930] focus:outline-none"
                @keydown.enter.exact.prevent="enviar"></textarea>
            <button type="submit" :disabled="enviando || !texto.trim()" aria-label="Enviar"
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#0FB930] text-white shadow transition hover:brightness-95 disabled:opacity-40">
                <i class="fas" :class="enviando ? 'fa-spinner animate-spin' : 'fa-paper-plane'"></i>
            </button>
        </form>
        <p v-if="erro" class="px-4 pb-3 text-xs text-red-600">{{ erro }}</p>
    </div>
</template>
