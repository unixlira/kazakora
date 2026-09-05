<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    settings: { type: Object, required: true },
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

const simulatedCustomer = ref('Essa campainha com câmera funciona pelo celular?');
const copied = ref(null);

const credentialItems = computed(() => [
    { key: 'accessToken', label: 'WhatsApp Access Token', description: 'Token protegido no .env; nunca é exibido no painel.' },
    { key: 'phoneNumberId', label: 'Phone Number ID', description: 'Identifica o número oficial que envia e recebe mensagens.' },
    { key: 'businessAccountId', label: 'WABA ID', description: 'Identifica a conta comercial da Meta/WhatsApp.' },
    { key: 'appSecret', label: 'App Secret', description: 'Reforça a validação de assinatura dos webhooks recebidos.' },
]);

const integrationStatus = computed(() => {
    if (!form.enabled) return { label: 'Recebimento pausado', class: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200' };
    if (!props.credentials.readyToSend) return { label: 'Credenciais incompletas', class: 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' };
    if (!form.auto_reply_enabled) return { label: 'Recebe sem responder', class: 'bg-sky-100 text-sky-800 dark:bg-sky-500/10 dark:text-sky-300' };
    return { label: 'Manuela ativa', class: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' };
});

const preview = computed(() => {
    const text = simulatedCustomer.value.toLowerCase();
    let intent = 'Dúvida geral';
    let reply = form.welcome_message;
    let nextAction = 'Fazer uma pergunta curta';
    let needsHuman = false;

    if (text.includes('frete') || text.includes('prazo') || text.includes('cep')) {
        intent = 'Frete e prazo';
        reply = 'Consigo te ajudar com o prazo. Me manda seu CEP, por favor, que eu confiro o caminho mais seguro pra entrega.';
    } else if (text.includes('desconto') || text.includes('preço') || text.includes('valor')) {
        intent = 'Preço ou desconto';
        reply = 'Consigo te orientar pelo melhor caminho de compra. Você pensa em pegar uma unidade ou mais de uma?';
    } else if (text.includes('pedido') || text.includes('rastreamento')) {
        intent = 'Status de pedido';
        reply = 'Me manda o número do pedido, por favor. Com ele eu consigo localizar e te responder com mais segurança.';
    } else if (text.includes('garantia') || text.includes('defeito') || text.includes('troca')) {
        intent = 'Troca ou garantia';
        reply = 'Vou te orientar com cuidado. Me manda o número do pedido e uma foto ou vídeo curto mostrando o problema, por favor.';
        needsHuman = true;
        nextAction = 'Sinalizar para humano';
    } else if (text.includes('comprar') || text.includes('quero') || text.includes('manda o link')) {
        intent = 'Intenção de compra';
        reply = form.closing_template;
        nextAction = 'Sugerir próximo passo de compra';
    } else if (text.includes('funciona') || text.includes('serve') || text.includes('voltagem') || text.includes('celular')) {
        intent = 'Dúvida de produto';
        reply = 'Funciona sim em muitos modelos, mas pra eu não te passar informação errada: você quer usar no celular Android ou iPhone?';
    }

    return { intent, reply, nextAction, needsHuman };
});

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
            <section class="overflow-hidden rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm sm:rounded-3xl">
                <div class="relative overflow-hidden bg-slate-950 px-4 py-6 text-white sm:px-6 sm:py-8 md:px-8">
                    <div class="absolute right-0 top-0 h-40 w-40 rounded-full bg-emerald-400/20 blur-3xl sm:h-48 sm:w-48"></div>
                    <div class="relative flex min-w-0 flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div class="min-w-0 max-w-3xl">
                            <div class="mb-4 inline-flex max-w-full items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-emerald-100 sm:text-xs sm:tracking-[0.22em]">
                                <i class="fab fa-whatsapp"></i>
                                <span class="truncate">Atendimento oficial</span>
                            </div>
                            <h1 class="font-display text-2xl font-semibold sm:text-3xl md:text-4xl">Configuração do WhatsApp</h1>
                            <p class="mt-3 text-sm leading-6 text-slate-300 md:text-base">
                                Controle o webhook oficial da Meta, a personalidade da Manuela e as regras de resposta automática da KazaKora sem expor tokens sensíveis.
                            </p>
                        </div>
                        <div class="w-full rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur sm:w-auto sm:min-w-64">
                            <span class="inline-flex max-w-full rounded-full px-3 py-1 text-xs font-semibold" :class="integrationStatus.class">
                                {{ integrationStatus.label }}
                            </span>
                            <p class="mt-3 text-xs text-slate-300">
                                {{ stats.conversations }} conversa(s) registradas · {{ stats.needsHuman }} precisam de humano
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <form class="min-w-0 space-y-6" @submit.prevent="submit">
                <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <div class="flex min-w-0 flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold">Integração WhatsApp Business</h2>
                                <p class="mt-1 text-sm text-slate-500">
                                    Use estes dados no painel da Meta para validar o recebimento oficial das mensagens.
                                </p>
                            </div>
                            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                API oficial
                            </span>
                        </div>

                        <div class="mt-5 space-y-4">
                            <div>
                                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">URL de callback</label>
                                <div class="mt-2 flex flex-col gap-2 rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)] p-3 md:flex-row md:items-center">
                                    <code class="flex-1 break-all text-sm text-slate-700 dark:text-slate-200">{{ requestedCallbackUrl }}</code>
                                    <button type="button" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700" @click="copyToClipboard(requestedCallbackUrl, 'callback')">
                                        {{ copied === 'callback' ? 'Copiado' : 'Copiar URL' }}
                                    </button>
                                </div>
                                <p class="mt-2 text-xs text-slate-500">Cole esta URL no painel da Meta em Callback URL. O endpoint também aceita o alias compatível <code>/api/whatsapp/webhook</code>.</p>
                            </div>

                            <div>
                                <label class="text-sm font-semibold text-slate-700 dark:text-slate-200">Token de verificação</label>
                                <div class="mt-2 flex flex-col gap-2 md:flex-row">
                                    <input v-model="form.verify_token" type="text" class="flex-1 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" autocomplete="off">
                                    <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-xs font-semibold hover:bg-[var(--surface-muted)]" @click="copyToClipboard(form.verify_token, 'verify')">
                                        {{ copied === 'verify' ? 'Copiado' : 'Copiar token' }}
                                    </button>
                                </div>
                                <p class="mt-2 text-xs text-amber-600">Se alterar este token, atualize o mesmo valor no painel da Meta antes de testar o webhook.</p>
                                <p v-if="form.errors.verify_token" class="mt-1 text-xs text-error">{{ form.errors.verify_token }}</p>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                        <h2 class="text-lg font-semibold">Credenciais do ambiente</h2>
                        <p class="mt-1 text-sm text-slate-500">Tokens sensíveis ficam protegidos no .env. Esta tela mostra apenas o status.</p>

                        <div class="mt-5 space-y-3">
                            <div v-for="item in credentialItems" :key="item.key" class="rounded-xl border border-[var(--surface-border)] p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold">{{ item.label }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ item.description }}</p>
                                    </div>
                                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="credentials[item.key] ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'">
                                        {{ credentials[item.key] ? 'Configurado' : 'Ausente' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">
                            Por segurança, o access token não é exibido nem copiado por esta tela. Quando você colocar os tokens no cofre, eu atualizo o servidor sem expor os valores.
                        </div>
                    </section>
                </div>

                <section class="grid min-w-0 gap-6 lg:grid-cols-3">
                    <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6 lg:col-span-1">
                        <h2 class="text-lg font-semibold">Funcionamento</h2>
                        <p class="mt-1 text-sm text-slate-500">Defina se o sistema recebe mensagens e se a Manuela responde sozinha.</p>

                        <div class="mt-5 space-y-4">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4">
                                <input v-model="form.enabled" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary">
                                <span>
                                    <span class="block text-sm font-semibold">Receber mensagens do WhatsApp</span>
                                    <span class="mt-1 block text-xs text-slate-500">As mensagens do número oficial serão registradas no sistema.</span>
                                </span>
                            </label>

                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4" :class="!form.enabled ? 'opacity-60' : ''">
                                <input v-model="form.auto_reply_enabled" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary" :disabled="!form.enabled">
                                <span>
                                    <span class="block text-sm font-semibold">Permitir resposta automática da Manuela</span>
                                    <span class="mt-1 block text-xs text-slate-500">Ela responde conforme as regras abaixo. Se o caso for sensível, sinaliza para humano.</span>
                                </span>
                            </label>

                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-[var(--surface-border)] p-4">
                                <input v-model="form.sandbox_mode" type="checkbox" class="mt-1 h-4 w-4 rounded accent-primary">
                                <span>
                                    <span class="block text-sm font-semibold">Modo cauteloso</span>
                                    <span class="mt-1 block text-xs text-slate-500">Mantém respostas conservadoras enquanto a operação é calibrada.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6 lg:col-span-2">
                        <h2 class="text-lg font-semibold">Atendente Manuela</h2>
                        <p class="mt-1 text-sm text-slate-500">A Manuela entende antes de vender. Ela conversa como pessoa, faz poucas perguntas e só avança quando fizer sentido.</p>

                        <div class="mt-5 grid gap-4 md:grid-cols-2">
                            <div>
                                <label class="text-sm font-semibold">Nome público da atendente</label>
                                <input v-model="form.attendant_name" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                <p v-if="form.errors.attendant_name" class="mt-1 text-xs text-error">{{ form.errors.attendant_name }}</p>
                            </div>
                            <div>
                                <label class="text-sm font-semibold">Marca atendida</label>
                                <input v-model="form.brand_name" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="text-sm font-semibold">Tom de voz</label>
                                <select v-model="form.tone" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                    <option value="humano">Humano e cordial</option>
                                    <option value="consultivo">Consultivo e objetivo</option>
                                    <option value="proximo_sem_pressao">Próximo, sem pressão</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-sm font-semibold">Perguntas antes do fechamento</label>
                                <select v-model="form.max_questions_before_close" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                    <option :value="1">1 pergunta · mais direto</option>
                                    <option :value="2">2 perguntas · recomendado</option>
                                    <option :value="3">3 perguntas · mais cuidadoso</option>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="text-sm font-semibold">URL base da loja</label>
                                <input v-model="form.store_base_url" type="url" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="grid min-w-0 gap-6 xl:grid-cols-2">
                    <div class="space-y-6">
                        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                            <h2 class="text-lg font-semibold">Mensagens-base</h2>
                            <p class="mt-1 text-sm text-slate-500">Mensagens curtas, úteis e sem prometer desconto, prazo ou estoque antes de consultar dados.</p>
                            <div class="mt-5 space-y-4">
                                <div>
                                    <label class="text-sm font-semibold">Mensagem de boas-vindas</label>
                                    <textarea v-model="form.welcome_message" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Horário com suporte humano</label>
                                    <input v-model="form.business_hours" type="text" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Resposta fora de horário</label>
                                    <textarea v-model="form.outside_hours_message" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Fechamento consultivo</label>
                                    <textarea v-model="form.closing_template" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                    <p class="mt-2 text-xs text-slate-500">Convide para o próximo passo sem urgência falsa ou pressão agressiva.</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                            <h2 class="text-lg font-semibold">Limites e handoff</h2>
                            <p class="mt-1 text-sm text-slate-500">Quando houver risco, exceção de política ou insatisfação forte, a Manuela para e chama o time humano.</p>
                            <div class="mt-5 space-y-4">
                                <div>
                                    <label class="text-sm font-semibold">Situações que exigem humano</label>
                                    <textarea v-model="form.handoff_keywords" rows="4" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                    <p class="mt-2 text-xs text-slate-500">Separe por vírgula. Inclua reclamação, reembolso, defeito, jurídico e pedido explícito por atendente.</p>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Categorias prioritárias</label>
                                    <textarea v-model="form.priority_categories" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                    <p class="mt-2 text-xs text-slate-500">Prioridade comercial não substitui adequação ao cliente.</p>
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Promessas proibidas</label>
                                    <textarea v-model="form.forbidden_promises" rows="4" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                    <p class="mt-2 text-xs text-amber-600">A Manuela nunca deve pedir senha, código de autenticação, cartão completo, token ou documento completo pelo WhatsApp.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-6">
                        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                            <h2 class="text-lg font-semibold">Prévia da conversa</h2>
                            <p class="mt-1 text-sm text-slate-500">Teste o tom antes de ativar. Esta prévia não envia mensagem real.</p>
                            <textarea v-model="simulatedCustomer" rows="3" class="mt-5 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm" placeholder="Mensagem simulada do cliente"></textarea>

                            <div class="mt-5 overflow-hidden rounded-2xl bg-[#0b141a] p-3 text-white shadow-inner sm:p-4">
                                <div class="ml-auto max-w-[85%] break-words rounded-2xl rounded-tr-sm bg-[#005c4b] px-3 py-3 text-sm sm:px-4">
                                    {{ simulatedCustomer }}
                                </div>
                                <div class="mt-3 max-w-[90%] break-words rounded-2xl rounded-tl-sm bg-[#202c33] px-3 py-3 text-sm leading-6 sm:px-4">
                                    {{ preview.reply }}
                                </div>
                            </div>

                            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                <div class="rounded-xl bg-[var(--surface-muted)] p-3">
                                    <p class="text-xs text-slate-500">Intenção</p>
                                    <p class="mt-1 text-sm font-semibold">{{ preview.intent }}</p>
                                </div>
                                <div class="rounded-xl bg-[var(--surface-muted)] p-3">
                                    <p class="text-xs text-slate-500">Humano?</p>
                                    <p class="mt-1 text-sm font-semibold">{{ preview.needsHuman ? 'Sim' : 'Não' }}</p>
                                </div>
                                <div class="rounded-xl bg-[var(--surface-muted)] p-3">
                                    <p class="text-xs text-slate-500">Próxima ação</p>
                                    <p class="mt-1 text-sm font-semibold">{{ preview.nextAction }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm sm:p-6">
                            <h2 class="text-lg font-semibold">Teste de envio real</h2>
                            <p class="mt-1 text-sm text-slate-500">Disponível quando o access token e o Phone Number ID estiverem configurados no servidor.</p>
                            <div class="mt-5 space-y-4">
                                <div>
                                    <label class="text-sm font-semibold">Número com DDI</label>
                                    <input v-model="testForm.to" type="text" placeholder="5511999999999" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="text-sm font-semibold">Mensagem</label>
                                    <textarea v-model="testForm.message" rows="3" class="mt-2 w-full rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm"></textarea>
                                </div>
                                <button type="button" :disabled="testForm.processing || !credentials.readyToSend" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" @click="sendTest">
                                    Enviar teste
                                </button>
                                <p v-if="!credentials.readyToSend" class="text-xs text-amber-600">Ainda falta token real no .env. O envio será liberado depois que você colocar os acessos no cofre e eu atualizar o servidor.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <div class="sticky bottom-4 z-10 rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)]/95 p-4 shadow-lg backdrop-blur">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-sm font-semibold">Salvar regras da Manuela</p>
                            <p class="text-xs text-slate-500">As alterações afetam apenas as próximas conversas. Mensagens antigas não serão alteradas.</p>
                        </div>
                        <button type="submit" :disabled="form.processing" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                            {{ form.processing ? 'Salvando...' : 'Salvar configurações' }}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>

