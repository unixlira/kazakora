<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    settings: { type: Object, required: true },
    conversations: { type: Array, default: () => [] },
    callbackUrl: { type: String, required: true },
    requestedCallbackUrl: { type: String, required: true },
    credentials: { type: Object, required: true },
    stats: { type: Object, required: true },
});

const form = useForm({
    enabled: Boolean(props.settings.enabled),
    auto_reply_enabled: Boolean(props.settings.auto_reply_enabled),
    sandbox_mode: Boolean(props.settings.sandbox_mode),
    attendant_name: props.settings.attendant_name ?? 'Manuela',
    brand_name: props.settings.brand_name ?? 'KazaKora',
    tone: props.settings.tone ?? 'consultivo',
    max_questions_before_close: props.settings.max_questions_before_close ?? 2,
    store_base_url: props.settings.store_base_url ?? 'https://kazakora.devlira.com.br',
    welcome_message: props.settings.welcome_message ?? '',
    outside_hours_message: props.settings.outside_hours_message ?? '',
    closing_template: props.settings.closing_template ?? '',
    handoff_keywords: props.settings.handoff_keywords ?? '',
    priority_categories: props.settings.priority_categories ?? '',
    forbidden_promises: props.settings.forbidden_promises ?? '',
    business_hours: props.settings.business_hours ?? '',
    verify_token: props.settings.verify_token ?? '',
});

const testForm = useForm({
    to: '',
    message: 'Oi, aqui é a Manuela da KazaKora. Esta é uma mensagem de teste da API oficial do WhatsApp.',
});

const simulatedCustomer = ref('Olá, vi a KazaKora e queria ajuda para escolher um produto para casa.');
const selectedId = ref(props.conversations[0]?.id ?? 'preview');
const copied = ref(null);

const credentialItems = computed(() => [
    { key: 'accessToken', label: 'Token' },
    { key: 'phoneNumberId', label: 'Número oficial' },
    { key: 'businessAccountId', label: 'WABA' },
    { key: 'appSecret', label: 'App secret' },
]);

const integrationStatus = computed(() => {
    if (!form.enabled) return { label: 'Pausado', class: 'bg-slate-600 text-white' };
    if (!props.credentials.readyToSend) return { label: 'Credenciais pendentes', class: 'bg-amber-500 text-white' };
    if (!form.auto_reply_enabled) return { label: 'Recebendo sem auto-resposta', class: 'bg-sky-600 text-white' };
    return { label: 'Manuela ativa', class: 'bg-emerald-600 text-white' };
});

const preview = computed(() => {
    const text = simulatedCustomer.value.toLowerCase();
    if (text.includes('garantia') || text.includes('defeito') || text.includes('troca')) {
        return 'Vou te orientar com cuidado. Me manda o número do pedido e uma foto ou vídeo curto mostrando o problema, por favor.';
    }

    if (text.includes('frete') || text.includes('prazo') || text.includes('cep')) {
        return 'Consigo te ajudar com o prazo. Me manda seu CEP, por favor, que eu confiro o caminho mais seguro pra entrega.';
    }

    if (text.includes('comprar') || text.includes('quero') || text.includes('link')) {
        return form.closing_template;
    }

    return form.welcome_message;
});

const fallbackConversation = computed(() => ({
    id: 'preview',
    display_name: `${form.attendant_name} · prévia`,
    phone: 'WhatsApp oficial KazaKora',
    avatar_initials: 'KK',
    profile_photo_url: null,
    status: 'preview',
    needs_human: false,
    unread_count: 0,
    last_message_preview: preview.value,
    last_message_at: 'agora',
    messages: [
        { id: 'demo-in', direction: 'inbound', type: 'text', body: simulatedCustomer.value, status: 'received', time: '09:41', date: 'Prévia' },
        { id: 'demo-out', direction: 'outbound', type: 'text', body: preview.value, status: 'draft', time: '09:42', date: 'Prévia' },
    ],
}));

const conversationList = computed(() => (props.conversations.length ? props.conversations : [fallbackConversation.value]));
const selectedConversation = computed(() => conversationList.value.find((conversation) => conversation.id === selectedId.value) ?? conversationList.value[0]);

const selectConversation = (conversation) => {
    selectedId.value = conversation.id;
};

const phoneLabel = (phone) => {
    if (!phone) return 'Número não identificado';
    const digits = String(phone).replace(/\D+/g, '');
    if (digits.length === 13 && digits.startsWith('55')) {
        return `+55 (${digits.slice(2, 4)}) ${digits.slice(4, 9)}-${digits.slice(9)}`;
    }
    if (digits.length === 12 && digits.startsWith('55')) {
        return `+55 (${digits.slice(2, 4)}) ${digits.slice(4, 8)}-${digits.slice(8)}`;
    }
    return phone;
};

