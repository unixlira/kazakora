<script setup>
// Janela "Remarketing" da tela única de Leads (plano do Lira 25/11/2026, §5):
// a sequência do carrinho abandonado (D+1 ... D+30) com o estado de cada
// canal. Só consulta + o botão de antecipar que já existia.
import ConfirmModal from '@/Shared/Components/ConfirmModal.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { maskPhone } from '@/Shared/useMasks';
import { dataHora, dinheiro } from './formato';
import { useDetalhe } from './useDetalhe';

const props = defineProps({
    lead: { type: Object, default: null },
    base: { type: String, required: true },
});
const emit = defineEmits(['close', 'antecipar']);
const estado = useDetalhe(props, 'remarketing');

const CANAL = { whatsapp: { nome: 'WhatsApp', icone: 'fab fa-whatsapp text-emerald-600' }, email: { nome: 'E-mail', icone: 'fas fa-envelope text-sky-600' } };
const ESTADO = {
    enviado: { rotulo: 'Enviado', tom: 'green' },
    agendado: { rotulo: 'Agendado', tom: 'blue' },
    pulado: { rotulo: 'Pulado', tom: 'gray' },
    falhou: { rotulo: 'Falhou', tom: 'red' },
};
const COR_SEQUENCIA = { andamento: 'bg-orange-50 text-orange-800', comprou: 'bg-emerald-50 text-emerald-800', parou: 'bg-slate-100 text-slate-700', nao_inscrito: 'bg-amber-50 text-amber-800' };
</script>

<template>
    <ConfirmModal :open="!!lead" :title="`Remarketing${lead?.nome ? ` · ${lead.nome}` : ''}`" width="max-w-[720px]" detalhe @close="emit('close')">
        <div v-if="estado.carregando" class="py-8 text-center text-sm text-slate-500">
            <i class="fas fa-spinner fa-spin mr-1.5" aria-hidden="true"></i> Carregando…
        </div>
        <p v-else-if="estado.erro" class="rounded-md bg-rose-50 p-3 text-sm text-rose-700">{{ estado.erro }}</p>
        <template v-else-if="estado.dados">
            <div class="mb-4 grid gap-3 text-sm sm:grid-cols-2">
                <div class="min-w-0">
                    <div class="text-xs text-slate-500">Carrinho</div>
                    <div v-for="(i, n) in estado.dados.itens" :key="n" class="truncate" :title="i.nome">{{ i.quantidade }}x {{ i.nome }}</div>
                    <div class="font-bold">Total {{ dinheiro(estado.dados.total) }}</div>
                </div>
                <div class="space-y-1">
                    <div><span class="text-xs text-slate-500">Loja</span> · {{ estado.dados.loja }}</div>
                    <div><span class="text-xs text-slate-500">Abandonado em</span> · {{ dataHora(estado.dados.abandonado_em) }}</div>
                    <div class="truncate text-xs" :title="estado.dados.contato.email ?? ''">
                        <span class="text-slate-500">Envia para</span>
                        <i class="fab fa-whatsapp ml-1 text-emerald-600" aria-hidden="true"></i> {{ estado.dados.contato.whatsapp ? maskPhone(estado.dados.contato.whatsapp) : 'sem celular' }}
                        <i class="fas fa-envelope ml-1 text-sky-600" aria-hidden="true"></i> {{ estado.dados.contato.email ?? 'sem e-mail' }}
                    </div>
                    <div class="inline-block rounded-md px-2 py-1 text-xs font-semibold" :class="COR_SEQUENCIA[estado.dados.sequencia.estado]">
                        {{ estado.dados.sequencia.texto }}
                        <template v-if="estado.dados.sequencia.estado === 'andamento'"> · {{ estado.dados.disparos }}/{{ estado.dados.total_disparos }} enviados</template>
                    </div>
                </div>
            </div>

            <ol class="space-y-2">
                <li v-for="passo in estado.dados.passos" :key="passo.numero" class="rounded-lg border p-2.5"
                    :class="passo.atual ? 'border-orange-400 bg-orange-50/60' : 'border-[var(--surface-border)]'">
                    <div class="mb-1 flex items-center gap-2 text-sm font-semibold">
                        <span class="rounded bg-[var(--surface-muted)] px-1.5 py-0.5 font-mono text-xs">{{ passo.rotulo }}</span>
                        <span>{{ passo.numero }}º disparo</span>
                        <span v-if="passo.atual" class="text-xs font-semibold text-orange-700">· próximo</span>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div v-for="(c, canal) in passo.canais" :key="canal" class="min-w-0 text-xs">
                            <div class="flex items-center gap-1.5">
                                <i :class="CANAL[canal].icone" aria-hidden="true"></i>
                                <span class="font-medium">{{ CANAL[canal].nome }}</span>
                                <StatusBadge :status="c.estado" :tone="ESTADO[c.estado].tom" :label="ESTADO[c.estado].rotulo" />
                            </div>
                            <div class="mt-0.5 truncate text-slate-500">
                                <template v-if="c.estado === 'enviado'">{{ dataHora(c.quando) }}<span v-if="c.para"> · para {{ canal === 'whatsapp' ? maskPhone(c.para) : c.para }}</span><span v-if="c.provider_id" class="font-mono" :title="c.provider_id"> · {{ c.provider_id }}</span></template>
                                <template v-else-if="c.estado === 'agendado'">previsto {{ dataHora(c.quando) }}</template>
                                <template v-else-if="c.estado === 'falhou'"><span :title="c.erro">{{ c.erro ?? 'erro' }}</span><template v-if="c.nova_tentativa"> · tenta de novo {{ dataHora(c.nova_tentativa) }}</template></template>
                                <template v-else>{{ c.motivo }}</template>
                            </div>
                        </div>
                    </div>
                </li>
            </ol>
        </template>

        <template #actions>
            <a v-if="estado.dados?.conversa_url" :href="estado.dados.conversa_url" class="mr-auto self-center text-sm text-sky-700 hover:underline">
                <i class="fab fa-whatsapp mr-1" aria-hidden="true"></i>Ver conversa no WhatsApp
            </a>
            <button v-if="estado.dados?.pode_antecipar" type="button"
                class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                @click="emit('antecipar', { chave: lead.chave, nome: lead.nome })">
                Antecipar 1ª mensagem
            </button>
            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm font-medium hover:bg-[var(--surface-muted)]" @click="emit('close')">Fechar</button>
        </template>
    </ConfirmModal>
</template>
