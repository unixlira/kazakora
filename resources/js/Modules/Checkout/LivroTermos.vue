<script setup>
// Termos de uso do livro digital (pedido do Lira 2026-10-22): o link do e-mail
// e da página do pedido cai aqui; o modal abre sozinho e o PDF só sai no
// "Concordar e baixar" (POST no mesmo link assinado, que registra o aceite).
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

defineProps({
    livro: { type: Object, required: true },
    pedido: { type: [String, Number], required: true },
    aceitarUrl: { type: String, required: true },
    csrfToken: { type: String, required: true },
});

const aberto = ref(false);
const baixando = ref(false);

onMounted(() => { aberto.value = true; });

// O download é resposta do POST nativo: a página continua aqui.
const concordar = () => {
    baixando.value = true;
    setTimeout(() => { aberto.value = false; }, 400);
};
</script>

<template>
    <Head title="Termos de uso do livro digital" />

    <AppLayout>
        <div class="mx-auto max-w-[640px] px-4 py-12 md:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-[#F27A2A] to-[#e0480f] text-xl text-white shadow-md">
                    <i class="fas fa-gift"></i>
                </span>
                <div>
                    <h1 class="font-display text-3xl font-semibold">Seu livro digital</h1>
                    <p class="text-sm text-store-fg-muted">Presente do pedido {{ pedido }}</p>
                </div>
            </div>

            <div class="mt-8 flex items-center gap-4 rounded-2xl border border-store-border bg-store-bg-raised p-5 shadow-sm">
                <img v-if="livro.capa" :src="livro.capa" alt="" class="h-20 w-14 flex-none rounded object-cover shadow">
                <div class="min-w-0 flex-1">
                    <p class="font-bold">{{ livro.titulo }}</p>
                    <p v-if="baixando" class="mt-1 text-sm text-[#047857]"><i class="fas fa-circle-check mr-1"></i> Download iniciado. Confira a pasta de downloads.</p>
                    <p v-else class="mt-1 text-sm text-store-fg-muted">Para baixar, leia e aceite os termos de uso.</p>
                </div>
                <button type="button" class="flex-none rounded-lg bg-[#c2450c] px-3 py-2 text-xs font-bold text-white hover:brightness-95" @click="aberto = true">
                    <i class="fas fa-download mr-1"></i> {{ baixando ? 'Baixar de novo' : 'Baixar PDF' }}
                </button>
            </div>
        </div>

        <div v-if="aberto" class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" aria-labelledby="termos-titulo" @keydown.esc="aberto = false">
            <form method="POST" :action="aceitarUrl" class="w-full max-w-md rounded-2xl bg-white p-6 text-slate-800 shadow-2xl [color-scheme:light]" @submit="concordar">
                <input type="hidden" name="_token" :value="csrfToken">
                <input type="hidden" name="aceite" value="1">

                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-orange-100 text-2xl text-[#f27a2a]">
                    <i class="fa-solid fa-file-shield"></i>
                </div>
                <h2 id="termos-titulo" class="mt-4 text-center text-xl font-bold">Termos de uso</h2>
                <p class="mt-2 text-center text-sm text-slate-500">
                    Você concorda em usar este livro digital apenas para fins pessoais e não comerciais?
                </p>

                <ul class="mt-5 space-y-2 rounded-xl bg-orange-50 p-4 text-sm text-slate-700">
                    <li class="flex gap-2"><i class="fas fa-check mt-0.5 text-[#c2450c]"></i> Uso pessoal, só para você.</li>
                    <li class="flex gap-2"><i class="fas fa-check mt-0.5 text-[#c2450c]"></i> Não compartilhar o arquivo nem este link.</li>
                    <li class="flex gap-2"><i class="fas fa-check mt-0.5 text-[#c2450c]"></i> Não vender, copiar ou publicar o conteúdo.</li>
                    <li class="flex gap-2"><i class="fas fa-check mt-0.5 text-[#c2450c]"></i> O link é exclusivo do seu pedido e cada download fica registrado.</li>
                </ul>

                <button type="submit" class="mt-6 w-full rounded-xl bg-[#0FB930] px-5 py-3 text-sm font-bold text-white shadow hover:brightness-95">
                    <i class="fas fa-download mr-1"></i> Concordar e baixar
                </button>
                <button type="button" class="mt-2 w-full rounded-xl px-5 py-2 text-sm font-semibold text-slate-500 hover:text-slate-700" @click="aberto = false">
                    Agora não
                </button>
            </form>
        </div>
    </AppLayout>
</template>
