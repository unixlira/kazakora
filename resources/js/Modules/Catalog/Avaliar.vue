<script setup>
// "Avalie suas compras" (pedido 2026-10-20): produtos já entregues ao cliente,
// cada um com estrelas, comentário e a opção de ocultar o nome, e o Clube de
// Avaliações. Pontos, níveis e cupons vêm do servidor (ClubeDeAvaliacoes): o
// cupom pessoal é gerado lá quando o cliente alcança um nível.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    produtos: { type: Array, required: true },
    clube: { type: Object, required: true },
    nomeCliente: { type: String, default: '' },
});

const PONTOS_NOTA = props.clube.regras.nota;
const PONTOS_COMENTARIO = props.clube.regras.comentario;
const COMENTARIO_MINIMO = props.clube.regras.comentario_minimo;
const VISUAL = {
    bronze: { icone: 'fa-medal', cor: '#b45309' },
    prata: { icone: 'fa-award', cor: '#64748b' },
    ouro: { icone: 'fa-crown', cor: '#ca8a04' },
};
const NIVEIS = computed(() => props.clube.niveis.map((n) => ({ ...n, ...VISUAL[n.chave], premio: `Cupom de ${n.desconto}% na próxima compra` })));

const pontosDe = (avaliacao) => {
    if (!avaliacao?.nota) return 0;
    const comentario = (avaliacao.comentario ?? '').trim();
    return PONTOS_NOTA + (comentario.length >= COMENTARIO_MINIMO ? PONTOS_COMENTARIO : 0);
};

const pontos = computed(() => props.clube.pontos);
const pendentes = computed(() => props.produtos.filter((p) => !p.avaliacao));
const feitos = computed(() => props.produtos.filter((p) => p.avaliacao));
const proximoNivel = computed(() => NIVEIS.value.find((n) => pontos.value < n.pontos) ?? null);
const nivelAtual = computed(() => [...NIVEIS.value].reverse().find((n) => pontos.value >= n.pontos) ?? null);
const topo = computed(() => NIVEIS.value[NIVEIS.value.length - 1].pontos);
const progresso = computed(() => {
    return Math.min(100, Math.round((pontos.value / topo.value) * 100));
});

// Rascunho de cada card: nota, comentário, se está editando e se está enviando.
const rascunhos = reactive({});
const rascunho = (produto) => {
    if (!rascunhos[produto.id]) {
        rascunhos[produto.id] = {
            nota: produto.avaliacao?.nota ?? 0,
            passando: 0,
            comentario: produto.avaliacao?.comentario ?? '',
            ocultarNome: produto.avaliacao?.ocultar_nome ?? false,
            editando: !produto.avaliacao,
            enviando: false,
            erro: null,
        };
    }
    return rascunhos[produto.id];
};

const ROTULOS = ['', 'Muito ruim', 'Ruim', 'Regular', 'Bom', 'Excelente'];
const enviado = ref(null);

const enviar = (produto) => {
    const r = rascunho(produto);
    if (!r.nota) {
        r.erro = 'Escolha de 1 a 5 estrelas.';
        return;
    }
    r.erro = null;
    r.enviando = true;
    router.post(`/produtos/${produto.id}/avaliacoes`, { rating: r.nota, comment: r.comentario || null, ocultar_nome: r.ocultarNome }, {
        preserveScroll: true,
        onSuccess: () => {
            r.editando = false;
            enviado.value = produto.id;
        },
        onError: (erros) => { r.erro = erros.review ?? erros.rating ?? erros.comment ?? 'Não deu para enviar. Tente de novo.'; },
        onFinish: () => { r.enviando = false; },
    });
};

const copiado = ref(null);
const copiar = async (codigo) => {
    try {
        await navigator.clipboard.writeText(codigo);
        copiado.value = codigo;
        setTimeout(() => { if (copiado.value === codigo) copiado.value = null; }, 2000);
    } catch { /* sem permissão de área de transferência: o código está visível */ }
};

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'America/Sao_Paulo' }).format(new Date(value))
    : '';
</script>

