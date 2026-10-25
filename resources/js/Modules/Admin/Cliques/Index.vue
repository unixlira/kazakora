<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { sidebarSections } from '@/Shared/adminSidebarItems';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

/**
 * Cliques do painel (pedido do Lira 2026-10-25): o que a equipe usa no admin
 * e o que ninguém usa. Os dados vêm de admin_cliques (contagem por dia).
 */
const props = defineProps({
    filtros: { type: Object, default: () => ({}) },
    periodo: { type: Object, default: () => ({}) },
    ranking: { type: Array, default: () => [] },
    semCliqueNoPeriodo: { type: Array, default: () => [] },
    porPagina: { type: Array, default: () => [] },
    porUsuario: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    paginas: { type: Array, default: () => [] },
    usuarios: { type: Array, default: () => [] },
});

const PERIODOS = [
    { chave: 'hoje', rotulo: 'Hoje' },
    { chave: '7', rotulo: '7 dias' },
    { chave: '30', rotulo: '30 dias' },
];
const TIPOS = { menu: 'Menu', topo: 'Topo', aba: 'Aba', botao: 'Botão' };

const filtro = reactive({
    periodo: props.filtros.periodo ?? '7',
    pagina: props.filtros.pagina ?? '',
    user_id: props.filtros.user_id ?? '',
});

const visitar = (mudanca = {}) => {
    Object.assign(filtro, mudanca);
    const query = Object.fromEntries(Object.entries(filtro).filter(([, v]) => v !== '' && v !== null));
    router.get('/admin/relatorios/cliques', query, { preserveState: true, preserveScroll: true });
};

const busca = ref('');
const rankingFiltrado = computed(() => {
    const termo = busca.value.trim().toLowerCase();
    return termo ? props.ranking.filter((r) => r.elemento.toLowerCase().includes(termo)) : props.ranking;
});
const maior = computed(() => Math.max(1, ...props.ranking.map((r) => r.total)));
const largura = (n) => `${Math.max(2, Math.round((n / maior.value) * 100))}%`;

// Itens do menu que ninguém clicou no período (a lista de itens vem do
// próprio menu; só faz sentido sem filtro de página).
const itensDoMenu = computed(() => sidebarSections.flatMap((s) => s.items.flatMap((item) => [
    { nome: `Menu: ${item.label}`, grupo: s.heading, href: item.href },
    ...(item.children ?? []).map((f) => ({ nome: `Menu: ${item.label} › ${f.label}`, grupo: s.heading, href: f.href })),
])));
const clicados = computed(() => new Set(props.ranking.map((r) => r.elemento)));
const menuSemClique = computed(() => itensDoMenu.value.filter((i) => !clicados.value.has(i.nome)));
const quaseZero = computed(() => props.ranking.filter((r) => r.total <= 2).slice().reverse());

const numero = (n) => Number(n || 0).toLocaleString('pt-BR');
</script>

