<script setup>
/**
 * Filtros do Fluxo de Caixa: período, canal e comparação, numa linha só
 * (no celular a faixa fica presa no topo e rola na horizontal). Datas
 * montadas a partir do "hoje" do servidor (São Paulo), nunca com
 * toISOString(), que virava o dia depois das 21h.
 */
import { somarDias } from '@/Shared/graficos';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    filtro: { type: Object, required: true },
    canais: { type: Array, default: () => [] },
});
const emit = defineEmits(['mudar']);

const hoje = computed(() => props.filtro.hoje);
const inicioMes = (iso) => `${iso.slice(0, 7)}-01`;
const fimMesAnterior = (iso) => somarDias(inicioMes(iso), -1);

const PRESETS = computed(() => [
    { chave: 'hoje', rotulo: 'Hoje', de: hoje.value, ate: hoje.value },
    { chave: '7d', rotulo: '7 dias', de: somarDias(hoje.value, -6), ate: hoje.value },
    { chave: 'mes', rotulo: 'Este mês', de: inicioMes(hoje.value), ate: hoje.value },
    { chave: 'mes-passado', rotulo: 'Mês passado', de: inicioMes(fimMesAnterior(hoje.value)), ate: fimMesAnterior(hoje.value) },
    { chave: 'ano', rotulo: 'Ano', de: `${hoje.value.slice(0, 4)}-01-01`, ate: hoje.value },
]);

const de = ref(props.filtro.de);
const ate = ref(props.filtro.ate);
watch(() => [props.filtro.de, props.filtro.ate], ([d, a]) => { de.value = d; ate.value = a; });

const presetAtivo = (p) => props.filtro.de === p.de && props.filtro.ate === p.ate;
const escolher = (p) => emit('mudar', { de: p.de, ate: p.ate, agrupar: null });
const aplicarDatas = () => {
    if (!de.value || !ate.value) return;
    emit('mudar', { de: de.value, ate: ate.value, agrupar: null });
};
</script>

<template>
    <div class="filtros-fluxo mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2.5 shadow-sm"
        role="group" aria-label="Filtros do período">
        <div class="flex shrink-0 gap-1" role="group" aria-label="Período">
            <button v-for="p in PRESETS" :key="p.chave" type="button" class="botao-filtro" :aria-pressed="presetAtivo(p)" @click="escolher(p)">{{ p.rotulo }}</button>
        </div>
        <label for="fluxo-de" class="flex shrink-0 items-center gap-1.5 text-xs text-[var(--texto-suave)]">
            De
            <input id="fluxo-de" v-model="de" type="date" :max="ate || hoje" class="campo-filtro" @change="aplicarDatas">
        </label>
        <label for="fluxo-ate" class="flex shrink-0 items-center gap-1.5 text-xs text-[var(--texto-suave)]">
            Até
            <input id="fluxo-ate" v-model="ate" type="date" :min="de" class="campo-filtro" @change="aplicarDatas">
        </label>
        <label for="fluxo-canal" class="flex shrink-0 items-center gap-1.5 text-xs text-[var(--texto-suave)]">
            Canal
            <select id="fluxo-canal" :value="filtro.canal ?? ''" class="campo-filtro" @change="emit('mudar', { canal: $event.target.value || null })">
                <option value="">Todos os canais</option>
                <option v-for="c in canais" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
        </label>
        <label for="fluxo-comparar" class="flex shrink-0 items-center gap-1.5 text-xs text-[var(--texto-suave)]">
            Comparar com
            <select id="fluxo-comparar" :value="filtro.comparar" class="campo-filtro" @change="emit('mudar', { comparar: $event.target.value })">
                <option value="mes_passado">Mesmo período do mês passado</option>
                <option value="anterior">Período anterior</option>
                <option value="nenhum">Não comparar</option>
            </select>
        </label>
    </div>
</template>

<style scoped>
.botao-filtro {
    border: 1px solid var(--surface-border);
    background: var(--surface);
    color: var(--surface-foreground);
    border-radius: 8px;
    padding: 6px 11px;
    font-size: 13px;
    font-weight: 500;
    touch-action: manipulation;
    white-space: nowrap;
}
.botao-filtro:hover { background: var(--surface-muted); }
.botao-filtro[aria-pressed="true"] {
    background: var(--surface-foreground);
    border-color: var(--surface-foreground);
    color: var(--surface);
}
.campo-filtro {
    background: var(--surface);
    color: var(--surface-foreground);
    border: 1px solid var(--surface-border);
    border-radius: 8px;
    padding: 5px 8px;
    font-size: 13px;
    color-scheme: light dark;
}
.campo-filtro option { background: var(--surface); color: var(--surface-foreground); }
.botao-filtro:focus-visible, .campo-filtro:focus-visible { outline: 2px solid var(--foco, #9a3412); outline-offset: 2px; }
@media (max-width: 640px) {
    .filtros-fluxo {
        position: sticky;
        top: 0;
        z-index: 5;
        flex-wrap: nowrap;
        overflow-x: auto;
    }
}
</style>
