<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import MercadoLivreBloco from '@/Modules/Admin/Radar/MercadoLivreBloco.vue';
import ShopeeRadarBloco from './ShopeeRadarBloco.vue';
import { RADAR_NOME } from '@/Shared/radarNome';
import { Deferred, Head, router } from '@inertiajs/vue3';
import { computed, nextTick, onUnmounted, reactive, ref } from 'vue';

// Oportunidades (correção do Lira 2026-10-12): produtos de TERCEIROS que
// estão vendendo muito ou em alta no marketplace, para achar produto novo.
// Não são as vendas da KazaKora. Ver OportunidadesController.
// `mercado` é prop adiada (2026-10-12): a tela abre na hora com o esqueleto
// de carregamento e os dados chegam logo depois (fica null até lá).
// Tela dividida no Mercado Livre (pedido do Lira 2026-10-12): em cima os
// mais vendidos, embaixo o bloco do Radar (`mercadoLivre`, adiado à parte).
// Na Shopee (Lira 2026-10-12), o Radar da Shopee (`radarShopee`, ranking da
// Central do Vendedor guardado no banco) vem em cima de tudo.
const props = defineProps({
    canal: { type: Object, required: true },
    base: { type: String, default: null },
    filtros: { type: Object, required: true },
    categorias: { type: Array, default: () => [] },
    mercado: { type: Object, default: null },
    mercadoLivre: { type: Object, default: null },
    radarShopee: { type: Object, default: null },
});

const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
const formatNumber = (value) => (value ?? 0).toLocaleString('pt-BR');
const formatDateTime = (iso) => new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));

const ehMl = computed(() => props.canal.slug === 'mercado-livre');
const titulo = computed(() => `Oportunidades · ${props.canal.nome}`);
const categoria = ref(props.filtros.categoria ?? '');
const termo = ref(props.filtros.termo ?? '');
const carregando = ref(false);
// Ocupado também enquanto os dados adiados não chegaram.
const ocupado = computed(() => carregando.value || !props.mercado);

const buscar = (extra = {}) => {
    const filtros = ehMl.value ? { categoria: categoria.value } : { termo: termo.value };
    router.get(props.base ?? `/admin/oportunidades/${props.canal.slug}`, { ...filtros, ...extra }, {
        preserveScroll: true,
        onStart: () => { carregando.value = true; },
        onFinish: () => { carregando.value = false; },
    });
};

const nomeCategoria = computed(() => props.categorias.find((c) => c.id === props.filtros.categoria)?.nome ?? props.filtros.categoria);
const faixaPreco = (p) => {
    if (p.preco_min === null) return '—';
    if (p.preco_max !== null && p.preco_max > p.preco_min) return `${formatPrice(p.preco_min)} a ${formatPrice(p.preco_max)}`;
    return formatPrice(p.preco_min);
};

// Inteligência (pedido do Lira 2026-10-13): passa os mais vendidos da
// categoria pelo filtro "demanda, concorrência e margem" e mostra num modal.
// Mesmo padrão do modal do olho em Visitas do site (sem Teleport: as cores
// do admin só valem dentro do .admin-shell).
const intel = reactive({ aberto: false, carregando: false, erro: '', dados: null });
const botaoIntel = ref(null);
const caixaIntel = ref(null);
const fecharIntel = ref(null);
const SECOES_INTEL = [
    { chave: 'oportunidade', titulo: 'Oportunidades', icone: 'fa-lightbulb', descricao: 'Até 5 vendedores, menor preço entre R$ 20 e R$ 200 e sem marca forte.' },
    { chave: 'ja_vendemos', titulo: 'Já vendemos', icone: 'fa-store', descricao: 'Parecidos com produtos ativos da KazaKora: nosso custo contra o preço do ML.' },
    { chave: 'evitar', titulo: 'Evitar (guerra de preço)', icone: 'fa-triangle-exclamation', descricao: '20 vendedores ou mais, ou marca forte segurando o preço.' },
];

