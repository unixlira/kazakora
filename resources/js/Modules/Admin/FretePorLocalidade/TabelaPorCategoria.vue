<script setup>
// Cada categoria com PAC e SEDEX lado a lado (pedido do Lira em 2026-10-25).
// Usada na consulta de Frete por localidade e na página da cidade em Fretes
// por estado.
defineProps({
    categorias: { type: Array, default: () => [] },
    localidades: { type: Array, default: () => [] },
    achadas: { type: Array, default: () => [] },
});

const dinheiro = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v));
const dias = (v) => (v === null || v === undefined ? '' : `${v} ${v === 1 ? 'dia útil' : 'dias úteis'}`);
const cep = (v) => (v ? `${v.slice(0, 5)}-${v.slice(5)}` : '');
const TIPOS = { M: 'Município', D: 'Distrito', P: 'Povoado' };
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <th scope="col" rowspan="2" class="sticky left-0 z-10 bg-[var(--surface)] px-4 py-2 align-bottom font-semibold">Localidade</th>
                    <th scope="col" rowspan="2" class="px-2 py-2 align-bottom font-semibold">CEP</th>
                    <th
                        v-for="c in categorias"
                        :key="c.chave"
                        scope="colgroup"
                        colspan="2"
                        class="border-l border-[var(--surface-border)] px-3 pt-2 pb-1 text-center font-semibold text-slate-700 dark:text-slate-200"
                    >
                        {{ c.nome }}
                    </th>
                </tr>
                <tr class="text-left text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    <template v-for="c in categorias" :key="c.chave">
                        <th scope="col" class="border-l border-[var(--surface-border)] px-3 pb-2 font-semibold">PAC</th>
                        <th scope="col" class="px-3 pb-2 font-semibold">SEDEX</th>
                    </template>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="l in localidades"
                    :key="l.id"
                    class="border-t border-[var(--surface-border)] align-top"
                    :class="achadas.includes(l.id) ? 'bg-blue-50/60 dark:bg-blue-950/40' : ''"
                >
                    <td class="sticky left-0 z-10 px-4 py-2" :class="achadas.includes(l.id) ? 'bg-blue-50 dark:bg-blue-950' : 'bg-[var(--surface)]'">
                        <span class="font-medium" :class="l.tipo === 'M' ? '' : 'pl-3'">{{ l.nome }}</span>
                        <span class="text-slate-500 dark:text-slate-400"> · {{ l.uf }}</span>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ TIPOS[l.tipo] }}<template v-if="l.tipo !== 'M' && l.municipio"> de {{ l.municipio }}</template></p>
                    </td>
                    <td class="px-2 py-2 tabular-nums">
                        {{ cep(l.cep_cotacao) || '—' }}
                        <p v-if="l.cep_herdado" class="text-xs text-slate-500 dark:text-slate-400">do município</p>
                    </td>
                    <template v-for="c in categorias" :key="c.chave">
                        <template v-if="l.regras[c.chave] && (l.regras[c.chave].pac !== null || l.regras[c.chave].sedex !== null)">
                            <td v-for="s in ['pac', 'sedex']" :key="s" class="whitespace-nowrap px-3 py-2" :class="s === 'pac' ? 'border-l border-[var(--surface-border)]' : ''">
                                <template v-if="l.regras[c.chave][s] !== null">
                                    <p class="font-medium tabular-nums">{{ dinheiro(l.regras[c.chave][s]) }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ dias(l.regras[c.chave][`${s}_prazo`]) }}</p>
                                </template>
                                <span v-else class="text-xs text-slate-400">sem serviço</span>
                            </td>
                        </template>
                        <td v-else colspan="2" class="border-l border-[var(--surface-border)] px-3 py-2">
                            <p v-if="l.regras[c.chave]" class="max-w-48 text-xs text-amber-700 dark:text-amber-400">{{ l.regras[c.chave].erro || 'Sem serviço' }}</p>
                            <span v-else class="text-xs text-slate-400">ainda não cotado</span>
                        </td>
                    </template>
                </tr>
            </tbody>
        </table>
    </div>
</template>
