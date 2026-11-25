<script setup>
// Newsletter antes do rodapé (pedido do Lira 24/11/2026, aula de e-commerce).
// Fica dentro do RodapeLoja, então aparece em todas as páginas da loja e no
// blog. Envia por fetch (sem recarregar a página). O campo "website" é
// armadilha pra robô: escondido de quem usa o site. Duplo opt-in: a pessoa
// confirma pelo e-mail antes de entrar na lista.
import { Link, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const page = usePage();
const email = ref(page.props.auth?.user?.email ?? '');
const nome = ref('');
const consentimento = ref(false);
const website = ref('');
const enviando = ref(false);
const sucesso = ref('');
const erros = ref({});

watch(consentimento, (marcado) => { if (marcado) erros.value = { ...erros.value, consentimento: undefined }; });

const xsrf = () => decodeURIComponent((document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/) ?? [])[1] ?? '');

const enviar = async () => {
    erros.value = {};
    if (!email.value.trim()) {
        erros.value = { email: 'Digite seu e-mail.' };
        return;
    }
    if (!consentimento.value) {
        erros.value = { consentimento: 'Marque a caixinha para aceitar receber nossos e-mails.' };
        return;
    }
    enviando.value = true;
    try {
        const resposta = await fetch('/newsletter', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({
                email: email.value.trim(),
                nome: nome.value.trim() || null,
                consentimento: consentimento.value,
                website: website.value,
                origem: window.location.pathname.slice(0, 255),
            }),
        });
        const dados = await resposta.json().catch(() => ({}));
        if (resposta.status === 422) {
            erros.value = Object.fromEntries(Object.entries(dados.errors ?? {}).map(([campo, msgs]) => [campo, msgs[0]]));
            return;
        }
        if (resposta.status === 429) throw new Error('Muitas tentativas seguidas. Espere alguns minutos e tente de novo.');
        if (!resposta.ok) throw new Error('Não conseguimos fazer sua inscrição agora. Tente de novo em instantes.');
        sucesso.value = dados.mensagem ?? 'Quase lá! Confira seu e-mail para confirmar.';
    } catch (e) {
        erros.value = { geral: e.message };
    } finally {
        enviando.value = false;
    }
};

const campo = 'w-full min-w-0 rounded-xl border-0 bg-white px-4 py-3.5 text-[15px] text-[#1b1714] placeholder:text-[#8a7f77] shadow-sm focus:outline-none focus:ring-4 focus:ring-white/40';
</script>

<template>
<!-- Modelo 8 "Pôr do sol" escolhido pelo Lira (24/11/2026): cartão em degradê laranja→âmbar,
     solto do rodapé (com espaço em volta), texto de notícias populares e ofertas. -->
<section class="mx-auto mt-16 mb-12 max-w-[1320px] px-4 md:px-6" aria-labelledby="newsletter-titulo">
    <div class="relative overflow-hidden rounded-3xl bg-[linear-gradient(120deg,#F44D00_0%,#FF7A1A_55%,#FFB443_100%)] px-6 py-9 text-white shadow-[0_18px_40px_-20px_rgba(244,77,0,0.55)] md:px-12 md:py-12">
        <span class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/15" aria-hidden="true"></span>
        <span class="pointer-events-none absolute -bottom-20 right-24 h-40 w-40 rounded-full bg-white/10" aria-hidden="true"></span>

        <div class="relative grid gap-6 lg:grid-cols-[1fr_minmax(0,540px)] lg:items-center lg:gap-12">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-white/85">
                    <i class="fa-regular fa-envelope mr-1.5" aria-hidden="true"></i>Newsletter KazaKora
                </p>
                <h2 id="newsletter-titulo" class="font-display mt-2 text-[1.7rem] font-extrabold leading-[1.08] [text-wrap:balance] md:text-4xl">
                    Notícias populares e ofertas no seu e-mail
                </h2>
                <p class="mt-3 max-w-[46ch] text-sm text-white/95 md:text-base">
                    Toda semana: as matérias mais lidas do blog e as melhores ofertas da loja, antes de todo mundo.
                </p>
            </div>

            <div v-if="sucesso" class="rounded-2xl bg-white/95 p-5 text-[#1b1714]" role="status" aria-live="polite">
                <p class="font-semibold text-[#1F8A4C]"><i class="fa-solid fa-circle-check mr-1.5" aria-hidden="true"></i>{{ sucesso }}</p>
                <p class="mt-1 text-sm">Abra o e-mail e toque em "Confirmar inscrição". Não chegou? Olhe a caixa de spam ou promoções.</p>
            </div>

            <form v-else class="relative flex flex-col gap-3" novalidate @submit.prevent="enviar">
                <div class="flex flex-col gap-2.5 sm:flex-row">
                    <div class="min-w-0 flex-1">
                        <label class="sr-only" for="newsletter-email">Seu e-mail</label>
                        <input id="newsletter-email" v-model="email" type="email" inputmode="email" autocomplete="email" maxlength="160" required placeholder="Seu melhor e-mail"
                            :class="campo" :aria-invalid="!!erros.email" aria-describedby="newsletter-erro-email">
                    </div>
                    <button type="submit" :disabled="enviando"
                        class="shrink-0 rounded-xl bg-white px-6 py-3.5 text-[15px] font-extrabold text-[#F44D00] shadow-sm transition hover:bg-[#FFF1E8] disabled:opacity-60">
                        <i v-if="enviando" class="fa-solid fa-spinner mr-1.5 animate-spin" aria-hidden="true"></i>
                        Quero receber
                    </button>
                </div>
                <p v-if="erros.email" id="newsletter-erro-email" class="rounded-md bg-white/90 px-2 py-1 text-xs font-semibold text-red-700">{{ erros.email }}</p>

                <label class="flex items-start gap-2.5 text-xs leading-relaxed text-white/90">
                    <input v-model="consentimento" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 accent-white" :aria-invalid="!!erros.consentimento">
                    <span>
                        Quero receber e-mails da KazaKora com notícias, ofertas e as matérias do blog. Posso sair quando quiser, com 1 clique.
                        Veja a <Link href="/politica-de-privacidade" class="underline hover:text-white">Política de Privacidade</Link>.
                    </span>
                </label>
                <p v-if="erros.consentimento" class="-mt-1 rounded-md bg-white/90 px-2 py-1 text-xs font-semibold text-red-700">{{ erros.consentimento }}</p>

                <!-- Armadilha pra robô: invisível pra quem usa o site. -->
                <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                    <label>Seu site <input v-model="website" type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <p v-if="erros.geral" class="rounded-md bg-white/90 px-2 py-1 text-xs font-semibold text-red-700" role="alert">{{ erros.geral }}</p>
            </form>
        </div>
    </div>
</section>
</template>
