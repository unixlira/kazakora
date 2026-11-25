<script setup>
// Resultado dos links do e-mail da newsletter (pedido do Lira 24/11/2026):
// confirmação do duplo opt-in e saída com 1 clique.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    situacao: { type: String, required: true },
    token: { type: String, default: null },
});

const textos = {
    confirmado: { titulo: 'Inscrição confirmada!', texto: 'Pronto! A partir de agora você recebe as novidades, ofertas e matérias do blog da KazaKora no seu e-mail.', icone: 'fa-circle-check text-green-600' },
    descadastrado: { titulo: 'Você saiu da newsletter', texto: 'Não vamos mais mandar a newsletter para este e-mail. E-mails do seu pedido (confirmação, envio) continuam chegando normalmente.', icone: 'fa-circle-check text-store-fg' },
    invalido: { titulo: 'Link inválido', texto: 'Esse link não existe mais ou foi copiado pela metade. Se quiser se inscrever, use o campo no fim da página.', icone: 'fa-circle-exclamation text-amber-600' },
};
const atual = computed(() => textos[props.situacao] ?? textos.invalido);
</script>

<template>
    <Head :title="atual.titulo" />

    <AppLayout>
        <div class="mx-auto max-w-[560px] px-4 py-16 text-center">
            <i class="fa-solid text-4xl" :class="atual.icone" aria-hidden="true"></i>
            <h1 class="font-display mt-4 text-3xl font-semibold">{{ atual.titulo }}</h1>
            <p class="mt-3 text-store-fg-muted">{{ atual.texto }}</p>
            <div class="mt-8 flex flex-col items-center gap-3">
                <Link href="/" class="rounded-lg bg-store-accent px-5 py-3 font-semibold text-store-accent-contrast hover:opacity-90">Ir para a loja</Link>
                <Link href="/blog" class="text-sm font-semibold underline">Ler o blog</Link>
                <Link v-if="situacao === 'descadastrado' && token" :href="`/newsletter/confirmar/${token}`" class="mt-4 text-xs text-store-fg-muted underline">
                    Saiu sem querer? Voltar a receber
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
