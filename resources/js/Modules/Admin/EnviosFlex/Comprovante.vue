<script setup>
import { Head } from '@inertiajs/vue3';

/**
 * Comprovante de retirada do Flex numa folha — pra imprimir ou "Salvar como
 * PDF" e anexar numa reclamação ou processo (pedido explícito 2026-09-11).
 *
 * Fora do AdminLayout de propósito: é documento, não tela. Cores fixas
 * (branco/preto) porque o que vale é o papel, e fora do .admin-shell as
 * variáveis de tema do admin nem existem.
 *
 * O SHA-256 é recalculado pelo servidor na hora de abrir ("confere"): é o
 * que permite afirmar que a imagem impressa é a mesma gravada no momento da
 * retirada.
 */
defineProps({
    recibo: { type: Object, required: true },
    pedidos: { type: Array, default: () => [] },
    emitidoEm: { type: String, required: true },
    emitidoPor: { type: String, default: null },
});

const quando = (iso) => (iso
    ? new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '—');

const imprimir = () => window.print();
</script>

<template>
    <Head :title="`Comprovante de retirada #${recibo.id}`" />

    <div class="min-h-screen bg-slate-100 py-6 print:bg-white print:py-0">
        <div class="mx-auto mb-4 flex max-w-3xl justify-end gap-2 px-4 print:hidden">
            <button type="button" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white" @click="imprimir">
                Imprimir / Salvar PDF
            </button>
        </div>

        <article class="mx-auto max-w-3xl bg-white p-8 text-slate-900 shadow print:max-w-none print:p-0 print:shadow-none">
            <header class="mb-6 border-b border-slate-300 pb-4">
                <p class="text-xs uppercase tracking-widest text-slate-500">Kora Shop · Mercado Envios Flex</p>
                <h1 class="text-2xl font-bold">Comprovante de retirada nº {{ recibo.id }}</h1>
                <p class="text-sm text-slate-600">
                    {{ recibo.caixas }} pacote(s) entregue(s) ao entregador em <strong>{{ quando(recibo.retiradoEm) }}</strong>
                </p>
            </header>

            <section class="mb-6 grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <div><span class="text-slate-500">Entregador (nome informado):</span> {{ recibo.entregador ?? 'não informado' }}</div>
                <div><span class="text-slate-500">Consentimento:</span> {{ recibo.consentidoEm ? quando(recibo.consentidoEm) : 'não houve' }}</div>
                <div><span class="text-slate-500">Aparelho:</span> {{ recibo.dispositivo ?? '—' }}</div>
                <div><span class="text-slate-500">IP de origem:</span> {{ recibo.ip ?? '—' }}</div>
                <div class="col-span-2 break-all text-xs text-slate-500">Navegador: {{ recibo.navegador ?? '—' }}</div>
            </section>

            <section class="mb-6">
                <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Pacotes</h2>
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
                            <th class="py-1 pr-2">Pedido</th>
                            <th class="py-1 pr-2">Venda ML</th>
                            <th class="py-1 pr-2">Envio ML</th>
                            <th class="py-1 pr-2">Destinatário</th>
                            <th class="py-1">Itens</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pedido in pedidos" :key="pedido.id" class="border-b border-slate-200 align-top">
                            <td class="py-1 pr-2">#{{ pedido.id }}</td>
                            <td class="py-1 pr-2">{{ pedido.venda ?? '—' }}</td>
                            <td class="py-1 pr-2">{{ pedido.envio ?? '—' }}</td>
                            <td class="py-1 pr-2">{{ pedido.cliente ?? '—' }}<span class="block text-xs text-slate-500">{{ pedido.cidade }}</span></td>
                            <td class="py-1">
                                <span v-for="(item, i) in pedido.itens" :key="i" class="block">{{ item.qtd }}x {{ item.nome }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="mb-6 grid grid-cols-2 gap-6">
                <figure>
                    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Foto no momento do envio</h2>
                    <img v-if="recibo.foto" :src="`/admin/envios-flex/recibos/${recibo.id}/foto`" alt="Foto de quem retirou" class="w-full border border-slate-300" />
                    <p v-else class="text-sm text-slate-500">Sem foto.</p>
                    <figcaption v-if="recibo.fotoIntegridade.hash" class="mt-1 break-all text-[10px] text-slate-500">
                        SHA-256 {{ recibo.fotoIntegridade.hash }}
                        <strong :class="recibo.fotoIntegridade.confere ? 'text-green-700' : 'text-red-700'">
                            {{ recibo.fotoIntegridade.confere === true ? '· confere com o arquivo gravado' : recibo.fotoIntegridade.existe ? '· NÃO CONFERE' : '· arquivo apagado pela retenção' }}
                        </strong>
                    </figcaption>
                </figure>
                <figure>
                    <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Assinatura</h2>
                    <img v-if="recibo.assinatura" :src="`/admin/envios-flex/recibos/${recibo.id}/assinatura`" alt="Assinatura" class="w-full border border-slate-300 bg-white" />
                    <p v-else class="text-sm text-slate-500">Sem assinatura.</p>
                    <figcaption v-if="recibo.assinaturaIntegridade.hash" class="mt-1 break-all text-[10px] text-slate-500">
                        SHA-256 {{ recibo.assinaturaIntegridade.hash }}
                        <strong :class="recibo.assinaturaIntegridade.confere ? 'text-green-700' : 'text-red-700'">
                            {{ recibo.assinaturaIntegridade.confere === true ? '· confere com o arquivo gravado' : recibo.assinaturaIntegridade.existe ? '· NÃO CONFERE' : '· arquivo apagado pela retenção' }}
                        </strong>
                    </figcaption>
                </figure>
            </section>

            <section v-if="recibo.textoDoConsentimento" class="mb-6">
                <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-slate-500">Texto exibido e aceito antes da coleta</h2>
                <p class="whitespace-pre-line rounded border border-slate-300 bg-slate-50 p-3 text-xs leading-relaxed">{{ recibo.textoDoConsentimento }}</p>
            </section>

            <footer class="border-t border-slate-300 pt-3 text-[11px] text-slate-500">
                <p v-if="recibo.retido">Retido como prova desde {{ quando(recibo.retidoEm) }} — {{ recibo.motivoRetencao }}.</p>
                <p>
                    Documento de uso restrito, destinado à comprovação desta entrega e ao exercício de direitos em processo administrativo ou judicial.
                    Proibida a divulgação pública.
                </p>
                <p>Emitido em {{ quando(emitidoEm) }}{{ emitidoPor ? ` por ${emitidoPor}` : '' }} a partir dos registros do sistema Kazakora.</p>
            </footer>
        </article>
    </div>
</template>
