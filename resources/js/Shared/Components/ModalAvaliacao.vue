<script setup>
// Modal "Avalie sua compra" depois do login (pedido 2026-10-23). O servidor
// decide se e qual item mostrar (ModalAvaliacao.php, prop `avaliacaoModal`):
// um por vez, o mais recente primeiro. A avaliação vai para o mesmo endpoint
// da tela "Avalie suas compras". "Não avaliar agora" (e também X, Esc, clique
// fora) avisa o servidor, que registra e só mostra o produto de novo depois de
// 15 dias, no próximo login (pedido do Lira 2026-10-29).
import { router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const MAX_FOTOS = 4;
const MAX_FOTO_MB = 5;
const MAX_COMENTARIO = 1000;
const TIPOS = ['image/jpeg', 'image/png', 'image/webp'];
const ROTULOS = ['', 'Muito ruim', 'Ruim', 'Regular', 'Bom', 'Excelente'];

const page = usePage();
const item = computed(() => page.props.avaliacaoModal ?? null);

const aberto = ref(false);
const nota = ref(0);
const passando = ref(0);
const comentario = ref('');
const ocultarNome = ref(false);
const fotos = ref([]); // { id, file, url }
const erro = ref('');
const enviando = ref(false);
const dialogo = ref(null);
const botaoFechar = ref(null);
const entradaGaleria = ref(null);
const entradaCamera = ref(null);
let foiAberto = null; // elemento que tinha o foco antes do modal
let contador = 0;

const notaVisivel = computed(() => passando.value || nota.value);

const limparFotos = () => {
    fotos.value.forEach((f) => URL.revokeObjectURL(f.url));
    fotos.value = [];
};

const reiniciar = () => {
    nota.value = 0;
    passando.value = 0;
    comentario.value = '';
    ocultarNome.value = false;
    erro.value = '';
    enviando.value = false;
    limparFotos();
};

const travarRolagem = (travar) => {
    document.documentElement.style.overflow = travar ? 'hidden' : '';
};

// Abre quando o servidor manda um item; troca de item (depois de enviar) limpa o formulário.
watch(
    () => item.value?.produto_id,
    async (id, anterior) => {
        if (!id) {
            if (aberto.value) {
                aberto.value = false;
                travarRolagem(false);
                foiAberto?.focus?.();
            }
            return;
        }
        if (id !== anterior) reiniciar();
        if (!aberto.value) {
            foiAberto = document.activeElement;
            aberto.value = true;
            travarRolagem(true);
        }
        await nextTick();
        botaoFechar.value?.focus();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    limparFotos();
    if (aberto.value) travarRolagem(false);
});

const fechar = () => {
    if (!aberto.value || enviando.value) return;
    aberto.value = false;
    travarRolagem(false);
    foiAberto?.focus?.();
    router.post('/avaliar/dispensar', { produto_id: item.value?.produto_id ?? null }, { preserveScroll: true, preserveState: true, only: ['avaliacaoModal'] });
};

const focaveis = () => Array.from(dialogo.value?.querySelectorAll(
    'a[href], button:not([disabled]), textarea, input:not([disabled]):not([type="hidden"]):not(.sr-only-foto), [tabindex]:not([tabindex="-1"])',
) ?? []).filter((el) => el.offsetParent !== null || el === document.activeElement);

const aoTeclar = (evento) => {
    if (evento.key === 'Escape') {
        evento.stopPropagation();
        fechar();
        return;
    }
    if (evento.key !== 'Tab') return;
    // Foco preso no modal.
    const lista = focaveis();
    if (!lista.length) return;
    const primeiro = lista[0];
    const ultimo = lista[lista.length - 1];
    if (evento.shiftKey && document.activeElement === primeiro) {
        evento.preventDefault();
        ultimo.focus();
    } else if (!evento.shiftKey && document.activeElement === ultimo) {
        evento.preventDefault();
        primeiro.focus();
    }
};

const adicionarFotos = (evento) => {
    const escolhidas = Array.from(evento.target.files ?? []);
    evento.target.value = '';
    erro.value = '';
    for (const file of escolhidas) {
        if (fotos.value.length >= MAX_FOTOS) {
            erro.value = `No máximo ${MAX_FOTOS} fotos.`;
            break;
        }
        if (!TIPOS.includes(file.type)) {
            erro.value = 'Use fotos JPG, PNG ou WebP.';
            continue;
        }
        if (file.size > MAX_FOTO_MB * 1024 * 1024) {
            erro.value = `Cada foto pode ter até ${MAX_FOTO_MB} MB.`;
            continue;
        }
        fotos.value.push({ id: ++contador, file, url: URL.createObjectURL(file) });
    }
};

const removerFoto = (id) => {
    const foto = fotos.value.find((f) => f.id === id);
    if (foto) URL.revokeObjectURL(foto.url);
    fotos.value = fotos.value.filter((f) => f.id !== id);
    erro.value = '';
};

const enviar = () => {
    if (!nota.value) {
        erro.value = 'Escolha de 1 a 5 estrelas.';
        return;
    }
    erro.value = '';
    enviando.value = true;
    const dados = { rating: nota.value, comment: comentario.value.trim() || null, ocultar_nome: ocultarNome.value };
    if (fotos.value.length) dados.fotos = fotos.value.map((f) => f.file);

    router.post(`/produtos/${item.value.produto_id}/avaliacoes`, dados, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onError: (erros) => {
            const primeiro = Object.values(erros)[0];
            erro.value = erros.review ?? erros.rating ?? erros.comment ?? erros.fotos ?? primeiro ?? 'Não deu para enviar. Tente de novo.';
        },
        onFinish: () => { enviando.value = false; },
    });
};