const onKeydownIntel = (event) => {
    if (!intel.aberto) return;
    if (event.key === 'Escape') {
        fecharModalIntel();
        return;
    }
    // Foco preso no modal enquanto ele está aberto.
    if (event.key === 'Tab' && caixaIntel.value) {
        const focaveis = [...caixaIntel.value.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')];
        if (!focaveis.length) return;
        const [primeiro, ultimo] = [focaveis[0], focaveis[focaveis.length - 1]];
        if (event.shiftKey && document.activeElement === primeiro) {
            event.preventDefault();
            ultimo.focus();
        } else if (!event.shiftKey && document.activeElement === ultimo) {
            event.preventDefault();
            primeiro.focus();
        }
    }
};

const fecharModalIntel = () => {
    intel.aberto = false;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKeydownIntel);
    botaoIntel.value?.focus();
};

const abrirIntel = async () => {
    Object.assign(intel, { carregando: true, erro: '', dados: null });
    try {
        const query = new URLSearchParams({ categoria: props.filtros.categoria ?? '' });
        const r = await fetch(`/admin/oportunidades/mercado-livre/inteligencia?${query}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        const dados = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(dados.message || 'Não foi possível processar a categoria agora.');
        intel.dados = dados;
    } catch (e) {
        intel.erro = e.message || 'Não foi possível processar a categoria agora.';
    } finally {
        intel.carregando = false;
    }
    intel.aberto = true;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeydownIntel);
    await nextTick();
    fecharIntel.value?.focus();
};

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydownIntel);
    document.body.style.overflow = '';
});
</script>

<template>
    <Head :title="titulo" />

    <AdminLayout>
        <div class="mb-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--texto-suave)]">Oportunidades</p>
            <h1 class="text-2xl font-bold">Mais vendidos e em alta · {{ canal.nome }}</h1>
            <p class="mt-1 text-sm text-[var(--texto-suave)]">
                Produtos de outros vendedores com muita venda no {{ canal.nome }}. Servem para achar produto novo: não são as vendas da KazaKora.
            </p>
        </div>

        <ShopeeRadarBloco v-if="!ehMl" :radar="radarShopee" />

        <h2 v-if="ehMl" class="mb-3 text-lg font-bold">Oportunidades mais vendidos</h2>
        <h2 v-else class="mb-3 text-lg font-bold">Mais vendidos pela API de Afiliados</h2>

        <!-- Filtro -->
        <form class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" @submit.prevent="buscar()">
            <label v-if="ehMl" class="text-xs text-[var(--texto-suave)]">Categoria
                <select v-model="categoria" name="categoria" class="mt-1 block min-w-64 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" @change="buscar()">
                    <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nome }}</option>
                    <option v-if="!categorias.some((c) => c.id === categoria)" :value="categoria">{{ categoria }}</option>
                </select>
            </label>
            <label v-else class="text-xs text-[var(--texto-suave)]">Palavra-chave (vazio = mais vendidos em geral)
                <input v-model="termo" type="search" name="termo" maxlength="80" autocomplete="off" placeholder="ex.: organizador, luminária…"
                    class="mt-1 block w-72 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 text-sm">
            </label>
            <button v-if="!ehMl" type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50" :disabled="ocupado || mercado?.configurado === false">
                {{ ocupado ? 'Buscando…' : 'Buscar' }}
            </button>
            <div class="ml-auto flex items-center gap-3 text-xs text-[var(--texto-suave)]">
                <span v-if="mercado?.atualizado_em">Consultado em {{ formatDateTime(mercado.atualizado_em) }}</span>
                <button v-if="ehMl" ref="botaoIntel" type="button" class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50"
                    :disabled="ocupado || intel.carregando" :aria-busy="intel.carregando" title="Filtra os mais vendidos por demanda, concorrência e margem"
                    @click="abrirIntel">
                    <i class="fas" :class="intel.carregando ? 'fa-circle-notch animate-spin' : 'fa-lightbulb'" aria-hidden="true"></i>
                    {{ intel.carregando ? 'Processando…' : 'Inteligência' }}
                </button>
                <button v-if="mercado?.configurado !== false" type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm hover:bg-[var(--surface-muted)] disabled:opacity-50" :disabled="ocupado" @click="buscar({ atualizar: 1 })">
                    {{ ocupado ? 'Consultando…' : 'Consultar de novo' }}
                </button>
            </div>
        </form>

        <Deferred data="mercado">
        <template #fallback>
            <!-- Esqueleto enquanto os dados de mercado chegam (2026-10-12).
            Mesmo desenho da tabela; a coleta do ML pode levar alguns segundos. -->
            <div class="grid grid-cols-1 gap-6" :class="ehMl ? 'xl:grid-cols-[1fr_300px]' : ''" role="status" aria-live="polite">
                <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                    <div class="flex items-center gap-2 border-b border-[var(--surface-border)] px-4 py-3">
                        <i class="fas fa-circle-notch animate-spin text-primary" aria-hidden="true"></i>
                        <h2 class="text-base font-semibold">Consultando o {{ canal.nome }}…</h2>
                    </div>
                    <p class="px-4 pt-3 text-sm text-[var(--texto-suave)]">Buscando os mais vendidos. Na primeira consulta do dia isso pode levar alguns segundos.</p>
                    <ul class="animate-pulse divide-y divide-[var(--surface-border)]" aria-hidden="true">
                        <li v-for="n in 8" :key="n" class="flex items-center gap-3 px-4 py-3">
                            <span class="h-3 w-6 rounded bg-[var(--surface-muted)]"></span>
                            <span class="h-12 w-12 shrink-0 rounded-lg bg-[var(--surface-muted)]"></span>
                            <span class="flex-1 space-y-2">
                                <span class="block h-3 w-3/4 rounded bg-[var(--surface-muted)]"></span>
                                <span class="block h-3 w-1/3 rounded bg-[var(--surface-muted)]"></span>
                            </span>
                            <span class="h-3 w-20 rounded bg-[var(--surface-muted)]"></span>
                        </li>
                    </ul>
                    <span class="sr-only">Carregando…</span>
                </section>
                <section v-if="ehMl" class="h-fit animate-pulse space-y-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-hidden="true">
                    <span class="block h-4 w-32 rounded bg-[var(--surface-muted)]"></span>
                    <span v-for="n in 8" :key="n" class="block h-3 rounded bg-[var(--surface-muted)]" :style="{ width: `${90 - n * 6}%` }"></span>
                </section>
            </div>
        </template>

        <!-- Shopee sem a API de Afiliados -->
        <section v-if="!mercado.configurado" class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] px-6 py-12 text-center">
            <i class="fas fa-plug text-3xl text-[var(--texto-suave)]" aria-hidden="true"></i>
            <p class="text-lg font-bold">Falta conectar a API de Afiliados da Shopee</p>
            <p class="max-w-xl text-sm text-[var(--texto-suave)]">
                A integração de vendedor da Shopee só enxerga a própria loja. As vendas de outras lojas vêm da API de Afiliados
                (affiliate.shopee.com.br › Open API). Com o AppID e a senha de lá no servidor
                (SHOPEE_AFILIADOS_APP_ID e SHOPEE_AFILIADOS_SECRET), esta tela passa a listar os mais vendidos.
            </p>
        </section>

        <template v-else>
            <p v-if="mercado.erro" class="mb-6 rounded-xl border border-[var(--color-error)] bg-[var(--surface)] px-4 py-3 text-sm text-error" role="alert">{{ mercado.erro }}</p>

            <div class="grid grid-cols-1 gap-6" :class="ehMl ? 'xl:grid-cols-[1fr_300px]' : ''">
                <!-- Mais vendidos -->
                <section class="overflow-hidden rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm" style="border-top: 3px solid var(--faixa-card)">
                    <div class="border-b border-[var(--surface-border)] px-4 py-3">
                        <h2 class="text-base font-semibold">
                            Mais vendidos{{ ehMl ? ` · ${nomeCategoria}` : (filtros.termo ? ` · “${filtros.termo}”` : '') }} ({{ mercado.produtos.length }})
                        </h2>
                    </div>

                    <p v-if="mercado.produtos.length === 0 && !mercado.erro" class="px-4 py-8 text-center text-sm text-[var(--texto-suave)]">
                        Nenhum produto encontrado.
                    </p>

                    <div v-else-if="mercado.produtos.length" class="overflow-x-auto">
                        <table class="w-full min-w-[720px] text-left text-sm">
                            <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-[var(--texto-suave)]">
                                <tr>
                                    <th scope="col" class="px-4 py-2">#</th>
                                    <th scope="col" class="py-2 pr-3">Produto</th>
                                    <th scope="col" class="py-2 pr-3 text-right">Preço</th>
                                    <th v-if="ehMl" scope="col" class="py-2 pr-4 text-right" title="Quantos vendedores anunciam este produto no catálogo">Vendedores</th>
                                    <template v-else>
                                        <th scope="col" class="py-2 pr-3 text-right" title="Vendas informadas pela Shopee">Vendas</th>
                                        <th scope="col" class="py-2 pr-4 text-right">Nota</th>
                                    </template>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in mercado.produtos" :key="p.id" class="border-b border-[var(--surface-border)] last:border-0 hover:bg-[var(--surface-muted)]">
                                    <td class="px-4 py-2 tabular-nums text-[var(--texto-suave)]">{{ p.posicao }}º</td>
                                    <td class="py-2 pr-3">
                                        <div class="flex items-center gap-3">
                                            <img v-if="p.imagem" :src="p.imagem" alt="" width="48" height="48" loading="lazy" class="h-12 w-12 shrink-0 rounded-lg border border-[var(--surface-border)] bg-white object-contain">
                                            <div class="min-w-0">
                                                <a :href="p.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 hover:text-[var(--destaque)] hover:underline">{{ p.nome }}</a>
                                                <span v-if="p.loja" class="block truncate text-xs text-[var(--texto-suave)]">{{ p.loja }}</span>
                                                <span v-if="p.voce_vende" class="mt-0.5 inline-block rounded-full bg-[var(--surface-muted)] px-2 py-0.5 text-xs">A KazaKora já vende</span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums">{{ faixaPreco(p) }}</td>
                                    <td v-if="ehMl" class="py-2 pr-4 text-right tabular-nums">{{ p.vendedores === null ? '—' : formatNumber(p.vendedores) }}</td>
                                    <template v-else>
                                        <td class="py-2 pr-3 text-right font-semibold tabular-nums">{{ p.vendas === null ? '—' : formatNumber(p.vendas) }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums">{{ p.nota ? p.nota.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) : '—' }}</td>
                                    </template>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="border-t border-[var(--surface-border)] px-4 py-3 text-xs text-[var(--texto-suave)]">
                        <template v-if="ehMl">
                            Ranking "Mais vendidos" do próprio Mercado Livre na categoria. Preço = menor e maior entre os vendedores do catálogo.
                            <template v-if="mercado.ocultos"> {{ mercado.ocultos }} anúncio(s) do ranking não são de catálogo e o ML não libera os dados deles.</template>
                        </template>
                        <template v-else>Ordenado pelas vendas informadas pela API de Afiliados da Shopee.</template>
                    </p>
                </section>

                <!-- Buscas em alta (só ML) -->
                <section v-if="ehMl" class="h-fit rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-label="Buscas em alta">
                    <h2 class="text-base font-semibold">Buscas em alta</h2>
                    <p class="mb-3 text-xs text-[var(--texto-suave)]">O que mais cresce nas buscas desta categoria no Mercado Livre.</p>
                    <p v-if="!mercado.tendencias.length" class="text-sm text-[var(--texto-suave)]">Sem tendências para esta categoria.</p>
                    <ol v-else class="space-y-1 text-sm">
                        <li v-for="(t, i) in mercado.tendencias" :key="t.termo" class="flex gap-2">
                            <span class="w-5 shrink-0 text-right tabular-nums text-[var(--texto-suave)]">{{ i + 1 }}.</span>
                            <a :href="t.link" target="_blank" rel="noopener noreferrer" class="hover:text-[var(--destaque)] hover:underline">{{ t.termo }}</a>
                        </li>
                    </ol>
                </section>
            </div>
        </template>
        </Deferred>

        <!-- Embaixo, a tabela do Radar (pedido do Lira 2026-10-12) -->
        <section v-if="ehMl" class="mt-8" aria-labelledby="radar-titulo">
            <h2 id="radar-titulo" class="mb-3 text-lg font-bold">{{ RADAR_NOME }}</h2>
            <MercadoLivreBloco :mercado-livre="mercadoLivre" :link-outras-categorias="false" />
        </section>

        <!-- Modal da Inteligência (Lira 2026-10-13) -->
        <div v-if="intel.aberto" class="fixed inset-0 z-[100] flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" aria-hidden="true" @click="fecharModalIntel"></div>
            <div ref="caixaIntel" role="dialog" aria-modal="true" aria-labelledby="intel-titulo" aria-describedby="intel-resumo"
                class="relative flex w-full max-w-6xl flex-col rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-xl"
                style="max-height: 90vh;">
                <div class="flex items-start justify-between gap-3 border-b border-[var(--surface-border)] px-6 py-4">
                    <div class="min-w-0">
                        <h2 id="intel-titulo" class="flex items-center gap-2 text-lg font-semibold">
                            <i class="fas fa-lightbulb text-primary" aria-hidden="true"></i>
                            Inteligência · {{ nomeCategoria }}
                        </h2>
                        <p id="intel-resumo" class="text-sm text-[var(--texto-suave)]">
                            <template v-if="intel.dados">
                                {{ intel.dados.resumo.texto }} De {{ intel.dados.resumo.total }} produtos analisados<template v-if="intel.dados.atualizado_em">, consulta de {{ formatDateTime(intel.dados.atualizado_em) }}</template>.
                            </template>
                            <template v-else>Filtro de demanda, concorrência e margem.</template>
                        </p>
                    </div>
                    <button ref="fecharIntel" type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-[var(--texto-suave)] hover:bg-[var(--surface-muted)]"
                        aria-label="Fechar" @click="fecharModalIntel">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="overflow-auto px-6 py-4">
                    <p v-if="intel.erro" class="rounded-xl border border-[var(--color-error)] px-4 py-3 text-sm text-error" role="alert">{{ intel.erro }}</p>
                    <template v-else-if="intel.dados">
                        <p v-if="intel.dados.erro" class="mb-4 rounded-xl border border-[var(--color-error)] px-4 py-3 text-sm text-error" role="alert">{{ intel.dados.erro }}</p>

                        <!-- Contagens -->
                        <dl class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div v-for="c in [...SECOES_INTEL, { chave: 'outros', titulo: 'Outros' }]" :key="c.chave" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)]/40 px-4 py-3">
                                <dt class="text-xs text-[var(--texto-suave)]">{{ c.titulo }}</dt>
                                <dd class="text-2xl font-bold tabular-nums">{{ intel.dados.resumo[c.chave] }}</dd>
                            </div>
                        </dl>

                        <section v-for="sec in SECOES_INTEL" :key="sec.chave" class="mb-6" :aria-labelledby="`intel-${sec.chave}`">
                            <h3 :id="`intel-${sec.chave}`" class="flex items-center gap-2 text-base font-semibold">
                                <i class="fas" :class="sec.icone" aria-hidden="true"></i>
                                {{ sec.titulo }} ({{ intel.dados.grupos[sec.chave].length }})
                            </h3>
                            <p class="mb-2 text-xs text-[var(--texto-suave)]">{{ sec.descricao }}</p>
                            <p v-if="!intel.dados.grupos[sec.chave].length" class="rounded-lg border border-dashed border-[var(--surface-border)] px-4 py-3 text-sm text-[var(--texto-suave)]">Nenhum produto nesta categoria.</p>
                            <ul v-else class="divide-y divide-[var(--surface-border)] rounded-xl border border-[var(--surface-border)]">
                                <li v-for="p in intel.dados.grupos[sec.chave]" :key="p.id" class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start">
                                    <div class="flex min-w-0 flex-1 items-start gap-3">
                                        <span class="w-8 shrink-0 pt-1 text-right text-sm tabular-nums text-[var(--texto-suave)]">{{ p.posicao }}º</span>
                                        <img v-if="p.imagem" :src="p.imagem" alt="" width="56" height="56" loading="lazy" class="h-14 w-14 shrink-0 rounded-lg border border-[var(--surface-border)] bg-white object-contain">
                                        <span v-else class="h-14 w-14 shrink-0 rounded-lg bg-[var(--surface-muted)]" aria-hidden="true"></span>
                                        <div class="min-w-0 text-sm">
                                            <a :href="p.link" target="_blank" rel="noopener noreferrer" class="line-clamp-2 font-medium hover:text-[var(--destaque)] hover:underline">{{ p.nome }}</a>
                                            <p class="mt-0.5 text-xs text-[var(--texto-suave)]">
                                                {{ p.vendedores === null ? 'vendedores ?' : `${formatNumber(p.vendedores)} ${p.vendedores === 1 ? 'vendedor' : 'vendedores'}` }}
                                                · {{ faixaPreco(p) }}
                                                <template v-if="p.custo_max_15 !== null"> · custo máx. p/ 15%: <strong class="font-semibold">{{ formatPrice(p.custo_max_15) }}</strong></template>
                                            </p>
                                            <p v-if="p.motivo_guerra" class="mt-1 text-xs"><i class="fas fa-triangle-exclamation mr-1 text-amber-500" aria-hidden="true"></i>Guerra de preço: {{ p.motivo_guerra }}</p>
                                            <div v-if="p.nosso" class="mt-1.5 rounded-lg bg-[var(--surface-muted)]/60 px-2.5 py-1.5 text-xs">
                                                <span class="font-medium">Nosso #{{ p.nosso.id }}</span> {{ p.nosso.nome }}
                                                <span v-if="!p.nosso.confirmado" class="ml-1 rounded-full border border-[var(--surface-border)] px-1.5 text-[10px] uppercase tracking-wide text-[var(--texto-suave)]" title="Achado pelo nome, não confirmado">parecido</span>
                                                <span class="block text-[var(--texto-suave)]">
                                                    preço {{ formatPrice(p.nosso.preco) }} · custo {{ p.nosso.custo === null ? 'sem cadastro' : formatPrice(p.nosso.custo) }}<template v-if="p.nosso.piso_15 !== null"> · piso 15%: {{ formatPrice(p.nosso.piso_15) }}</template>
                                                </span>
                                            </div>
                                            <p v-if="p.veredito" class="mt-1 text-xs font-medium">{{ p.veredito }}</p>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 gap-2 sm:flex-col">
                                        <a :href="p.videos.tiktok" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-2.5 py-1 text-xs hover:bg-[var(--surface-muted)]">
                                            <i class="fab fa-tiktok" aria-hidden="true"></i> Vídeos TikTok
                                        </a>
                                        <a :href="p.videos.youtube" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--surface-border)] px-2.5 py-1 text-xs hover:bg-[var(--surface-muted)]">
                                            <i class="fab fa-youtube" aria-hidden="true"></i> Vídeos YouTube
                                        </a>
                                    </div>
                                </li>
                            </ul>
                        </section>
                    </template>
                </div>

                <p class="border-t border-[var(--surface-border)] px-6 py-3 text-xs text-[var(--texto-suave)]">
                    Limitações: a API do Mercado Livre não informa unidades vendidas, então a posição no ranking é o sinal de demanda.
                    "Vendedores" conta só quem anuncia no catálogo do produto. O fator 0,56 (custo ≈ 56% do preço para sobrar ~15%) é uma aproximação:
                    a decisão final passa pela margem de contribuição (ContributionMargin). Produto "parecido" foi achado pelo nome e precisa ser conferido.
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
