<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    campaigns: { type: Array, default: () => [] },
    credentials: { type: Object, required: true },
    settings: { type: Object, required: true },
    limits: { type: Object, required: true },
});

const sampleNumbers = `5511999999999, Maria\n5511888888888, João`;
const copied = ref(false);
const mediaInput = ref(null);
const mediaPreviewUrl = ref(null);
const mediaMeta = ref(null);

const form = useForm({
    name: '',
    mode: 'template',
    numbers_text: '',
    message_body: '',
    template_name: '',
    template_language: 'pt_BR',
    media_file: null,
    dry_run: true,
    confirmation: '',
});

const parsedRecipients = computed(() => {
    const seen = new Set();
    return form.numbers_text
        .split(/\r\n|\r|\n/)
        .map((line) => {
            const [phonePart, namePart] = line.split(/[,;|]/, 2);
            let phone = (phonePart ?? '').replace(/\D+/g, '');
            if (phone.startsWith('0')) phone = phone.replace(/^0+/, '');
            if (!phone.startsWith('55') && phone.length >= 10) phone = `55${phone}`;
            return { phone, name: (namePart ?? '').trim() };
        })
        .filter((item) => {
            if (!/^55\d{10,11}$/.test(item.phone) || seen.has(item.phone)) return false;
            seen.add(item.phone);
            return true;
        });
});