const dataEntrega = computed(() => item.value?.entregue_em
    ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'long', timeZone: 'America/Sao_Paulo' }).format(new Date(item.value.entregue_em))
    : '');
</script>

<template>
    <Teleport to="body">
        <div v-if="aberto && item" class="fixed inset-0 z-[120] flex items-end justify-center bg-black/60 sm:items-center sm:p-4" @mousedown.self="fechar">
            <div ref="dialogo" role="dialog" aria-modal="true" aria-labelledby="aval-modal-titulo" aria-describedby="aval-modal-produto"
                class="relative flex max-h-[92dvh] w-full max-w-lg flex-col overflow-hidden rounded-t-3xl bg-white text-slate-900 shadow-2xl sm:rounded-3xl"
                @keydown="aoTeclar">
                <!-- X visível: botão 48px, fundo escuro com ícone branco, canto superior direito. -->
                <button ref="botaoFechar" type="button" aria-label="Fechar"
                    class="absolute right-2 top-2 z-10 flex h-12 w-12 items-center justify-center rounded-full bg-slate-900 text-white shadow-md ring-2 ring-white hover:bg-slate-700 focus:outline-none focus-visible:ring-4 focus-visible:ring-[#F27A2A]"
                    @click="fechar">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" /></svg>
                </button>

                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="enviar">
                    <div class="overflow-y-auto overscroll-contain px-5 pb-4 pt-5">
                        <h2 id="aval-modal-titulo" class="pr-14 text-xl font-bold leading-tight">Como foi sua compra?</h2>

                        <div class="mt-4 flex items-center gap-3">
                            <span class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
                                <img v-if="item.imagem" :src="item.imagem" alt="" class="h-full w-full object-contain" width="80" height="80">
                            </span>
                            <div class="min-w-0">
                                <p id="aval-modal-produto" class="line-clamp-3 font-semibold">{{ item.nome }}</p>
                                <p v-if="dataEntrega" class="mt-0.5 text-xs text-slate-600">Entregue em {{ dataEntrega }}</p>
                            </div>
                        </div>

                        <fieldset class="mt-5">
                            <legend class="text-sm font-semibold">Sua nota <span class="text-red-700" aria-hidden="true">*</span><span class="sr-only">(obrigatória)</span></legend>
                            <div class="mt-1 flex items-center" @mouseleave="passando = 0">
                                <label v-for="n in 5" :key="n"
                                    class="flex h-12 w-11 cursor-pointer items-center justify-center text-3xl focus-within:rounded-lg focus-within:outline focus-within:outline-2 focus-within:outline-[#F27A2A]"
                                    :class="n <= notaVisivel ? 'text-amber-500' : 'text-slate-400'"
                                    @mouseenter="passando = n">
                                    <input v-model="nota" type="radio" class="sr-only" name="aval-modal-nota" :value="n" :aria-label="`${n} estrela${n > 1 ? 's' : ''}: ${ROTULOS[n]}`">
                                    <i :class="n <= notaVisivel ? 'fas fa-star' : 'far fa-star'" aria-hidden="true"></i>
                                </label>
                                <span class="ml-2 text-sm font-semibold text-slate-700" aria-live="polite">{{ ROTULOS[notaVisivel] }}</span>
                            </div>
                        </fieldset>

                        <label for="aval-modal-comentario" class="mt-4 block text-sm font-semibold">Comentário <span class="font-normal text-slate-600">(opcional)</span></label>
                        <textarea id="aval-modal-comentario" v-model="comentario" rows="3" :maxlength="MAX_COMENTARIO"
                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-base focus:border-[#F27A2A] focus:outline-none focus:ring-2 focus:ring-[#F27A2A]/40"
                            placeholder="Coloque aqui a sua avaliação: o que você achou do produto?"></textarea>
                        <p class="text-right text-xs text-slate-600">{{ comentario.length }}/{{ MAX_COMENTARIO }}</p>

                        <div class="mt-2">
                            <p class="text-sm font-semibold">Fotos <span class="font-normal text-slate-600">(opcional, até {{ MAX_FOTOS }})</span></p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" :disabled="fotos.length >= MAX_FOTOS"
                                    class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-400 px-4 text-sm font-semibold hover:bg-slate-100 disabled:opacity-50"
                                    @click="entradaGaleria.click()">
                                    <i class="far fa-image" aria-hidden="true"></i> Escolher fotos
                                </button>
                                <button type="button" :disabled="fotos.length >= MAX_FOTOS"
                                    class="inline-flex min-h-11 items-center gap-2 rounded-full border border-slate-400 px-4 text-sm font-semibold hover:bg-slate-100 disabled:opacity-50"
                                    @click="entradaCamera.click()">
                                    <i class="fas fa-camera" aria-hidden="true"></i> Tirar foto
                                </button>
                            </div>
                            <input ref="entradaGaleria" type="file" class="sr-only-foto hidden" accept="image/jpeg,image/png,image/webp" multiple tabindex="-1" data-testid="aval-fotos" @change="adicionarFotos">
                            <input ref="entradaCamera" type="file" class="sr-only-foto hidden" accept="image/*" capture="environment" tabindex="-1" data-testid="aval-camera" @change="adicionarFotos">

                            <ul v-if="fotos.length" class="mt-3 flex flex-wrap gap-3">
                                <li v-for="(foto, i) in fotos" :key="foto.id" class="relative">
                                    <img :src="foto.url" :alt="`Foto ${i + 1} da avaliação`" class="h-20 w-20 rounded-xl border border-slate-300 object-cover">
                                    <button type="button" :aria-label="`Remover foto ${i + 1}`"
                                        class="absolute -right-2 -top-2 flex h-9 w-9 items-center justify-center rounded-full bg-slate-900 text-white ring-2 ring-white hover:bg-red-700"
                                        @click="removerFoto(foto.id)">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" aria-hidden="true"><path d="M5 5l14 14M19 5L5 19" /></svg>
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <label class="mt-4 flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                            <input v-model="ocultarNome" type="checkbox" class="h-5 w-5 rounded border-slate-400 accent-[#F27A2A]">
                            <span>Ocultar meu nome na avaliação</span>
                        </label>

                        <p v-if="erro" class="mt-2 text-sm font-medium text-red-700" role="alert">{{ erro }}</p>
                    </div>

                    <div class="flex flex-col gap-2 border-t border-slate-200 bg-white px-5 py-3">
                        <button type="submit" :disabled="enviando"
                            class="flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-[#0B8F25] px-5 text-base font-bold text-white hover:brightness-95 disabled:opacity-60">
                            <i class="fas" :class="enviando ? 'fa-spinner fa-spin' : 'fa-paper-plane'" aria-hidden="true"></i>
                            {{ enviando ? 'Enviando…' : 'Enviar avaliação' }}
                        </button>
                        <button type="button" :disabled="enviando" data-testid="aval-nao-avaliar"
                            class="flex min-h-11 w-full items-center justify-center rounded-full px-5 text-sm font-semibold text-slate-700 underline-offset-2 hover:bg-slate-100 hover:underline disabled:opacity-60"
                            @click="fechar">
                            Não avaliar agora
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Teleport>
</template>
