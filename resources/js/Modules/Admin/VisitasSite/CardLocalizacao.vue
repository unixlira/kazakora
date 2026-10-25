<script setup>
// Card do olho das Visitas (Kazakora e outros sites): latitude, longitude e
// CEP aproximados que a Cloudflare tirou do IP em cada visita, para conferir
// o rastreio. Sem captura, mostra a estimativa da base DB-IP do servidor.
import { computed } from 'vue';

const props = defineProps({
    localizacao: { type: Object, default: null },
    total: { type: Number, default: 0 },
});

const capturas = computed(() => props.localizacao?.capturas ?? []);
const atual = computed(() => capturas.value[0] ?? null);
const outras = computed(() => capturas.value.slice(1));
const base = computed(() => props.localizacao?.base ?? null);

const numero = (valor) => Number(valor ?? 0).toLocaleString('pt-BR');
const coordenada = (valor) => Number(valor).toFixed(4);
const mapa = (l) => `https://www.google.com/maps?q=${l.latitude},${l.longitude}`;

// A Cloudflare às vezes manda só o começo do CEP (5 números): é a faixa.
const cep = (valor) => {
    if (!valor) return null;
    if (valor.length === 8) return `${valor.slice(0, 5)}-${valor.slice(5)}`;
    if (valor.length === 5) return `${valor}-xxx`;
    return valor;
};
const tituloCep = (valor) => (valor?.length === 5 ? 'Só o começo do CEP (faixa da região)' : 'CEP aproximado do IP');
const lugar = (b) => [b.cidade, b.uf ?? b.pais].filter(Boolean).join(' / ');
</script>

<template>
    <section class="mx-4 mt-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)]/40 p-4" aria-labelledby="visitante-localizacao">
        <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <h3 id="visitante-localizacao" class="text-sm font-semibold">
                <i class="fas fa-location-dot mr-1 text-slate-400" aria-hidden="true"></i> Localização pelo IP
            </h3>
            <span v-if="atual" class="text-xs tabular-nums text-slate-500 dark:text-slate-400">
                {{ numero(props.localizacao.com_localizacao) }} de {{ numero(props.total) }} {{ props.total === 1 ? 'página' : 'páginas' }} com localização
            </span>
        </div>

        <template v-if="atual">
            <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Latitude</dt>
                    <dd class="font-mono text-sm font-semibold tabular-nums" :title="String(atual.latitude)">{{ coordenada(atual.latitude) }}</dd>
                </div>
                <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Longitude</dt>
                    <dd class="font-mono text-sm font-semibold tabular-nums" :title="String(atual.longitude)">{{ coordenada(atual.longitude) }}</dd>
                </div>
                <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">CEP aproximado</dt>
                    <dd v-if="atual.cep" class="font-mono text-sm font-semibold tabular-nums" :title="tituloCep(atual.cep)">{{ cep(atual.cep) }}</dd>
                    <dd v-else class="text-sm italic text-slate-400">não informado</dd>
                </div>
            </dl>
            <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                <span>Capturado pela Cloudflare, última visita em <span class="tabular-nums">{{ atual.ultima }}</span> ({{ numero(atual.visitas) }} {{ atual.visitas === 1 ? 'visita' : 'visitas' }})</span>
                <a :href="mapa(atual)" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">
                    <i class="fas fa-map mr-0.5" aria-hidden="true"></i> Ver no mapa
                </a>
            </p>

            <div v-if="outras.length" class="mt-3 border-t border-[var(--surface-border)] pt-2">
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">Outras localizações no período</p>
                <ul class="mt-1 space-y-1 text-xs">
                    <li v-for="l in outras" :key="`${l.latitude},${l.longitude},${l.cep}`" class="flex flex-wrap items-center gap-x-3">
                        <span class="font-mono tabular-nums">{{ coordenada(l.latitude) }}, {{ coordenada(l.longitude) }}</span>
                        <span class="font-mono tabular-nums" :title="tituloCep(l.cep)">CEP {{ cep(l.cep) ?? 'não informado' }}</span>
                        <span class="tabular-nums text-slate-500 dark:text-slate-400">{{ numero(l.visitas) }} {{ l.visitas === 1 ? 'visita' : 'visitas' }}, última em {{ l.ultima }}</span>
                        <a :href="mapa(l)" target="_blank" rel="noopener noreferrer" class="text-primary hover:underline">mapa</a>
                    </li>
                </ul>
            </div>
        </template>

        <template v-else>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Nenhuma localização gravada nessas visitas. A captura começou em 24/10/2026 e vem da Cloudflare do kazakora.com;
                visitas anteriores ou que não passaram pela Cloudflare ficam sem.
            </p>
            <div v-if="base" class="mt-2 rounded-lg border border-dashed border-[var(--surface-border)] px-3 py-2 text-xs">
                <span class="text-slate-500 dark:text-slate-400">Estimativa da base do servidor (sem CEP): </span>
                <span class="font-mono tabular-nums">{{ coordenada(base.latitude) }}, {{ coordenada(base.longitude) }}</span>
                <span v-if="lugar(base)" class="text-slate-500 dark:text-slate-400">, {{ lugar(base) }}</span>
                <a :href="mapa(base)" target="_blank" rel="noopener noreferrer" class="ml-2 text-primary hover:underline">mapa</a>
            </div>
        </template>

        <p class="mt-2 text-[11px] text-slate-400">
            É a localização do IP, não do endereço: celular costuma cair na cidade da operadora.
        </p>
    </section>
</template>
