<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps({
    partners: { type: Array, default: () => [] },
    availableAbilities: { type: Array, default: () => [] },
});

const abilityLabels = {
    'cadastros.view': 'Ver produtos/cadastros',
    'cadastros.create': 'Criar produtos/cadastros',
    'cadastros.edit': 'Editar produtos/cadastros',
    'cadastros.delete': 'Excluir produtos/cadastros',
    'pedidos.view': 'Ver pedidos/notas/etiquetas',
    'pedidos.edit': 'Editar status de pedidos, emitir nota',
    'estoque.adjust': 'Ajustar estoque',
    'configuracoes.usuarios': 'Gerenciar usuários/permissões',
    'configuracoes.auditoria': 'Ver auditoria',
    'configuracoes.integracoes': 'Gerenciar integrações',
    'relatorios.view': 'Ver relatórios',
    'financeiro.view': 'Ver financeiro',
    'financeiro.create': 'Criar lançamentos financeiros',
    'financeiro.edit': 'Editar financeiro',
    'financeiro.delete': 'Excluir financeiro',
    'operacional.view': 'Ver operacional',
    'operacional.create': 'Criar operacional',
    'operacional.edit': 'Editar operacional',
    'operacional.delete': 'Excluir operacional',
};

// Token em texto puro só existe nesta ÚNICA resposta (Sanctum nunca grava
// o valor real, só o hash) — mostrado num painel FIXO na tela (não um
// toast que some sozinho), com botão de copiar, até o admin fechar
// manualmente. Ver Admin\ApiPartnerController::issueToken().
const page = usePage();
const revealedToken = ref(page.props.flash?.plainTextToken ?? null);
const copied = ref(false);

