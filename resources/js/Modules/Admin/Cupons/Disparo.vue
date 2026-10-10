<script setup>
// Disparo do cupom em lote (pedido 2026-10-10): escolhe o público
// (carrinho abandonado, clientes, aniversariantes), a ocasião (épocas
// sazonais preenchem o texto) e os canais; mostra quantos vão receber antes.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import InputError from '@/Shared/Components/InputError.vue';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    cupom: { type: Object, required: true },
    publicos: { type: Array, default: () => [] },
    ocasioes: { type: Array, default: () => [] },
    historico: { type: Array, default: () => [] },
});

const { can } = usePermissions();

const form = useForm({
    publico: 'carrinho_abandonado',
    dias: 30,
    ocasiao: 'carrinho',
    assunto: '',
    mensagem: '',
    canais: ['email', 'site'],
});

const aplicarOcasiao = (chave) => {
    const ocasiao = props.ocasioes.find((item) => item.chave === chave);
    if (ocasiao) {
        form.assunto = ocasiao.assunto;
        form.mensagem = ocasiao.mensagem;
    }
};
aplicarOcasiao(form.ocasiao);
watch(() => form.ocasiao, aplicarOcasiao);

// Prévia: quantos clientes o público alcança.
const total = ref(null);
const contando = ref(false);
let contagemTimer = null;
const contar = () => {
    clearTimeout(contagemTimer);
    contagemTimer = setTimeout(async () => {
        contando.value = true;
        try {
            const params = new URLSearchParams({ publico: form.publico, dias: form.dias || 30 });
            const response = await fetch(`/admin/cupons/${props.cupom.id}/publico?${params}`, { headers: { Accept: 'application/json' } });
            total.value = response.ok ? (await response.json()).total : null;
        } finally {
            contando.value = false;
        }
    }, 300);
};
watch(() => [form.publico, form.dias], contar, { immediate: true });

// Exemplo do texto como o cliente vai ler.
const preencher = (texto) => texto
    .replaceAll('{nome}', 'Maria')
    .replaceAll('{cupom}', props.cupom.code)
    .replaceAll('{desconto}', props.cupom.descricao)
    .replaceAll('{validade}', props.cupom.expires_at ? `até ${props.cupom.expires_at.split('-').reverse().join('/')}` : 'por tempo limitado');
const previaAssunto = computed(() => preencher(form.assunto));
const previaMensagem = computed(() => preencher(form.mensagem));

const enviar = () => {
    if (!window.confirm(`Enviar o cupom ${props.cupom.code} para ${total.value ?? 'os'} cliente(s)?`)) return;
    form.post(`/admin/cupons/${props.cupom.id}/disparo`, { preserveScroll: true });
};

// Atualiza o andamento enquanto houver disparo em curso.
const emAndamento = computed(() => props.historico.some((item) => item.status !== 'concluido'));
let poll = null;
onMounted(() => {
    poll = setInterval(() => {
        if (emAndamento.value) router.reload({ only: ['historico'] });
    }, 5000);
});
onBeforeUnmount(() => { clearInterval(poll); clearTimeout(contagemTimer); });

const STATUS = {
    pendente: { label: 'Na fila', tone: 'gray' },
    enviando: { label: 'Enviando', tone: 'blue' },
    concluido: { label: 'Concluído', tone: 'green' },
};
const campo = 'w-full rounded-lg border border-[var(--surface-border)] bg-transparent px-3 py-2 text-sm';
</script>