<template>
    <Head title="Avalie suas compras" />

    <AppLayout>
        <div class="mx-auto max-w-[1000px] px-4 py-10 md:px-6">
            <div class="flex items-center gap-3">
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-[#F27A2A] to-[#e0480f] text-xl text-white shadow-md">
                    <i class="fas fa-star"></i>
                </span>
                <div>
                    <h1 class="font-display text-3xl font-semibold">Avalie suas compras</h1>
                    <p class="text-sm text-store-fg-muted">Conte como foi o produto que chegou. Sua opinião ajuda quem vai comprar depois.</p>
                </div>
            </div>

            <!-- Recompensas: prévia (os pontos ainda não viram cupom). -->
            <section class="relative mt-8 overflow-hidden rounded-3xl border border-store-border bg-store-bg-raised p-5 shadow-sm md:p-6" aria-labelledby="titulo-recompensas">
                <span aria-hidden="true" class="absolute -right-12 -top-16 h-48 w-48 rounded-full bg-[#F27A2A] opacity-20 blur-3xl"></span>
                <div class="relative flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 id="titulo-recompensas" class="text-xl font-semibold">Clube de Avaliações KazaKora</h2>
                        <p class="mt-1 max-w-[520px] text-sm text-store-fg-muted">
                            Cada avaliação vale {{ PONTOS_NOTA }} pontos. Com um comentário de pelo menos {{ COMENTARIO_MINIMO }} letras, vale mais {{ PONTOS_COMENTARIO }}.
                            Ao chegar em cada nível você ganha um cupom só seu, de uso único, válido por {{ clube.regras.validade_dias }} dias.
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-4xl font-black tracking-tight text-[#F27A2A]">{{ pontos }}</p>
                        <p class="text-xs font-semibold uppercase tracking-wide text-store-fg-muted">pontos</p>
                        <p v-if="nivelAtual" class="mt-1 text-sm font-semibold" :style="{ color: nivelAtual.cor }">
                            <i class="fas" :class="nivelAtual.icone"></i> Nível {{ nivelAtual.nome }}
                        </p>
                    </div>
                </div>

                <div class="relative mt-5">
                    <div class="h-2.5 overflow-hidden rounded-full bg-store-bg-sunken" role="progressbar" :aria-valuenow="pontos" aria-valuemin="0" :aria-valuemax="topo" aria-label="Pontos até o nível Ouro">
                        <div class="h-full rounded-full bg-gradient-to-r from-[#F27A2A] to-[#0FB930] transition-all" :style="{ width: `${progresso}%` }"></div>
                    </div>
                    <p class="mt-2 text-xs text-store-fg-muted">
                        <template v-if="proximoNivel">Faltam <strong class="text-store-fg">{{ proximoNivel.pontos - pontos }} pontos</strong> para o nível {{ proximoNivel.nome }}.</template>
                        <template v-else>Você chegou ao nível mais alto.</template>
                    </p>
                </div>

                <ul class="relative mt-5 grid gap-3 sm:grid-cols-3">
                    <li v-for="nivel in NIVEIS" :key="nivel.nome"
                        class="rounded-2xl border p-4 transition"
                        :class="pontos >= nivel.pontos ? 'border-[#0FB930] bg-[#0FB930]/5' : 'border-store-border'">
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-2 font-semibold" :style="{ color: nivel.cor }">
                                <i class="fas" :class="nivel.icone"></i> {{ nivel.nome }}
                            </span>
                            <span class="text-xs font-semibold text-store-fg-muted">{{ nivel.pontos }} pts</span>
                        </div>
                        <p class="mt-1.5 text-sm">{{ nivel.premio }}</p>
                        <template v-if="nivel.cupom">
                            <div class="mt-2 flex items-center gap-2">
                                <code class="rounded-lg border border-dashed border-[#0FB930] bg-white px-2 py-1 text-sm font-bold tracking-wide text-store-fg" :class="{ 'line-through opacity-60': nivel.cupom.usado || nivel.cupom.expirado }">{{ nivel.cupom.codigo }}</code>
                                <button v-if="!nivel.cupom.usado && !nivel.cupom.expirado" type="button" class="text-xs font-semibold text-[#F27A2A] hover:underline" @click="copiar(nivel.cupom.codigo)">
                                    {{ copiado === nivel.cupom.codigo ? 'Copiado!' : 'Copiar' }}
                                </button>
                            </div>
                            <p class="mt-1 text-xs text-store-fg-muted">
                                <template v-if="nivel.cupom.usado">Cupom já usado.</template>
                                <template v-else-if="nivel.cupom.expirado">Cupom vencido em {{ formatDate(nivel.cupom.validade) }}.</template>
                                <template v-else>Use no checkout até {{ formatDate(nivel.cupom.validade) }}.</template>
                            </p>
                        </template>
                        <p v-else-if="pontos >= nivel.pontos" class="mt-2 text-xs font-semibold text-[#0FB930]"><i class="fas fa-check"></i> Alcançado</p>
                    </li>
                </ul>
            </section>

            <div v-if="produtos.length === 0" class="mt-10 rounded-3xl border-2 border-dashed border-store-border p-10 text-center">
                <i class="fas fa-box-open text-5xl text-[#F27A2A] opacity-70"></i>
                <p class="mt-3 font-semibold">Quando um pedido seu for entregue, os produtos aparecem aqui para avaliar.</p>
                <Link href="/pedidos" class="mt-4 inline-flex rounded-full bg-[#0FB930] px-6 py-2.5 text-sm font-bold text-white no-underline hover:brightness-95">Ver meus pedidos</Link>
            </div>

            <template v-else>
                <h2 class="mt-10 text-lg font-semibold">
                    Para avaliar <span class="text-store-fg-muted">({{ pendentes.length }})</span>
                </h2>
                <p v-if="pendentes.length === 0" class="mt-2 text-sm text-store-fg-muted">Tudo avaliado. Obrigado!</p>

                <ul class="mt-4 flex flex-col gap-4">
                    <li v-for="produto in [...pendentes, ...feitos]" :key="produto.id"
                        class="rounded-2xl border border-store-border bg-store-bg-raised p-4 shadow-sm sm:p-5"
                        :class="{ 'ring-2 ring-[#0FB930]': enviado === produto.id }">
                        <div class="flex gap-4">
                            <Link :href="`/produtos/${produto.slug}`" class="h-20 w-20 shrink-0 overflow-hidden rounded-xl bg-white ring-1 ring-store-border">
                                <img v-if="produto.imagem" :src="produto.imagem" :alt="produto.nome" class="h-full w-full object-contain" loading="lazy" width="80" height="80">
                            </Link>
                            <div class="min-w-0 flex-1">
                                <Link :href="`/produtos/${produto.slug}`" class="line-clamp-2 font-semibold text-store-fg no-underline hover:text-[#F27A2A]">{{ produto.nome }}</Link>
                                <p class="mt-0.5 text-xs text-store-fg-muted">Pedido {{ produto.pedido }} · entregue em {{ formatDate(produto.entregue_em) }}</p>

                                <!-- Já avaliado: resumo e botão para editar. -->
                                <div v-if="!rascunho(produto).editando" class="mt-2">
                                    <div class="flex items-center gap-2">
                                        <span class="flex gap-0.5 text-amber-400" role="img" :aria-label="`Nota ${produto.avaliacao.nota} de 5`">
                                            <i v-for="n in 5" :key="n" :class="n <= produto.avaliacao.nota ? 'fas fa-star' : 'far fa-star'" aria-hidden="true"></i>
                                        </span>
                                        <span class="rounded-full bg-[#0FB930]/10 px-2 py-0.5 text-xs font-semibold text-[#0FB930]">+{{ pontosDe(produto.avaliacao) }} pontos</span>
                                    </div>
                                    <p v-if="produto.avaliacao.comentario" class="mt-1.5 text-sm text-store-fg-muted">“{{ produto.avaliacao.comentario }}”</p>
                                    <p v-if="produto.avaliacao.ocultar_nome" class="mt-1 text-xs text-store-fg-muted"><i class="fas fa-user-secret"></i> Publicada sem o seu nome</p>
                                    <button type="button" class="mt-2 text-sm font-semibold text-[#F27A2A] hover:underline" @click="rascunho(produto).editando = true">Editar avaliação</button>
                                </div>

                                <!-- Formulário: estrelas e comentário. -->
                                <form v-else class="mt-3" @submit.prevent="enviar(produto)">
                                    <fieldset>
                                        <legend class="sr-only">Nota para {{ produto.nome }}</legend>
                                        <div class="flex items-center gap-1" @mouseleave="rascunho(produto).passando = 0">
                                            <label v-for="n in 5" :key="n" class="cursor-pointer text-2xl"
                                                :class="n <= (rascunho(produto).passando || rascunho(produto).nota) ? 'text-amber-400' : 'text-store-fg-faint'"
                                                @mouseenter="rascunho(produto).passando = n">
                                                <input v-model="rascunho(produto).nota" type="radio" class="sr-only" :name="`nota-${produto.id}`" :value="n" :aria-label="`${n} estrela${n > 1 ? 's' : ''}: ${ROTULOS[n]}`">
                                                <i :class="n <= (rascunho(produto).passando || rascunho(produto).nota) ? 'fas fa-star' : 'far fa-star'" aria-hidden="true"></i>
                                            </label>
                                            <span class="ml-2 text-sm font-semibold text-store-fg-muted" aria-live="polite">{{ ROTULOS[rascunho(produto).passando || rascunho(produto).nota] }}</span>
                                        </div>
                                    </fieldset>
                                    <label :for="`comentario-${produto.id}`" class="mt-3 block text-sm font-medium">Comentário <span class="font-normal text-store-fg-muted">(opcional, vale +{{ PONTOS_COMENTARIO }} pontos com {{ COMENTARIO_MINIMO }} letras ou mais)</span></label>
                                    <textarea :id="`comentario-${produto.id}`" v-model="rascunho(produto).comentario" rows="3" maxlength="1000"
                                        class="mt-1 w-full rounded-xl border border-store-border bg-store-bg px-3 py-2 text-sm focus:border-[#F27A2A] focus:outline-none focus:ring-2 focus:ring-[#F27A2A]/30"
                                        placeholder="O que achou? Qualidade, tamanho, se chegou como na foto…"></textarea>
                                    <label class="mt-3 flex cursor-pointer items-start gap-2 text-sm">
                                        <input v-model="rascunho(produto).ocultarNome" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-store-border accent-[#F27A2A]">
                                        <span>
                                            Ocultar meu nome na avaliação
                                            <span class="block text-xs text-store-fg-muted">Aparece como: <strong>{{ rascunho(produto).ocultarNome || !nomeCliente ? 'Cliente KazaKora' : nomeCliente }}</strong></span>
                                        </span>
                                    </label>
                                    <p v-if="rascunho(produto).erro" class="mt-1 text-sm text-red-600" role="alert">{{ rascunho(produto).erro }}</p>
                                    <div class="mt-3 flex flex-wrap items-center gap-3">
                                        <button type="submit" :disabled="rascunho(produto).enviando"
                                            class="inline-flex items-center gap-2 rounded-full bg-[#0FB930] px-5 py-2 text-sm font-bold text-white hover:brightness-95 disabled:opacity-60">
                                            <i class="fas" :class="rascunho(produto).enviando ? 'fa-spinner fa-spin' : 'fa-paper-plane'"></i>
                                            {{ rascunho(produto).enviando ? 'Enviando…' : 'Enviar avaliação' }}
                                        </button>
                                        <button v-if="produto.avaliacao" type="button" class="text-sm text-store-fg-muted hover:underline" @click="rascunho(produto).editando = false">Cancelar</button>
                                        <span class="text-xs text-store-fg-muted">Vale {{ PONTOS_NOTA + ((rascunho(produto).comentario || '').trim().length >= COMENTARIO_MINIMO ? PONTOS_COMENTARIO : 0) }} pontos</span>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </li>
                </ul>
            </template>
        </div>
    </AppLayout>
</template>