const estimatedStatus = computed(() => {
    if (form.dry_run) return { label: 'Prévia segura', class: 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' };
    if (props.settings.sandbox_mode) return { label: 'Bloqueado pelo modo cauteloso', class: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' };
    if (!props.credentials.readyToSend) return { label: 'Credenciais incompletas', class: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' };
    if (form.confirmation !== props.limits.realSendConfirmation) return { label: 'Aguardando confirmação', class: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' };
    return { label: 'Pronto para envio real', class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' };
});

const canSubmit = computed(() => {
    if (!form.name || parsedRecipients.value.length === 0 || parsedRecipients.value.length > props.limits.maxRecipientsPerBatch) return false;
    if (form.mode === 'template') return Boolean(form.template_name && form.template_language);
    return Boolean(form.message_body || form.media_file);
});

const submit = () => {
    form.post('/admin/whatsapp/disparos', {
        preserveScroll: true,
        forceFormData: true,
    });
};

const useSample = () => {
    form.numbers_text = sampleNumbers;
};

const chooseMedia = () => mediaInput.value?.click();

const onMediaSelected = (event) => {
    const file = event.target.files?.[0] ?? null;
    if (mediaPreviewUrl.value) URL.revokeObjectURL(mediaPreviewUrl.value);

    form.media_file = file;
    mediaPreviewUrl.value = file ? URL.createObjectURL(file) : null;
    mediaMeta.value = file ? {
        name: file.name,
        type: file.type.startsWith('video/') ? 'video' : 'image',
        size: file.size,
    } : null;
};

const removeMedia = () => {
    if (mediaPreviewUrl.value) URL.revokeObjectURL(mediaPreviewUrl.value);
    mediaPreviewUrl.value = null;
    mediaMeta.value = null;
    form.media_file = null;
    if (mediaInput.value) mediaInput.value.value = '';
};

const humanFileSize = (bytes) => {
    if (!bytes) return '';
    const mb = bytes / 1024 / 1024;
    return `${mb.toFixed(mb >= 10 ? 0 : 1)} MB`;
};

const copyConfirmation = async () => {
    await navigator.clipboard.writeText(props.limits.realSendConfirmation);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1600);
};

const statusLabel = (campaign) => {
    const labels = {
        dry_run: 'Prévia',
        running: 'Enviando',
        finished: 'Finalizada',
        partial: 'Parcial',
        failed: 'Falhou',
        draft: 'Rascunho',
    };
    return labels[campaign.status] ?? campaign.status;
};
</script>

<template>
    <Head title="Disparos WhatsApp" />

    <AdminLayout>
        <div class="min-w-0 space-y-6">
            <section class="overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm sm:rounded-3xl">
                <div class="relative overflow-hidden bg-[#075e54] px-4 py-6 text-white sm:px-6 sm:py-8 md:px-8">
                    <div class="absolute right-0 top-0 h-40 w-40 rounded-full bg-emerald-300/25 blur-3xl sm:h-52 sm:w-52"></div>
                    <div class="relative flex min-w-0 flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="min-w-0 max-w-3xl">
                            <div class="mb-4 inline-flex max-w-full items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-100 sm:text-xs sm:tracking-[0.22em]">
                                <i class="fab fa-whatsapp"></i>
                                <span class="truncate">Campanhas e envio manual</span>
                            </div>
                            <h1 class="font-display text-2xl font-semibold sm:text-3xl md:text-4xl">Disparos WhatsApp</h1>
                            <p class="mt-3 max-w-2xl text-sm leading-6 text-emerald-50 md:text-base">
                                Envie mensagens manuais ou campanhas por template aprovado, com prévia obrigatória, mídia opcional e confirmação explícita antes de qualquer envio real.
                            </p>
                        </div>
                        <div class="w-full rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur sm:w-auto sm:min-w-64">
                            <span class="inline-flex max-w-full rounded-full px-3 py-1 text-xs font-semibold" :class="estimatedStatus.class">
                                {{ estimatedStatus.label }}
                            </span>
                            <p class="mt-3 text-xs text-emerald-50">
                                {{ parsedRecipients.length }} contato(s) válidos · limite {{ limits.maxRecipientsPerBatch }} por lote
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.05fr)_minmax(320px,0.95fr)]">
                <form class="min-w-0 space-y-6" enctype="multipart/form-data" @submit.prevent="submit">
                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold">Novo lote</h2>
                                <p class="mt-1 text-sm text-slate-500">Comece em modo prévia. O envio real exige credenciais, sandbox desligado e confirmação manual.</p>
                            </div>
                            <Link href="/admin/whatsapp" class="inline-flex justify-center rounded-xl border border-[var(--surface-border)] px-4 py-2 text-sm font-semibold hover:bg-[var(--surface-muted)]">
                                Configurações
                            </Link>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div class="min-w-0">
                                <label class="text-sm font-semibold">Nome interno</label>
                                <input v-model="form.name" type="text" required placeholder="Campanha utilidades setembro" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <p v-if="form.errors.name" class="mt-1 text-xs text-error">{{ form.errors.name }}</p>
                            </div>
                            <div class="min-w-0">
                                <label class="text-sm font-semibold">Tipo de envio</label>
                                <select v-model="form.mode" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                    <option value="template">Campanha por template aprovado</option>
                                    <option value="text">Texto livre / atendimento manual</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                            <strong>Regra do WhatsApp oficial:</strong> campanhas para iniciar conversa precisam usar template aprovado na Meta. Texto livre é para atendimento manual ou conversa dentro da janela de 24h.
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Mensagem</h2>
                        <div v-if="form.mode === 'template'" class="mt-5 grid min-w-0 gap-4 sm:grid-cols-[minmax(0,1fr)_140px] md:grid-cols-[minmax(0,1fr)_160px]">
                            <div class="min-w-0">
                                <label class="text-sm font-semibold">Nome do template aprovado</label>
                                <input v-model="form.template_name" type="text" placeholder="ex: oferta_utilidades_01" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <p class="mt-2 text-xs leading-5 text-slate-500">Use exatamente o nome cadastrado na Meta. Se anexar imagem/vídeo, o template precisa ter cabeçalho de mídia aprovado.</p>
                                <p v-if="form.errors.template_name" class="mt-1 text-xs text-error">{{ form.errors.template_name }}</p>
                            </div>
                            <div class="min-w-0">
                                <label class="text-sm font-semibold">Idioma</label>
                                <input v-model="form.template_language" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                            </div>
                        </div>

                        <div v-else class="mt-5 min-w-0">
                            <label class="text-sm font-semibold">Texto livre / legenda</label>
                            <textarea v-model="form.message_body" rows="6" maxlength="1000" placeholder="Oi, aqui é a Manuela da KazaKora..." class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                            <div class="mt-2 flex flex-col gap-1 text-xs text-slate-500 sm:flex-row sm:justify-between">
                                <span>Evite promessa de desconto, estoque, prazo ou garantia sem conferir.</span>
                                <span>{{ form.message_body.length }}/1000</span>
                            </div>
                            <p v-if="form.errors.message_body" class="mt-1 text-xs text-error">{{ form.errors.message_body }}</p>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold">Imagem ou vídeo</h2>
                                <p class="mt-1 text-sm text-slate-500">Anexo opcional para disparar com a mensagem. Aceita JPG, PNG, MP4 e 3GP.</p>
                            </div>
                            <button type="button" class="inline-flex justify-center rounded-lg border border-[var(--surface-border)] px-3 py-2 text-xs font-semibold hover:bg-[var(--surface-muted)]" @click="chooseMedia">
                                Escolher mídia
                            </button>
                        </div>

                        <input ref="mediaInput" type="file" class="hidden" accept="image/jpeg,image/png,video/mp4,video/3gpp" @change="onMediaSelected">

                        <div v-if="mediaMeta" class="mt-5 overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface-muted)]">
                            <img v-if="mediaMeta.type === 'image'" :src="mediaPreviewUrl" alt="Prévia da imagem" class="max-h-80 w-full object-contain">
                            <video v-else :src="mediaPreviewUrl" class="max-h-80 w-full bg-black object-contain" controls></video>
                            <div class="flex min-w-0 flex-col gap-3 border-t border-[var(--surface-border)] p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">{{ mediaMeta.name }}</p>
                                    <p class="text-xs text-slate-500">{{ mediaMeta.type === 'image' ? 'Imagem' : 'Vídeo' }} · {{ humanFileSize(mediaMeta.size) }}</p>
                                </div>
                                <button type="button" class="rounded-lg px-3 py-2 text-xs font-semibold text-error hover:bg-red-50 dark:hover:bg-red-500/10" @click="removeMedia">
                                    Remover
                                </button>
                            </div>
                        </div>
                        <div v-else class="mt-5 rounded-2xl border border-dashed border-[var(--surface-border)] p-6 text-center text-sm text-slate-500">
                            Nenhuma mídia anexada. Você pode enviar só texto/template ou anexar uma peça da campanha.
                        </div>
                        <p v-if="form.errors.media_file" class="mt-2 text-xs text-error">{{ form.errors.media_file }}</p>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold">Contatos</h2>
                                <p class="mt-1 text-sm text-slate-500">Um por linha: número com DDI, opcionalmente seguido do nome.</p>
                            </div>
                            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-semibold hover:bg-[var(--surface-muted)]" @click="useSample">
                                Exemplo
                            </button>
                        </div>
                        <textarea v-model="form.numbers_text" rows="8" placeholder="5511999999999, Maria" class="mt-5 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        <div class="mt-2 flex flex-col gap-1 text-xs sm:flex-row sm:items-center sm:justify-between">
                            <span :class="parsedRecipients.length > limits.maxRecipientsPerBatch ? 'text-error' : 'text-slate-500'">
                                {{ parsedRecipients.length }} contato(s) válidos detectados.
                            </span>
                            <span class="text-slate-500">Duplicados são removidos automaticamente.</span>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Travas de envio</h2>
                        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4">
                            <input v-model="form.dry_run" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary">
                            <span>
                                <span class="block text-sm font-semibold">Criar apenas prévia, sem enviar</span>
                                <span class="mt-1 block text-xs text-slate-500">Recomendado para validar lista, template, texto e mídia antes de campanha real.</span>
                            </span>
                        </label>

                        <div v-if="!form.dry_run" class="mt-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-500/20 dark:bg-red-500/10 dark:text-red-200">
                            <p class="font-semibold">Envio real habilitado nesta tela.</p>
                            <p class="mt-1">Digite a frase abaixo para liberar o botão. Isso evita disparo acidental.</p>
                            <div class="mt-3 flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                                <code class="min-w-0 flex-1 break-all rounded-lg bg-white px-3 py-2 text-xs dark:bg-black/30">{{ limits.realSendConfirmation }}</code>
                                <button type="button" class="w-full rounded-lg bg-red-700 px-3 py-2 text-xs font-semibold text-white hover:bg-red-800 sm:w-auto" @click="copyConfirmation">
                                    {{ copied ? 'Copiado' : 'Copiar frase' }}
                                </button>
                            </div>
                            <input v-model="form.confirmation" type="text" class="mt-3 w-full rounded-xl border border-red-200 bg-white px-3 py-2 text-sm text-slate-900" autocomplete="off">
                        </div>

                        <button type="submit" :disabled="form.processing || !canSubmit" class="mt-5 w-full rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                            {{ form.processing ? 'Processando...' : form.dry_run ? 'Criar prévia do lote' : 'Enviar lote agora' }}
                        </button>
                    </section>
                </form>

                <aside class="min-w-0 space-y-6">
                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Checklist operacional</h2>
                        <div class="mt-5 space-y-3">
                            <div class="flex min-w-0 flex-col gap-3 rounded-xl border border-[var(--surface-border)] p-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">Token e número oficial</p>
                                    <p class="mt-1 text-xs text-slate-500">Protegidos no .env; a tela só mostra presença.</p>
                                </div>
                                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold" :class="credentials.readyToSend ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'">
                                    {{ credentials.readyToSend ? 'OK' : 'Pendente' }}
                                </span>
                            </div>
                            <div class="flex min-w-0 flex-col gap-3 rounded-xl border border-[var(--surface-border)] p-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">Modo cauteloso</p>
                                    <p class="mt-1 text-xs text-slate-500">Bloqueia envio real durante calibração.</p>
                                </div>
                                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-semibold" :class="settings.sandbox_mode ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'">
                                    {{ settings.sandbox_mode ? 'Ligado' : 'Desligado' }}
                                </span>
                            </div>
                            <div class="rounded-xl border border-[var(--surface-border)] p-4">
                                <p class="text-sm font-semibold">Templates de campanha</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">Cadastre e aprove o template na Meta antes. Para mídia em template, aprove um cabeçalho de imagem ou vídeo.</p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Prévia rápida</h2>
                        <div class="mt-5 overflow-hidden rounded-2xl bg-[#0b141a] p-3 text-white shadow-inner sm:p-4">
                            <div class="max-w-full rounded-2xl rounded-tl-sm bg-[#202c33] px-3 py-3 text-sm leading-6 sm:max-w-[92%] sm:px-4">
                                <div v-if="mediaMeta" class="mb-3 overflow-hidden rounded-xl bg-black/40">
                                    <img v-if="mediaMeta.type === 'image'" :src="mediaPreviewUrl" alt="Prévia" class="max-h-64 w-full object-contain">
                                    <video v-else :src="mediaPreviewUrl" class="max-h-64 w-full object-contain" muted></video>
                                </div>
                                <template v-if="form.mode === 'template'">
                                    Template: <strong class="break-all">{{ form.template_name || 'nome_do_template' }}</strong><br>
                                    Idioma: {{ form.template_language || 'pt_BR' }}
                                </template>
                                <template v-else>
                                    <span class="whitespace-pre-line break-words">{{ form.message_body || 'Digite a mensagem para visualizar aqui.' }}</span>
                                </template>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Últimos lotes</h2>
                        <div v-if="campaigns.length" class="mt-5 space-y-3">
                            <div v-for="campaign in campaigns" :key="campaign.id" class="rounded-xl border border-[var(--surface-border)] p-4">
                                <div class="flex min-w-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold">{{ campaign.name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ campaign.created_at }} · {{ campaign.mode === 'template' ? 'Template' : 'Texto livre' }}</p>
                                        <p v-if="campaign.media_type" class="mt-1 truncate text-xs text-slate-500">Anexo: {{ campaign.media_type === 'image' ? 'imagem' : 'vídeo' }} · {{ campaign.media_original_name }}</p>
                                    </div>
                                    <span class="w-fit rounded-full bg-[var(--surface-muted)] px-2.5 py-1 text-xs font-semibold">{{ statusLabel(campaign) }}</span>
                                </div>
                                <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                                    <div class="rounded-lg bg-[var(--surface-muted)] p-2"><strong>{{ campaign.total_recipients }}</strong><br>contatos</div>
                                    <div class="rounded-lg bg-[var(--surface-muted)] p-2"><strong>{{ campaign.sent_count }}</strong><br>enviadas</div>
                                    <div class="rounded-lg bg-[var(--surface-muted)] p-2"><strong>{{ campaign.failed_count }}</strong><br>falhas</div>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-5 rounded-xl bg-[var(--surface-muted)] p-4 text-sm text-slate-500">Nenhum lote criado ainda.</p>
                    </section>
                </aside>
            </div>
        </div>
    </AdminLayout>
</template>