<template>
    <Head :title="`Disparar ${cupom.code}`" />

    <AdminLayout>
        <div class="mb-6">
            <Link href="/admin/cupons" class="text-sm text-primary hover:underline"><i class="fas fa-arrow-left mr-1"></i> Cupons</Link>
            <h1 class="mb-1 mt-2 text-2xl font-bold">Disparar cupom <span class="font-mono">{{ cupom.code }}</span></h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ cupom.descricao }}<span v-if="cupom.expires_at"> · válido até {{ cupom.expires_at.split('-').reverse().join('/') }}</span>
                · usado {{ cupom.usos }} vez(es)
            </p>
            <p v-if="!cupom.is_active" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700">Este cupom está inativo — ative antes de disparar.</p>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-5">
            <form v-if="can('cadastros.create')" @submit.prevent="enviar"
                class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm xl:col-span-3">
                <h2 class="mb-4 text-lg font-semibold">Novo disparo</h2>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Para quem *</label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="publico in publicos" :key="publico.chave"
                            class="flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm"
                            :class="form.publico === publico.chave ? 'border-primary bg-primary/5' : 'border-[var(--surface-border)]'">
                            <input v-model="form.publico" type="radio" :value="publico.chave" class="mt-0.5">
                            {{ publico.nome }}
                        </label>
                    </div>
                    <div v-if="form.publico === 'carrinho_abandonado'" class="mt-2 flex items-center gap-2 text-sm">
                        Que abandonaram nos últimos
                        <input v-model.number="form.dias" type="number" min="1" max="365" class="w-20 rounded-lg border border-[var(--surface-border)] bg-transparent px-2 py-1 text-sm"> dias
                    </div>
                    <p class="mt-2 text-sm">
                        <i class="fas mr-1" :class="contando ? 'fa-spinner animate-spin' : 'fa-users'"></i>
                        <strong>{{ total ?? '…' }}</strong> cliente(s) vão receber
                        <span class="text-xs text-slate-400">(só clientes com e-mail que aceitam promoções)</span>
                    </p>
                    <InputError :message="form.errors.publico" />
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Ocasião / época</label>
                    <select v-model="form.ocasiao" :class="campo">
                        <option v-for="ocasiao in ocasioes" :key="ocasiao.chave" :value="ocasiao.chave">{{ ocasiao.nome }}</option>
                    </select>
                    <p class="mt-1 text-xs text-slate-400">Preenche o assunto e o texto abaixo — dá para editar à vontade.</p>
                </div>

                <div class="mb-4">
                    <label class="mb-1 block text-sm font-medium">Assunto *</label>
                    <input v-model="form.assunto" type="text" maxlength="150" :class="campo" />
                    <InputError :message="form.errors.assunto" />
                </div>

                <div class="mb-2">
                    <label class="mb-1 block text-sm font-medium">Mensagem *</label>
                    <textarea v-model="form.mensagem" rows="6" maxlength="3000" :class="campo"></textarea>
                    <p class="mt-1 text-xs text-slate-400">Use {nome}, {cupom}, {desconto} e {validade} — cada cliente recebe com os dados dele.</p>
                    <InputError :message="form.errors.mensagem" />
                </div>

                <div class="mb-5">
                    <label class="mb-1 block text-sm font-medium">Enviar por *</label>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <label class="flex items-center gap-2"><input v-model="form.canais" type="checkbox" value="email"> <i class="fas fa-envelope text-slate-400"></i> E-mail</label>
                        <label class="flex items-center gap-2"><input v-model="form.canais" type="checkbox" value="site"> <i class="fas fa-bell text-slate-400"></i> Notificação no site (sininho)</label>
                    </div>
                    <InputError :message="form.errors.canais" />
                </div>

                <button type="submit" :disabled="form.processing || !cupom.is_active || !total"
                    class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-paper-plane'"></i>
                    {{ form.processing ? 'Agendando...' : `Disparar para ${total ?? 0} cliente(s)` }}
                </button>
            </form>

            <div class="space-y-6 xl:col-span-2">
                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h2 class="mb-3 text-lg font-semibold">Prévia do e-mail</h2>
                    <p class="text-xs uppercase text-slate-400">Assunto</p>
                    <p class="mb-3 font-medium">{{ previaAssunto }}</p>
                    <p class="whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ previaMensagem }}</p>
                    <div class="mt-4 rounded-lg border-2 border-dashed border-slate-400 p-3 text-center">
                        <div class="text-xs uppercase tracking-widest text-slate-400">Seu cupom</div>
                        <div class="font-mono text-2xl font-bold tracking-widest">{{ cupom.code }}</div>
                        <div class="text-sm font-semibold text-emerald-600">{{ cupom.descricao }}</div>
                    </div>
                    <div class="mt-3 rounded-lg bg-[#0FB930] py-2 text-center text-sm font-bold text-white">Usar meu cupom agora</div>
                </div>

                <div class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                    <h2 class="mb-3 text-lg font-semibold">Histórico de disparos</h2>
                    <p v-if="historico.length === 0" class="text-sm text-slate-400">Nenhum disparo deste cupom ainda.</p>
                    <div v-for="item in historico" :key="item.id" class="border-b border-[var(--surface-border)] py-3 text-sm last:border-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-medium">{{ item.assunto }}</span>
                            <StatusBadge :tone="STATUS[item.status]?.tone" :label="STATUS[item.status]?.label ?? item.status" />
                        </div>
                        <div class="mt-1 text-xs text-slate-400">
                            {{ item.publico }}<span v-if="item.ocasiao"> · {{ item.ocasiao }}</span> · {{ item.canais.map((canal) => canal === 'email' ? 'e-mail' : 'site').join(' + ') }}
                        </div>
                        <div class="mt-1 text-xs">
                            {{ item.enviados }} de {{ item.total }} enviados<span v-if="item.falhas" class="text-red-600"> · {{ item.falhas }} falha(s)</span>
                            · {{ item.criado_em }}<span v-if="item.criador"> · {{ item.criador }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
