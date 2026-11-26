<script setup>
// Linha do tempo completa do cliente (pedido do Lira 26/11/2026: "quero
// rastreio de histórico total dos clientes"). Tudo em ordem de data e hora:
// cadastro, logins, páginas, carrinho/checkout, pedidos e pagamentos,
// WhatsApp/e-mail, senha, perfil, cupons e cookies. Só admin; só leitura.
// "Desde quando" de cada tipo vem do servidor (fontes): nada do passado é
// inventado.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    site: { type: String, required: true },
    nomeDoSite: { type: String, default: '' },
    cliente: { type: Object, required: true },
    acompanhamento: { type: Object, default: null },
    categorias: { type: Object, default: () => ({}) },
    eventos: { type: Array, default: () => [] },
    fontes: { type: Array, default: () => [] },
    cortes: { type: Array, default: () => [] },
});

const TZ = 'America/Sao_Paulo';
const dia = (iso) => new Date(iso).toLocaleDateString('pt-BR', { timeZone: TZ, weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
const hora = (iso) => new Date(iso).toLocaleTimeString('pt-BR', { timeZone: TZ, hour: '2-digit', minute: '2-digit', second: '2-digit' });
const dataHora = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
const duracao = (seg) => {
    if (seg === null || seg === undefined) return null;
    if (seg < 60) return `${seg} s`;
    const m = Math.floor(seg / 60);
    const s = seg % 60;
    return s ? `${m} min ${s} s` : `${m} min`;
};
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);

const CORES = {
    conta: 'bg-violet-100 text-violet-700',
    acesso: 'bg-sky-100 text-sky-700',
    pagina: 'bg-slate-100 text-slate-600',
    carrinho: 'bg-amber-100 text-amber-700',
    pedido: 'bg-emerald-100 text-emerald-700',
    mensagem: 'bg-green-100 text-green-700',
    senha: 'bg-rose-100 text-rose-700',
    perfil: 'bg-indigo-100 text-indigo-700',
    cupom: 'bg-fuchsia-100 text-fuchsia-700',
    privacidade: 'bg-stone-100 text-stone-700',
};

const contagem = computed(() => props.eventos.reduce((acc, e) => ({ ...acc, [e.categoria]: (acc[e.categoria] ?? 0) + 1 }), {}));
const ativas = ref(new Set(Object.keys(props.categorias)));
const maisRecentesPrimeiro = ref(false);
const alternar = (cat) => {
    const novo = new Set(ativas.value);
    novo.has(cat) ? novo.delete(cat) : novo.add(cat);
    ativas.value = novo;
};
const soEsta = (cat) => { ativas.value = new Set([cat]); };
const todas = () => { ativas.value = new Set(Object.keys(props.categorias)); };

// Agrupado por dia, em ordem cronológica (ou a mais recente primeiro).
const porDia = computed(() => {
    const lista = props.eventos.filter((e) => ativas.value.has(e.categoria));
    const ordenada = maisRecentesPrimeiro.value ? [...lista].reverse() : lista;
    const grupos = [];
    for (const e of ordenada) {
        const d = dia(e.em);
        if (!grupos.length || grupos[grupos.length - 1].dia !== d) grupos.push({ dia: d, itens: [] });
        grupos[grupos.length - 1].itens.push(e);
    }
    return grupos;
});
const visiveis = computed(() => porDia.value.reduce((n, g) => n + g.itens.length, 0));
</script>

<template>
    <Head :title="`Histórico — ${cliente.nome ?? 'Cliente'}`" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold">Linha do tempo: {{ cliente.nome ?? 'Sem nome' }}</h1>
                <p class="text-sm text-slate-500">
                    Conta da {{ nomeDoSite }} · cadastro em {{ dataHora(cliente.cadastro_em) }} · {{ cliente.email }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <Link :href="`/admin/clientes-dos-sites/${site}/${cliente.id}`" class="text-primary hover:underline">
                    <i class="fas fa-id-card mr-1"></i> Cadastro e pedidos
                </Link>
                <Link :href="`/admin/clientes-dos-sites?site=${site}`" class="text-primary hover:underline">
                    <i class="fas fa-arrow-left mr-1"></i> Clientes dos sites
                </Link>
            </div>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <p class="text-xs uppercase text-slate-400">Último acesso</p>
                <p class="font-semibold">{{ acompanhamento?.ultimo_acesso_em ? dataHora(acompanhamento.ultimo_acesso_em) : 'Sem registro' }}</p>
                <p class="text-xs text-slate-500">{{ acompanhamento?.dias_com_acesso ?? 0 }} dia(s) com acesso</p>
            </div>
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <p class="text-xs uppercase text-slate-400">Origem do cadastro</p>
                <p class="font-semibold">{{ acompanhamento?.origem?.texto ?? 'Sem registro' }}</p>
                <p v-if="acompanhamento?.origem?.estimada" class="text-xs text-slate-500">estimada</p>
            </div>
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <p class="text-xs uppercase text-slate-400">Pedidos pagos</p>
                <p class="font-semibold">{{ cliente.pedidos_pagos }} · {{ formatPrice(cliente.total_gasto) }}</p>
            </div>
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <p class="text-xs uppercase text-slate-400">Pagamento</p>
                <p class="font-semibold">
                    {{ (acompanhamento?.pagamentos ?? []).map((p) => p.nome + (p.aprovado ? '' : ' (não aprovado)')).join(', ') || '—' }}
                </p>
            </div>
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <p class="text-xs uppercase text-slate-400">Foto de perfil</p>
                <p class="font-semibold">{{ cliente.tem_foto ? 'Tem foto' : 'Sem foto' }}</p>
            </div>
            <div class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] p-3">
                <p class="text-xs uppercase text-slate-400">Senha</p>
                <p class="font-semibold">{{ acompanhamento?.senha?.texto ?? 'Nada registrado' }}</p>
                <p v-if="acompanhamento?.senha?.em" class="text-xs text-slate-500">{{ dataHora(acompanhamento.senha.em) }}</p>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <button v-for="(nome, cat) in categorias" :key="cat" type="button"
                :class="['rounded-full px-3 py-1 font-medium ring-1 transition', ativas.has(cat) ? `${CORES[cat]} ring-transparent` : 'bg-white text-slate-400 ring-slate-200 line-through']"
                :title="'Clique para mostrar/esconder. Duplo clique: só este tipo.'"
                @click="alternar(cat)" @dblclick="soEsta(cat)">
                {{ nome }} <span class="ml-1 opacity-70">{{ contagem[cat] ?? 0 }}</span>
            </button>
            <button type="button" class="text-xs text-sky-700 hover:underline" @click="todas">mostrar tudo</button>
            <label class="ml-auto flex items-center gap-2 text-xs text-slate-600">
                <input v-model="maisRecentesPrimeiro" type="checkbox"> mais recentes primeiro
            </label>
        </div>

        <div v-if="cortes.length" class="mb-3 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800">
            <p v-for="c in cortes" :key="c">{{ c }}</p>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <p class="mb-3 text-xs text-slate-500">{{ visiveis }} de {{ eventos.length }} acontecimentos</p>
            <p v-if="!porDia.length" class="text-sm text-slate-500">Nada registrado para os tipos escolhidos.</p>
            <div v-for="g in porDia" :key="g.dia" class="mb-5">
                <h3 class="sticky top-0 z-10 mb-2 bg-[var(--surface)] py-1 text-sm font-semibold capitalize text-slate-700">{{ g.dia }}</h3>
                <ol class="relative ml-2 border-l border-slate-200">
                    <li v-for="(e, i) in g.itens" :key="i" class="mb-2 ml-4">
                        <span :class="['absolute -left-3 flex h-6 w-6 items-center justify-center rounded-full text-[11px]', CORES[e.categoria]]">
                            <i :class="['fas', e.icone]"></i>
                        </span>
                        <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                            <span class="font-mono text-xs text-slate-400">{{ hora(e.em) }}</span>
                            <span class="font-medium text-slate-800 break-all">{{ e.titulo }}</span>
                            <span v-if="duracao(e.tempo_segundos)" class="rounded bg-slate-100 px-1.5 text-xs text-slate-500" title="Tempo até a próxima página do mesmo navegador">
                                {{ duracao(e.tempo_segundos) }} na página
                            </span>
                            <Link v-if="e.pedido_id" :href="`/admin/pedidos/${e.pedido_id}`" class="text-xs text-sky-700 hover:underline">ver pedido</Link>
                        </div>
                        <p v-if="e.texto" class="text-xs text-slate-500 break-words">{{ e.texto }}</p>
                    </li>
                </ol>
            </div>
        </div>

        <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-sm shadow-sm">
            <h2 class="mb-2 text-base font-semibold">Desde quando cada tipo é registrado</h2>
            <table class="w-full text-left text-xs">
                <thead class="text-slate-400">
                    <tr><th class="py-1 pr-3">Tipo</th><th class="py-1 pr-3">Registrado desde</th><th class="py-1 pr-3">Guardado por</th><th class="py-1">Observação</th></tr>
                </thead>
                <tbody>
                    <tr v-for="f in fontes" :key="f.nome" class="border-t border-slate-100">
                        <td class="py-1 pr-3 font-medium text-slate-700">{{ f.nome }}</td>
                        <td class="py-1 pr-3">{{ f.desde }}</td>
                        <td class="py-1 pr-3">{{ f.guarda }}</td>
                        <td class="py-1 text-slate-500">{{ f.obs ?? '' }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 text-xs text-slate-500">
                Página visitada só aparece com o aceite de cookies daquela visita; sem aceite, fica só o horário do acesso. O mapa de
                cliques das páginas de produto é anônimo e não entra aqui. Cada abertura desta tela fica na Auditoria.
            </p>
        </div>
    </AdminLayout>
</template>
