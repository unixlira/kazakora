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

const campo = 'w-full rounded-lg border border-store-border-strong bg-store-bg-raised px-3.5 py-3 text-[15px] text-store-fg placeholder:text-store-fg-faint focus:border-store-accent focus:outline-none';
</script>

<template>
<section class="mt-16 border-t border-store-border bg-store-bg-sunken" aria-labelledby="newsletter-titulo">
    <div class="mx-auto grid max-w-[1320px] gap-6 px-4 py-10 md:px-6 lg:grid-cols-[1fr_minmax(0,560px)] lg:items-center lg:gap-12 lg:py-12">
        <div>
            <p class="font-store-mono text-xs uppercase tracking-wider text-store-fg-muted">
                <i class="fa-regular fa-envelope mr-1.5" aria-hidden="true"></i>Newsletter KazaKora
            </p>
            <h2 id="newsletter-titulo" class="font-display mt-2 text-2xl font-semibold leading-tight md:text-3xl">
                Novidades da KazaKora no seu e-mail
            </h2>
            <p class="mt-2 max-w-[46ch] text-sm text-store-fg-muted md:text-base">
                Lançamentos, ofertas e as matérias do nosso blog, sem encher sua caixa de entrada.
            </p>
        </div>

        <div v-if="sucesso" class="rounded-2xl border border-green-200 bg-green-50 p-5 text-green-800" role="status" aria-live="polite">
            <p class="font-semibold"><i class="fa-solid fa-circle-check mr-1.5" aria-hidden="true"></i>{{ sucesso }}</p>
            <p class="mt-1 text-sm">Abra o e-mail e toque em "Confirmar inscrição". Não chegou? Olhe a caixa de spam ou promoções.</p>
        </div>

        <form v-else class="relative flex flex-col gap-3" novalidate @submit.prevent="enviar">
            <!-- E-mail primeiro no celular (é o que importa); no computador, nome à esquerda. -->
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <div class="sm:order-2">
                    <label class="sr-only" for="newsletter-email">Seu e-mail</label>
                    <input id="newsletter-email" v-model="email" type="email" inputmode="email" autocomplete="email" maxlength="160" required placeholder="Seu melhor e-mail"
                        :class="campo" :aria-invalid="!!erros.email" aria-describedby="newsletter-erro-email">
                    <p v-if="erros.email" id="newsletter-erro-email" class="mt-1 text-xs text-red-600">{{ erros.email }}</p>
                </div>
                <div class="sm:order-1">
                    <label class="sr-only" for="newsletter-nome">Seu nome (opcional)</label>
                    <input id="newsletter-nome" v-model="nome" type="text" autocomplete="given-name" maxlength="120" placeholder="Seu nome (opcional)" :class="campo">
                </div>
            </div>

            <label class="flex items-start gap-2.5 text-xs leading-relaxed text-store-fg-muted">
                <input v-model="consentimento" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 accent-[#111111]" :aria-invalid="!!erros.consentimento">
                <span>
                    Quero receber e-mails da KazaKora com novidades, ofertas e as matérias do blog. Posso sair quando quiser, com 1 clique.
                    Veja a <Link href="/politica-de-privacidade" class="underline hover:text-store-fg">Política de Privacidade</Link>.
                </span>
            </label>
            <p v-if="erros.consentimento" class="-mt-1 text-xs text-red-600">{{ erros.consentimento }}</p>

            <!-- Armadilha pra robô: invisível pra quem usa o site. -->
            <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                <label>Seu site <input v-model="website" type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <button type="submit" :disabled="enviando"
                class="rounded-lg bg-store-accent px-5 py-3 text-[15px] font-semibold text-store-accent-contrast transition hover:opacity-90 disabled:opacity-50 sm:self-start">
                <i v-if="enviando" class="fa-solid fa-spinner mr-1.5 animate-spin" aria-hidden="true"></i>
                Quero receber
            </button>
            <p v-if="erros.geral" class="text-xs text-red-600" role="alert">{{ erros.geral }}</p>
        </form>
    </div>
</section>
</template>