<template>
    <Head title="Cliques do painel" />

    <AdminLayout>
        <header class="mb-3">
            <h1 class="text-2xl font-bold">Cliques do painel</h1>
            <p class="text-sm text-pretty text-[var(--texto-suave)]">
                Quais menus, abas e botões a equipe usa no admin. Conta só o nome do item clicado, nunca o que foi digitado.
                Período: {{ periodo.de }} a {{ periodo.ate }}.
            </p>
        </header>

        <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-[var(--surface-border)]" aria-label="Período">
            <button v-for="p in PERIODOS" :key="p.chave" type="button"
                class="shrink-0 border-b-2 px-3 py-2 text-sm font-semibold whitespace-nowrap transition-colors"
                :class="filtro.periodo === p.chave ? 'border-[var(--color-primary)] text-[var(--surface-foreground)]' : 'border-transparent text-[var(--texto-suave)] hover:text-[var(--surface-foreground)]'"
                :aria-current="filtro.periodo === p.chave ? 'page' : undefined"
                :data-clique="`Cliques do painel: período ${p.rotulo}`"
                @click="visitar({ periodo: p.chave })">{{ p.rotulo }}</button>
        </nav>

        <div class="mb-4 grid grid-cols-1 gap-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:grid-cols-3">
            <div>
                <label for="filtro-pagina" class="block text-xs font-medium text-[var(--texto-suave)]">Página</label>
                <select id="filtro-pagina" v-model="filtro.pagina" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" @change="visitar()">
                    <option value="">Todas</option>
                    <option v-for="p in paginas" :key="p" :value="p">{{ p }}</option>
                </select>
            </div>
            <div>
                <label for="filtro-usuario" class="block text-xs font-medium text-[var(--texto-suave)]">Usuário</label>
                <select id="filtro-usuario" v-model="filtro.user_id" class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm" @change="visitar()">
                    <option value="">Todos</option>
                    <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                </select>
            </div>
            <div>
                <label for="busca-elemento" class="block text-xs font-medium text-[var(--texto-suave)]">Buscar item</label>
                <input id="busca-elemento" v-model="busca" type="search" autocomplete="off" placeholder="Ex.: Pedidos…"
                    class="mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1.5 text-sm">
            </div>
        </div>

        <section class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Resumo do período">
            <CardStats stat-subtitle="CLIQUES" :stat-title="numero(total)" stat-icon-name="fas fa-arrow-pointer" variant="primary" compact />
            <CardStats stat-subtitle="ITENS USADOS" :stat-title="numero(ranking.length)" stat-icon-name="fas fa-list-check" variant="success" compact />
            <CardStats stat-subtitle="MENU SEM CLIQUE" :stat-title="filtro.pagina ? '—' : numero(menuSemClique.length)" stat-icon-name="fas fa-ghost" variant="warning" compact />
            <CardStats stat-subtitle="PESSOAS" :stat-title="numero(porUsuario.length)" stat-icon-name="fas fa-users" variant="info" compact />
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm xl:col-span-2" aria-labelledby="titulo-ranking">
                <h2 id="titulo-ranking" class="mb-3 text-base font-bold">Mais clicados</h2>
                <p v-if="rankingFiltrado.length === 0" class="py-6 text-center text-sm text-[var(--texto-suave)]">Nenhum clique registrado neste período.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-[var(--surface-border)] text-left text-xs uppercase tracking-wide text-[var(--texto-suave)]">
                                <th scope="col" class="py-2 pr-2">#</th>
                                <th scope="col" class="py-2 pr-2">Item</th>
                                <th scope="col" class="py-2 pr-2">Tipo</th>
                                <th scope="col" class="py-2 pr-2 text-right">Cliques</th>
                                <th scope="col" class="py-2 pr-2 text-right">Pessoas</th>
                                <th scope="col" class="py-2 text-right">Último</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in rankingFiltrado" :key="r.elemento" class="border-b border-[var(--surface-border)] last:border-0">
                                <td class="py-2 pr-2 tabular-nums text-[var(--texto-suave)]">{{ i + 1 }}</td>
                                <td class="min-w-[14rem] py-2 pr-2">
                                    <div class="font-medium break-words">{{ r.elemento }}</div>
                                    <div class="mt-1 h-1.5 rounded-full bg-[var(--surface-muted)]" aria-hidden="true">
                                        <div class="h-1.5 rounded-full bg-primary" :style="{ width: largura(r.total) }"></div>
                                    </div>
                                    <Link v-if="r.destino" :href="r.destino" class="text-xs text-primary hover:underline" data-clique="Cliques do painel: abrir destino">{{ r.destino }}</Link>
                                </td>
                                <td class="py-2 pr-2 text-[var(--texto-suave)]">{{ TIPOS[r.tipo] ?? r.tipo }}</td>
                                <td class="py-2 pr-2 text-right font-semibold tabular-nums">{{ numero(r.total) }}</td>
                                <td class="py-2 pr-2 text-right tabular-nums">{{ r.pessoas }}</td>
                                <td class="py-2 text-right tabular-nums text-[var(--texto-suave)]">{{ r.ultimoDia }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex flex-col gap-4">
                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="titulo-paginas">
                    <h2 id="titulo-paginas" class="mb-3 text-base font-bold">Páginas com mais cliques</h2>
                    <p v-if="porPagina.length === 0" class="text-sm text-[var(--texto-suave)]">Sem dados.</p>
                    <ul v-else class="space-y-1.5 text-sm">
                        <li v-for="p in porPagina" :key="p.pagina" class="flex items-center justify-between gap-2">
                            <button type="button" class="min-w-0 truncate text-left text-primary hover:underline" :title="`Filtrar pela página ${p.pagina}`"
                                data-clique="Cliques do painel: filtrar página" @click="visitar({ pagina: p.pagina })">{{ p.pagina }}</button>
                            <span class="shrink-0 font-semibold tabular-nums">{{ numero(p.total) }}</span>
                        </li>
                    </ul>
                </section>

                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="titulo-usuarios">
                    <h2 id="titulo-usuarios" class="mb-3 text-base font-bold">Por pessoa</h2>
                    <p v-if="porUsuario.length === 0" class="text-sm text-[var(--texto-suave)]">Sem dados.</p>
                    <ul v-else class="space-y-1.5 text-sm">
                        <li v-for="u in porUsuario" :key="u.nome" class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate">{{ u.nome }}</span>
                            <span class="shrink-0 font-semibold tabular-nums">{{ numero(u.total) }}</span>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <section class="mt-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm" aria-labelledby="titulo-pouco-usados">
            <h2 id="titulo-pouco-usados" class="text-base font-bold">Pouco ou nada usados</h2>
            <p class="mb-3 text-sm text-pretty text-[var(--texto-suave)]">Candidatos a sair do menu ou a ir para um lugar mais escondido. Confira com a equipe antes de tirar.</p>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div>
                    <h3 class="mb-2 text-sm font-semibold">Itens do menu sem clique ({{ filtro.pagina ? '—' : menuSemClique.length }})</h3>
                    <p v-if="filtro.pagina" class="text-sm text-[var(--texto-suave)]">Tire o filtro de página para ver esta lista.</p>
                    <p v-else-if="menuSemClique.length === 0" class="text-sm text-[var(--texto-suave)]">Todos os itens do menu tiveram clique.</p>
                    <ul v-else class="max-h-96 space-y-1 overflow-y-auto text-sm">
                        <li v-for="i in menuSemClique" :key="i.nome" class="flex justify-between gap-2">
                            <span class="min-w-0 truncate">{{ i.nome.replace(/^Menu: /, '') }}</span>
                            <span class="shrink-0 text-xs text-[var(--texto-suave)]">{{ i.grupo }}</span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-semibold">Com 1 ou 2 cliques ({{ quaseZero.length }})</h3>
                    <p v-if="quaseZero.length === 0" class="text-sm text-[var(--texto-suave)]">Nenhum.</p>
                    <ul v-else class="max-h-96 space-y-1 overflow-y-auto text-sm">
                        <li v-for="r in quaseZero" :key="r.elemento" class="flex justify-between gap-2">
                            <span class="min-w-0 truncate">{{ r.elemento }}</span>
                            <span class="shrink-0 tabular-nums">{{ r.total }}</span>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="mb-2 text-sm font-semibold">Usados antes, nada no período ({{ semCliqueNoPeriodo.length }})</h3>
                    <p v-if="semCliqueNoPeriodo.length === 0" class="text-sm text-[var(--texto-suave)]">Nenhum nos últimos 90 dias.</p>
                    <ul v-else class="max-h-96 space-y-1 overflow-y-auto text-sm">
                        <li v-for="r in semCliqueNoPeriodo" :key="r.elemento" class="flex justify-between gap-2">
                            <span class="min-w-0 truncate">{{ r.elemento }}</span>
                            <span class="shrink-0 text-xs text-[var(--texto-suave)]">último {{ r.ultimoDia }}</span>
                        </li>
                    </ul>
                </div>
            </div>
            <p class="mt-3 text-xs text-[var(--texto-suave)]">A contagem começou em 25/10/2026: item que não aparece pode ser só falta de tempo de medição.</p>
        </section>
    </AdminLayout>
</template>