const statusLabel = (conversation) => {
    if (conversation.needs_human) return 'Humano';
    if (conversation.status === 'open') return 'Aberto';
    if (conversation.status === 'preview') return 'Prévia';
    return conversation.status ?? 'Aberto';
};

const submit = () => {
    form.put('/admin/whatsapp', { preserveScroll: true });
};

const sendTest = () => {
    testForm.post('/admin/whatsapp/testar-envio', { preserveScroll: true });
};

const copyToClipboard = async (value, key) => {
    await navigator.clipboard.writeText(value);
    copied.value = key;
    setTimeout(() => {
        if (copied.value === key) copied.value = null;
    }, 1600);
};
</script>

<template>
    <Head title="WhatsApp / Manuela" />

    <AdminLayout>
        <div class="min-w-0 space-y-6">
            <section class="overflow-hidden rounded-3xl border border-[#d1d7db] bg-[#efeae2] shadow-sm dark:border-slate-700 dark:bg-[#0b141a]">
                <div class="flex min-h-[76vh] flex-col lg:grid lg:grid-cols-[380px_minmax(0,1fr)]">
                    <aside class="min-h-0 border-b border-[#d1d7db] bg-white lg:border-b-0 lg:border-r dark:border-slate-700 dark:bg-[#111b21]">
                        <header class="flex items-center justify-between gap-3 bg-[#f0f2f5] px-4 py-3 dark:bg-[#202c33]">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#00a884] text-sm font-bold text-white">
                                    <span>KK</span>
                                </div>
                                <div class="min-w-0">
                                    <h1 class="truncate text-base font-semibold text-[#111b21] dark:text-slate-100">KazaKora WhatsApp</h1>
                                    <p class="truncate text-xs text-[#667781] dark:text-slate-400">{{ stats.conversations }} conversa(s) · {{ stats.needsHuman }} aguardando humano</p>
                                </div>
                            </div>
                            <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="integrationStatus.class">
                                {{ integrationStatus.label }}
                            </span>
                        </header>

                        <div class="border-b border-[#e9edef] bg-white p-3 dark:border-slate-700 dark:bg-[#111b21]">
                            <div class="rounded-lg bg-[#f0f2f5] px-3 py-2 text-sm text-[#667781] dark:bg-[#202c33] dark:text-slate-300">
                                <i class="fas fa-search mr-2"></i>
                                Pesquisar ou começar uma nova conversa
                            </div>
                        </div>

                        <div class="max-h-[58vh] overflow-y-auto lg:max-h-[calc(76vh-116px)]">
                            <button
                                v-for="conversation in conversationList"
                                :key="conversation.id"
                                type="button"
                                class="flex w-full min-w-0 items-center gap-3 border-b border-[#f0f2f5] px-4 py-3 text-left transition hover:bg-[#f5f6f6] dark:border-slate-800 dark:hover:bg-[#202c33]"
                                :class="selectedConversation?.id === conversation.id ? 'bg-[#f0f2f5] dark:bg-[#202c33]' : 'bg-white dark:bg-[#111b21]'"
                                @click="selectConversation(conversation)"
                            >
                                <div class="relative h-12 w-12 shrink-0 overflow-hidden rounded-full bg-[#dfe5e7] text-[#54656f] dark:bg-slate-700 dark:text-slate-100">
                                    <img v-if="conversation.profile_photo_url" :src="conversation.profile_photo_url" alt="Foto do contato" class="h-full w-full object-cover">
                                    <div v-else class="flex h-full w-full items-center justify-center text-sm font-bold">
                                        {{ conversation.avatar_initials }}
                                    </div>
                                    <span v-if="conversation.needs_human" class="absolute bottom-0 right-0 h-3 w-3 rounded-full border-2 border-white bg-amber-500 dark:border-[#111b21]"></span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="flex min-w-0 items-center justify-between gap-2">
                                        <p class="truncate text-sm font-semibold text-[#111b21] dark:text-slate-100">{{ conversation.display_name }}</p>
                                        <span class="shrink-0 text-[11px] text-[#667781] dark:text-slate-400">{{ conversation.last_message_at }}</span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs font-medium text-[#667781] dark:text-slate-400">{{ phoneLabel(conversation.phone) }}</p>
                                    <div class="mt-1 flex min-w-0 items-center justify-between gap-2">
                                        <p class="truncate text-xs text-[#667781] dark:text-slate-400">{{ conversation.last_message_preview }}</p>
                                        <span v-if="conversation.unread_count" class="shrink-0 rounded-full bg-[#25d366] px-1.5 py-0.5 text-[10px] font-bold text-white">{{ conversation.unread_count }}</span>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </aside>

                    <main class="flex min-h-[70vh] min-w-0 flex-1 flex-col bg-[#efeae2] dark:bg-[#0b141a]">
                        <header class="flex min-w-0 items-center justify-between gap-3 border-b border-[#d1d7db] bg-[#f0f2f5] px-4 py-3 dark:border-slate-700 dark:bg-[#202c33]">
                            <div class="flex min-w-0 items-center gap-3">
                                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-[#dfe5e7] text-[#54656f] dark:bg-slate-700 dark:text-slate-100">
                                    <img v-if="selectedConversation?.profile_photo_url" :src="selectedConversation.profile_photo_url" alt="Foto do contato" class="h-full w-full object-cover">
                                    <div v-else class="flex h-full w-full items-center justify-center text-sm font-bold">
                                        {{ selectedConversation?.avatar_initials }}
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <h2 class="truncate text-sm font-semibold text-[#111b21] dark:text-slate-100">{{ selectedConversation?.display_name }}</h2>
                                    <p class="truncate text-xs text-[#667781] dark:text-slate-400">{{ phoneLabel(selectedConversation?.phone) }} · {{ statusLabel(selectedConversation ?? {}) }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-3 text-[#54656f] dark:text-slate-300">
                                <i class="fas fa-search"></i>
                                <i class="fas fa-ellipsis-vertical"></i>
                            </div>
                        </header>

                        <div class="flex-1 overflow-y-auto p-4 sm:p-6">
                            <div class="mx-auto max-w-4xl space-y-3">
                                <div class="mx-auto w-fit rounded-lg bg-[#fff3c4] px-3 py-2 text-center text-[11px] leading-5 text-[#54656f] shadow-sm dark:bg-[#182229] dark:text-slate-300">
                                    As mensagens são armazenadas em `whatsapp_conversations` e `whatsapp_messages`. Tokens nunca aparecem nesta tela.
                                </div>

                                <div
                                    v-for="message in selectedConversation?.messages ?? []"
                                    :key="message.id"
                                    class="flex"
                                    :class="message.direction === 'outbound' ? 'justify-end' : 'justify-start'"
                                >
                                    <div
                                        class="max-w-[84%] rounded-lg px-3 py-2 text-sm leading-6 shadow-sm sm:max-w-[70%]"
                                        :class="message.direction === 'outbound' ? 'rounded-tr-none bg-[#d9fdd3] text-[#111b21] dark:bg-[#005c4b] dark:text-slate-50' : 'rounded-tl-none bg-white text-[#111b21] dark:bg-[#202c33] dark:text-slate-50'"
                                    >
                                        <p class="whitespace-pre-line break-words">{{ message.body || message.type }}</p>
                                        <div class="mt-1 flex items-center justify-end gap-1 text-[10px] opacity-70">
                                            <span>{{ message.time }}</span>
                                            <span v-if="message.direction === 'outbound'">✓✓</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <footer class="border-t border-[#d1d7db] bg-[#f0f2f5] px-3 py-3 dark:border-slate-700 dark:bg-[#202c33]">
                            <div class="flex items-end gap-3">
                                <button type="button" class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-full text-[#54656f] hover:bg-black/5 sm:flex dark:text-slate-300">
                                    <i class="far fa-face-smile"></i>
                                </button>
                                <textarea v-model="simulatedCustomer" rows="1" class="min-h-10 flex-1 resize-none rounded-xl border-0 bg-white px-4 py-2.5 text-sm text-[#111b21] shadow-sm focus:ring-2 focus:ring-[#00a884] dark:bg-[#2a3942] dark:text-slate-100" placeholder="Digite uma prévia de mensagem do cliente"></textarea>
                                <button type="button" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#00a884] text-white">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </footer>
                    </main>
                </div>
            </section>

            <form class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1fr)_360px]" @submit.prevent="submit">
                <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                    <div class="flex min-w-0 flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div>
                            <h2 class="text-lg font-semibold">Configuração da Manuela</h2>
                            <p class="mt-1 text-sm text-slate-500">Ajustes operacionais ficam abaixo do chat, sem poluir a tela principal.</p>
                        </div>
                        <button type="submit" :disabled="form.processing" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:opacity-50">
                            {{ form.processing ? 'Salvando...' : 'Salvar configurações' }}
                        </button>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4">
                            <input v-model="form.enabled" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary">
                            <span><span class="block text-sm font-semibold">Receber mensagens</span><span class="mt-1 block text-xs text-slate-500">Registra conversas oficiais no banco.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4">
                            <input v-model="form.auto_reply_enabled" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary">
                            <span><span class="block text-sm font-semibold">Auto-resposta Manuela</span><span class="mt-1 block text-xs text-slate-500">Responde só dentro das regras.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4">
                            <input v-model="form.sandbox_mode" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary">
                            <span><span class="block text-sm font-semibold">Modo cauteloso</span><span class="mt-1 block text-xs text-slate-500">Bloqueia envio real enquanto calibramos.</span></span>
                        </label>
                        <div class="rounded-xl border border-[var(--surface-border)] p-4">
                            <label class="text-sm font-semibold">Tom de voz</label>
                            <select v-model="form.tone" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <option value="humano">Humano e cordial</option>
                                <option value="consultivo">Consultivo e objetivo</option>
                                <option value="proximo_sem_pressao">Próximo, sem pressão</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Nome público</label>
                            <input v-model="form.attendant_name" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Marca</label>
                            <input v-model="form.brand_name" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-sm font-semibold">Mensagem de boas-vindas</label>
                            <textarea v-model="form.welcome_message" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Horário humano</label>
                            <input v-model="form.business_hours" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Perguntas antes do fechamento</label>
                            <select v-model="form.max_questions_before_close" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <option :value="1">1 pergunta</option>
                                <option :value="2">2 perguntas</option>
                                <option :value="3">3 perguntas</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-sm font-semibold">Resposta fora de horário</label>
                            <textarea v-model="form.outside_hours_message" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label class="text-sm font-semibold">Fechamento consultivo</label>
                            <textarea v-model="form.closing_template" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Handoff para humano</label>
                            <textarea v-model="form.handoff_keywords" rows="4" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Promessas proibidas</label>
                            <textarea v-model="form.forbidden_promises" rows="4" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">Categorias prioritárias</label>
                            <textarea v-model="form.priority_categories" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                        </div>
                        <div>
                            <label class="text-sm font-semibold">URL base da loja</label>
                            <input v-model="form.store_base_url" type="url" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                        </div>
                    </div>
                </section>

                <aside class="space-y-6">
                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Credenciais</h2>
                        <p class="mt-1 text-sm text-slate-500">Status do `.env`, sem mostrar segredo.</p>
                        <div class="mt-4 grid gap-3">
                            <div v-for="item in credentialItems" :key="item.key" class="flex items-center justify-between rounded-xl border border-[var(--surface-border)] p-3">
                                <span class="text-sm font-semibold">{{ item.label }}</span>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="credentials[item.key] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'">
                                    {{ credentials[item.key] ? 'Configurado' : 'Ausente' }}
                                </span>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Webhook Meta</h2>
                        <p class="mt-1 text-sm text-slate-500">Use no painel da Meta.</p>
                        <div class="mt-4 rounded-xl bg-[var(--surface-muted)] p-3">
                            <p class="text-xs font-semibold text-slate-500">Callback URL</p>
                            <code class="mt-1 block break-all text-xs">{{ requestedCallbackUrl }}</code>
                            <button type="button" class="mt-3 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white" @click="copyToClipboard(requestedCallbackUrl, 'callback')">
                                {{ copied === 'callback' ? 'Copiado' : 'Copiar URL' }}
                            </button>
                        </div>
                        <div class="mt-4">
                            <label class="text-sm font-semibold">Verify token</label>
                            <input v-model="form.verify_token" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" autocomplete="off">
                            <button type="button" class="mt-2 rounded-lg border border-[var(--surface-border)] px-3 py-2 text-xs font-semibold" @click="copyToClipboard(form.verify_token, 'verify')">
                                {{ copied === 'verify' ? 'Copiado' : 'Copiar token' }}
                            </button>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Teste real</h2>
                        <p class="mt-1 text-sm text-slate-500">Só libera quando token e número oficial existirem.</p>
                        <div class="mt-4 space-y-3">
                            <input v-model="testForm.to" type="text" placeholder="5511999999999" class="w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                            <textarea v-model="testForm.message" rows="3" class="w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                            <button type="button" :disabled="testForm.processing || !credentials.readyToSend" class="w-full rounded-xl bg-[#00a884] px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50" @click="sendTest">
                                Enviar teste
                            </button>
                        </div>
                    </section>
                </aside>
            </form>
        </div>
    </AdminLayout>
</template>
