<script setup>
// Financeiro > Arquivos do TikTok (2026-10-11): envio manual dos relatórios
// financeiros baixados no Seller Center, para a auditoria.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    arquivos: { type: Array, default: () => [] },
});

const form = useForm({ arquivos: [] });
const escolher = (e) => { form.arquivos = Array.from(e.target.files ?? []); };
const enviar = () => form.post('/admin/arquivos-tiktok', { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
const TIPOS = { liquidacao: 'Liquidação (Income)', pedidos: 'Pedidos', anuncios: 'Anúncios', devolucoes: 'Devoluções' };
</script>

<template>
    <Head title="Arquivos do TikTok" />

    <AdminLayout>
        <div class="space-y-6">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><i class="fab fa-tiktok"></i> Arquivos financeiros do TikTok</h1>
                <p class="mt-1 text-sm text-slate-500">Baixe no TikTok Seller Center (Finanças › Extratos / Income) e envie aqui. O mesmo arquivo não entra duas vezes.</p>
            </div>

            <form class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-5" @submit.prevent="enviar">
                <input type="file" multiple accept=".xlsx,.xls,.csv,.zip,.json,.pdf,.txt" class="block w-full text-sm" @change="escolher">
                <p v-if="form.errors.arquivos || form.errors['arquivos.0']" class="mt-2 text-xs text-red-600">{{ form.errors.arquivos || form.errors['arquivos.0'] }}</p>
                <button type="submit" :disabled="form.processing || !form.arquivos.length" class="mt-4 rounded-lg bg-primary px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">
                    <i class="fas" :class="form.processing ? 'fa-circle-notch animate-spin' : 'fa-upload'"></i> Enviar arquivos
                </button>
            </form>

            <div class="overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)]">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-[var(--surface-border)] text-xs uppercase text-slate-400">
                        <tr><th class="px-4 py-3">Arquivo</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Tamanho</th><th class="px-4 py-3">Por</th><th class="px-4 py-3">Recebido em</th></tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--surface-border)]">
                        <tr v-for="a in arquivos" :key="a.id">
                            <td class="px-4 py-3"><i class="fas fa-circle mr-2 text-[8px] text-green-500"></i>{{ a.nome }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ TIPOS[a.tipo] ?? '—' }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ a.tamanho_kb }} KB</td>
                            <td class="px-4 py-3 text-slate-500">{{ a.origem }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ new Date(a.recebido_em).toLocaleString('pt-BR') }}</td>
                        </tr>
                        <tr v-if="!arquivos.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">Nenhum arquivo ainda.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AdminLayout>
</template>
