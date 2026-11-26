<script setup>
// Detalhe de uma conta do site (Clientes dos sites, pedido 2026-11-16):
// cadastro, endereços, pedidos, acesso e os leads/carrinhos ligados pelo
// e-mail, CPF ou telefone. Só leitura.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import CardStats from '@/Shared/Components/CardStats.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { maskCpfCnpj, maskPhone } from '@/Shared/useMasks';
import { maskCep } from '@/Shared/useCep';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    site: { type: String, required: true },
    nomeDoSite: { type: String, default: '' },
    cliente: { type: Object, required: true },
    resumo: { type: Object, required: true },
    enderecos: { type: Array, default: () => [] },
    pedidos: { type: Array, default: () => [] },
    acesso: { type: Object, default: () => ({}) },
    leads: { type: Array, default: () => [] },
    carrinhos: { type: Array, default: () => [] },
    podeVerHistorico: { type: Boolean, default: false },
});

const NOMES_SITE = { kazakora: 'KazaKora', ferrakora: 'FerraKora', beautykora: 'Beauty Kora' };
const formatPrice = (value) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
const formatDate = (value) => (value ? new Date(value).toLocaleDateString('pt-BR', { timeZone: 'America/Sao_Paulo' }) : '—');
const formatDateTime = (value) => (value
    ? new Date(value).toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—');
const nascimento = (value) => (value ? value.split('-').reverse().join('/') : '—');
const ticketMedio = props.resumo.pedidos_pagos > 0 ? props.resumo.total_gasto / props.resumo.pedidos_pagos : 0;
const itensTexto = (itens) => (itens ?? []).map((i) => `${i.quantidade}x ${i.nome}${i.variacao ? ` (${i.variacao})` : ''}`).join(', ') || '—';
</script>

<template>
    <Head :title="`Cliente — ${cliente.nome ?? 'Sem nome'}`" />

    <AdminLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold">{{ cliente.nome ?? 'Sem nome' }}</h1>
                <p class="text-sm text-slate-500">Conta do site {{ nomeDoSite }} · cadastro em {{ formatDateTime(cliente.cadastro_em) }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Link v-if="podeVerHistorico" :href="`/admin/clientes-dos-sites/${site}/${cliente.id}/historico`"
                    class="inline-flex items-center gap-2 rounded-md bg-sky-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-sky-700">
                    <i class="fas fa-timeline"></i> Linha do tempo completa
                </Link>
                <Link :href="`/admin/clientes-dos-sites?site=${site}`" class="text-sm text-primary hover:underline">
                    <i class="fas fa-arrow-left mr-1"></i> Voltar para Clientes dos sites
                </Link>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Cadastro</h2>
            <div class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">E-mail</p>
                    <p class="break-all">{{ cliente.email ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Telefone</p>
                    <p class="flex items-center gap-2">
                        {{ cliente.telefone ? maskPhone(cliente.telefone) : '—' }}
                        <a v-if="cliente.whatsapp" :href="cliente.whatsapp" target="_blank" rel="noopener noreferrer"
                            class="text-green-600 hover:text-green-700" title="Abrir conversa no WhatsApp">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </a>
                    </p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">CPF</p>
                    <p>{{ cliente.cpf ? maskCpfCnpj(cliente.cpf) : '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Nascimento</p>
                    <p>{{ nascimento(cliente.nascimento) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Entrou em</p>
                    <p>{{ formatDateTime(cliente.cadastro_em) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Último acesso</p>
                    <p>{{ acesso.ultimo_acesso_em ? formatDateTime(acesso.ultimo_acesso_em) : 'Sem registro' }}</p>
                    <p class="text-xs text-slate-400">{{ acesso.fonte }}</p>
                    <p v-if="acesso.sessoes_abertas !== null && acesso.sessoes_abertas !== undefined" class="text-xs text-slate-400">
                        Sessões abertas agora: {{ acesso.sessoes_abertas }}
                    </p>
                </div>
                <div v-if="site === 'kazakora'">
                    <p class="text-xs font-medium uppercase text-slate-400">Promoções</p>
                    <p>{{ cliente.recebe_promocoes ? 'Aceita receber' : 'Não aceita' }}</p>
                </div>
                <div v-if="site === 'kazakora'">
                    <p class="text-xs font-medium uppercase text-slate-400">E-mail confirmado</p>
                    <p>{{ cliente.email_verificado_em ? formatDateTime(cliente.email_verificado_em) : 'Não' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Senha</p>
                    <p v-if="cliente.senha_definida === false">Ainda não criou (conta vinda de pedido antigo)</p>
                    <p v-else-if="cliente.senha_temporaria">Ainda usa a senha temporária do e-mail</p>
                    <p v-else>Senha pessoal criada</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase text-slate-400">Cadastro alterado em</p>
                    <p>{{ formatDateTime(cliente.atualizado_em) }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CardStats stat-subtitle="PEDIDOS PAGOS" :stat-title="String(resumo.pedidos_pagos)" stat-icon-name="fas fa-receipt" variant="primary" />
            <CardStats stat-subtitle="TOTAL GASTO" :stat-title="formatPrice(resumo.total_gasto)" stat-icon-name="fas fa-sack-dollar" variant="success" />
            <CardStats stat-subtitle="TICKET MÉDIO" :stat-title="formatPrice(ticketMedio)" stat-icon-name="fas fa-chart-line" variant="secondary" />
            <CardStats stat-subtitle="ÚLTIMO PEDIDO PAGO" :stat-title="formatDate(resumo.ultimo_pedido_em)" stat-icon-name="fas fa-calendar-check" variant="info" />
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Endereços</h2>
            <p v-if="!enderecos.length" class="text-sm text-slate-400">Nenhum endereço cadastrado.</p>
            <div v-else class="grid grid-cols-1 gap-3 md:grid-cols-2">
                <div v-for="(e, i) in enderecos" :key="i" class="rounded-lg border border-[var(--surface-border)] p-3 text-sm">
                    <div class="mb-1 flex items-center gap-2 font-medium">
                        {{ e.rotulo || e.destinatario }}
                        <StatusBadge v-if="e.principal" status="green" label="Principal" />
                    </div>
                    <p>{{ e.rua }}, {{ e.numero }}<span v-if="e.complemento"> - {{ e.complemento }}</span></p>
                    <p>{{ e.bairro }} · {{ e.cidade }}/{{ e.uf }} · CEP {{ maskCep(e.cep) }}</p>
                    <p class="text-xs text-slate-400">
                        Recebe: {{ e.destinatario }}<span v-if="e.telefone"> · {{ maskPhone(e.telefone) }}</span>
                    </p>
                </div>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Pedidos</h2>
            <p v-if="!pedidos.length" class="text-sm text-slate-400">Nenhum pedido feito com esta conta.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-2 pr-4">Pedido</th>
                            <th class="py-2 pr-4">Site</th>
                            <th class="py-2 pr-4">Situação</th>
                            <th class="py-2 pr-4">Total</th>
                            <th class="py-2 pr-4">Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in pedidos" :key="p.id" class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="py-2 pr-4">
                                <Link :href="`/admin/pedidos/${p.id}`" class="font-medium text-sky-700 hover:underline">
                                    {{ p.numero ?? `#${p.id}` }}
                                </Link>
                            </td>
                            <td class="py-2 pr-4">{{ NOMES_SITE[p.site] ?? p.site }}</td>
                            <td class="py-2 pr-4"><StatusBadge :status="p.status" context="order" /></td>
                            <td class="py-2 pr-4">{{ formatPrice(p.total) }}</td>
                            <td class="py-2 pr-4 whitespace-nowrap">{{ formatDateTime(p.criado_em) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">Leads do checkout</h2>
            <p class="mb-3 text-xs text-slate-400">O que foi digitado no checkout com o mesmo e-mail, CPF ou telefone desta conta.</p>
            <p v-if="!leads.length" class="text-sm text-slate-400">Nenhum lead ligado a esta conta.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-2 pr-4">Quando</th>
                            <th class="py-2 pr-4">Site</th>
                            <th class="py-2 pr-4">Produtos</th>
                            <th class="py-2 pr-4">Subtotal</th>
                            <th class="py-2 pr-4">Situação</th>
                            <th class="py-2 pr-4">Origem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in leads" :key="l.id" class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="py-2 pr-4 whitespace-nowrap">{{ formatDateTime(l.atualizado_em) }}</td>
                            <td class="py-2 pr-4">{{ NOMES_SITE[l.site] ?? l.site }}</td>
                            <td class="py-2 pr-4 text-xs">{{ itensTexto(l.itens) }}</td>
                            <td class="py-2 pr-4">{{ formatPrice(l.subtotal) }}</td>
                            <td class="py-2 pr-4">
                                <StatusBadge :status="l.status" :tone="l.status === 'convertido' ? 'green' : 'yellow'"
                                    :label="l.status === 'convertido' ? 'Convertido' : 'Abandonou'" />
                                <Link v-if="l.pedido_id" :href="`/admin/pedidos/${l.pedido_id}`" class="block text-xs text-sky-700 hover:underline">
                                    Ver pedido
                                </Link>
                            </td>
                            <td class="py-2 pr-4 text-xs text-slate-500">{{ l.origem ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-1 text-lg font-semibold">Carrinhos do checkout</h2>
            <p class="mb-3 text-xs text-slate-400">Carrinhos que chegaram à entrega ou ao pagamento com os dados desta conta.</p>
            <p v-if="!carrinhos.length" class="text-sm text-slate-400">Nenhum carrinho ligado a esta conta.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-2 pr-4">Quando</th>
                            <th class="py-2 pr-4">Site</th>
                            <th class="py-2 pr-4">Etapa</th>
                            <th class="py-2 pr-4">Produtos</th>
                            <th class="py-2 pr-4">Subtotal</th>
                            <th class="py-2 pr-4">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in carrinhos" :key="c.id" class="border-b border-[var(--surface-border)] last:border-0">
                            <td class="py-2 pr-4 whitespace-nowrap">{{ formatDateTime(c.atualizado_em) }}</td>
                            <td class="py-2 pr-4">{{ NOMES_SITE[c.site] ?? c.site }}</td>
                            <td class="py-2 pr-4">{{ c.etapa === 'pagamento' ? 'Pagamento' : 'Entrega' }}</td>
                            <td class="py-2 pr-4 text-xs">{{ itensTexto(c.itens) }}</td>
                            <td class="py-2 pr-4">{{ formatPrice(c.subtotal) }}</td>
                            <td class="py-2 pr-4">
                                <StatusBadge :status="c.status" :tone="c.status === 'convertido' ? 'green' : 'yellow'"
                                    :label="c.status === 'convertido' ? 'Convertido' : 'Abandonou'" />
                                <Link v-if="c.pedido_id" :href="`/admin/pedidos/${c.pedido_id}`" class="block text-xs text-sky-700 hover:underline">
                                    Ver pedido
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