const copyToken = async () => {
    try {
        await navigator.clipboard.writeText(revealedToken.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Clipboard indisponível (contexto não-seguro, permissão negada)
        // — o token continua selecionável/copiável manualmente no painel.
    }
};

const createForm = useForm({
    name: '',
    contact_email: '',
    password: '',
    abilities: [],
    rate_limit_per_minute: 60,
    notes: '',
});

const createPartner = () => {
    createForm.post('/admin/api-parceiros', {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
};

const editingId = ref(null);
const editForms = reactive({});

const startEditing = (partner) => {
    editingId.value = partner.id;
    editForms[partner.id] = useForm({
        name: partner.name,
        contact_email: partner.contact_email ?? '',
        // Sempre vazio — nunca reexibe a senha atual (só o hash existe no
        // banco). Em branco = "não mexer" (ver ApiPartnerController::update()).
        password: '',
        abilities: [...partner.abilities],
        rate_limit_per_minute: partner.rate_limit_per_minute,
        is_active: partner.is_active,
        notes: '',
    });
};

const saveEdit = (partner) => {
    editForms[partner.id].patch(`/admin/api-parceiros/${partner.id}`, {
        preserveScroll: true,
        onSuccess: () => (editingId.value = null),
    });
};

const issueTokenForm = useForm({ token_name: '' });
const issuingFor = ref(null);

const issueToken = (partner) => {
    issuingFor.value = partner.id;
    issueTokenForm.post(`/admin/api-parceiros/${partner.id}/tokens`, {
        preserveScroll: true,
        onFinish: () => (issuingFor.value = null),
    });
};

const revokeToken = (partner, token) => {
    if (! confirm(`Revogar o token "${token.name}"? Qualquer chamada usando ele passa a ser rejeitada na hora.`)) return;
    router.delete(`/admin/api-parceiros/${partner.id}/tokens/${token.id}`, { preserveScroll: true });
};

const deletePartner = (partner) => {
    if (! confirm(`Remover o parceiro "${partner.name}"? Todos os tokens dele serão revogados.`)) return;
    router.delete(`/admin/api-parceiros/${partner.id}`, { preserveScroll: true });
};

const hasAnyAbility = computed(() => createForm.abilities.length > 0);
</script>

<template>
    <Head title="API de Parceiros" />

    <AdminLayout>
        <h1 class="mb-1 text-2xl font-bold">API de Parceiros</h1>
        <p class="mb-4 text-sm text-slate-400">
            Acesso externo ao sistema via API — cada parceiro só pode fazer o que as abilities dele permitirem. Duas formas de autenticar:
            token estático gerado abaixo, ou login usuário/senha em <code class="rounded bg-[var(--surface)] px-1">POST /api/v1/login</code> (se você definir uma senha) trocado por um JWT de 1h.
            Ver <code class="rounded bg-[var(--surface)] px-1">/api/documentacao</code>, endpoint <code class="rounded bg-[var(--surface)] px-1">GET /api/v1/me</code> pra autoverificação.
        </p>

        <div v-if="revealedToken" class="mb-6 rounded-xl border-2 border-amber-500 bg-amber-50 p-4 dark:bg-amber-500/10">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-amber-800 dark:text-amber-300">
                        <i class="fas fa-triangle-exclamation"></i> Copie o token agora — ele não será mostrado de novo
                    </p>
                    <code class="mt-2 block break-all rounded-lg bg-white p-3 text-sm dark:bg-black/30">{{ revealedToken }}</code>
                </div>
                <div class="flex shrink-0 flex-col gap-2">
                    <button type="button" class="rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700" @click="copyToken">
                        {{ copied ? 'Copiado!' : 'Copiar' }}
                    </button>
                    <button type="button" class="rounded-lg border border-amber-400 px-3 py-1.5 text-sm" @click="revealedToken = null">
                        Fechar
                    </button>
                </div>
            </div>
        </div>

        <div class="mb-6 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <h2 class="mb-3 text-lg font-semibold">Novo parceiro</h2>
            <form class="grid grid-cols-1 gap-4 md:grid-cols-2" @submit.prevent="createPartner">
                <div>
                    <label class="mb-1 block text-sm font-medium">Nome</label>
                    <input v-model="createForm.name" type="text" required
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <p v-if="createForm.errors.name" class="mt-1 text-xs text-error">{{ createForm.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">E-mail de contato (opcional)</label>
                    <input v-model="createForm.contact_email" type="email"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Senha de login (opcional)</label>
                    <input v-model="createForm.password" type="text" minlength="8" placeholder="Deixe em branco pra usar só token estático"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-slate-400">
                        Se definida, o parceiro pode obter um token JWT de 1h em <code class="rounded bg-[var(--surface)] px-1">POST /api/v1/login</code> mandando <code class="rounded bg-[var(--surface)] px-1">usuario</code> = o slug dele (gerado a partir do nome, aparece no card depois de criar) + essa senha.
                    </p>
                    <p v-if="createForm.errors.password" class="mt-1 text-xs text-error">{{ createForm.errors.password }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Limite de requisições/minuto</label>
                    <input v-model.number="createForm.rate_limit_per_minute" type="number" min="1" max="6000"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Observações (opcional)</label>
                    <input v-model="createForm.notes" type="text"
                        class="w-full rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="mb-2 block text-sm font-medium">O que esse parceiro pode acessar</label>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <label v-for="ability in props.availableAbilities" :key="ability" class="flex items-center gap-2 text-sm">
                            <input v-model="createForm.abilities" type="checkbox" :value="ability" class="rounded border-[var(--surface-border)]">
                            {{ abilityLabels[ability] ?? ability }}
                        </label>
                    </div>
                    <p v-if="createForm.errors.abilities" class="mt-1 text-xs text-error">{{ createForm.errors.abilities }}</p>
                </div>
                <div class="md:col-span-2">
                    <button type="submit" :disabled="createForm.processing || !hasAnyAbility"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                        Criar parceiro
                    </button>
                </div>
            </form>
        </div>

        <div v-if="props.partners.length === 0" class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-8 text-center text-sm text-slate-400">
            Nenhum parceiro cadastrado ainda.
        </div>

        <div v-for="partner in props.partners" :key="partner.id" class="mb-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 class="flex items-center gap-2 font-semibold">
                        {{ partner.name }}
                        <span v-if="!partner.is_active" class="rounded-full bg-slate-200 px-2 py-0.5 text-xs text-slate-600 dark:bg-slate-700 dark:text-slate-300">Inativo</span>
                    </h3>
                    <p class="text-xs text-slate-400">
                        usuário (login): <code class="rounded bg-[var(--surface-sunken,theme(colors.slate.100))] px-1">{{ partner.slug }}</code>
                        <span :class="partner.has_password ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400'">
                            · login por senha {{ partner.has_password ? 'ativo' : 'não configurado' }}
                        </span>
                    </p>
                    <p class="text-xs text-slate-400">
                        {{ partner.contact_email || 'Sem e-mail de contato' }} · {{ partner.rate_limit_per_minute }} req/min ·
                        Último uso: {{ partner.last_used_at || 'nunca' }}
                    </p>
                    <div class="mt-2 flex flex-wrap gap-1">
                        <span v-for="ability in partner.abilities" :key="ability" class="rounded-full bg-[var(--surface-sunken,theme(colors.slate.100))] px-2 py-0.5 text-xs">
                            {{ abilityLabels[ability] ?? ability }}
                        </span>
                    </div>
                </div>
                <div class="flex shrink-0 gap-2">
                    <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm hover:bg-[var(--surface-sunken,theme(colors.slate.50))]"
                        @click="startEditing(partner)">
                        Editar
                    </button>
                    <button type="button" :disabled="issuingFor === partner.id"
                        class="rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50"
                        @click="issueToken(partner)">
                        {{ issuingFor === partner.id ? 'Gerando...' : 'Gerar token' }}
                    </button>
                    <button type="button" class="rounded-lg border border-error/40 px-3 py-1.5 text-sm text-error hover:bg-error/10"
                        @click="deletePartner(partner)">
                        Remover
                    </button>
                </div>
            </div>

            <form v-if="editingId === partner.id" class="mt-4 space-y-3 border-t border-[var(--surface-border)] pt-4" @submit.prevent="saveEdit(partner)">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <input v-model="editForms[partner.id].name" type="text" placeholder="Nome"
                        class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <input v-model="editForms[partner.id].contact_email" type="email" placeholder="E-mail de contato"
                        class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <input v-model="editForms[partner.id].password" type="text" minlength="8"
                        :placeholder="partner.has_password ? 'Nova senha (deixe em branco pra manter a atual)' : 'Definir senha de login (opcional)'"
                        class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <input v-model.number="editForms[partner.id].rate_limit_per_minute" type="number" min="1" max="6000" placeholder="Req/min"
                        class="rounded-lg border border-[var(--surface-border)] px-3 py-2 text-sm">
                    <label class="flex items-center gap-2 text-sm">
                        <input v-model="editForms[partner.id].is_active" type="checkbox" class="rounded border-[var(--surface-border)]">
                        Parceiro ativo
                    </label>
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="ability in props.availableAbilities" :key="ability" class="flex items-center gap-2 text-sm">
                        <input v-model="editForms[partner.id].abilities" type="checkbox" :value="ability" class="rounded border-[var(--surface-border)]">
                        {{ abilityLabels[ability] ?? ability }}
                    </label>
                </div>
                <p class="text-xs text-slate-400">Mudar as abilities aqui não afeta tokens já emitidos — gere um token novo pra aplicar.</p>
                <div class="flex gap-2">
                    <button type="submit" :disabled="editForms[partner.id].processing"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                        Salvar
                    </button>
                    <button type="button" class="rounded-lg border border-[var(--surface-border)] px-4 py-2 text-sm" @click="editingId = null">
                        Cancelar
                    </button>
                </div>
            </form>

            <div v-if="partner.tokens.length > 0" class="mt-3 border-t border-[var(--surface-border)] pt-3">
                <p class="mb-2 text-xs font-medium text-slate-400">Tokens ativos ({{ partner.tokens_count }})</p>
                <div v-for="token in partner.tokens" :key="token.id" class="flex items-center justify-between py-1 text-sm">
                    <span>{{ token.name }} <span class="text-xs text-slate-400">— criado {{ token.created_at }}, último uso: {{ token.last_used_at || 'nunca' }}</span></span>
                    <button type="button" class="text-xs text-error hover:underline" @click="revokeToken(partner, token)">Revogar</button>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
