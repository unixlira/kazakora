<script setup>
import { useDarkMode } from '@/Shared/useDarkMode';
import { usePermissions } from '@/Shared/usePermissions';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Tela de Conversas do WhatsApp oficial, igual ao WhatsApp Web (pedido
// explícito 2026-10-08: "igual ao WhatsApp Web, inclusive o papel de
// parede"). Ocupa a tela inteira, fora do AdminLayout, com a barra de
// ícones à esquerda pra voltar ao admin.
//
// Atualiza sozinha por polling (3s com a aba visível, 15s escondida) —
// ver WhatsAppInboxController::updates(). Cada conversa tem a chave
// "Manuela": ligada, a Manuela responde; desligada, só humanos.

const props = defineProps({
    conversations: { type: Array, required: true },
    serverTime: { type: String, required: true },
    status: { type: Object, required: true },
});

const page = usePage();
const { can } = usePermissions();
const { isDark, toggle: toggleTheme } = useDarkMode();

const POLL_VISIBLE_MS = 3000;
const POLL_HIDDEN_MS = 15000;

const conversations = ref([...props.conversations]);
const activeId = ref(null);
const messages = ref([]);
const hasMore = ref(false);
const loadingMessages = ref(false);
const loadingOlder = ref(false);
const search = ref('');
const filter = ref('all');
const draft = ref('');
const sending = ref(false);
const actionError = ref(null);
const showNewBelow = ref(false);
const soundOn = ref(readSoundPref());
const connectionLost = ref(false);
const drawerOpen = ref(false);
const menuOpen = ref(false);
const emojiOpen = ref(false);
const toggling = ref(new Set());

const messagesBox = ref(null);
const composer = ref(null);

let since = props.serverTime;
let pollTimer = null;
let polling = false;
let originalTitle = '';

const attendant = props.status.attendantName || 'Manuela';
const user = computed(() => page.props.auth?.user ?? null);

const filters = [
    { key: 'all', label: 'Tudo' },
    { key: 'unread', label: 'Não lidas' },
    { key: 'human', label: 'Precisa de humano' },
    { key: 'manuela', label: attendant },
    { key: 'resolved', label: 'Encerradas' },
];

const humanCount = computed(() => conversations.value.filter((c) => c.needsHuman).length);

const EMOJIS = ['😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😉', '🙂', '🤗', '🤩', '😎', '🤔', '😅', '😇', '🥰',
    '😢', '😭', '😡', '😱', '🙏', '👍', '👎', '👏', '🙌', '👌', '✌️', '🤝', '💪', '👋', '❤️', '🧡',
    '💛', '💚', '💙', '💜', '🔥', '✨', '⭐', '🎉', '🎁', '📦', '🚚', '🛒', '🛍️', '💳', '💰', '🏷️',
    '📍', '📞', '📷', '✅', '❌', '⚠️', '⏰', '📅', '😴', '🤞', '😬', '🥲', '😋', '🤤', '🙄', '😌'];

const active = computed(() => conversations.value.find((c) => c.id === activeId.value) ?? null);

const sortedConversations = computed(() =>
    [...conversations.value].sort((a, b) => (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? '') || b.id - a.id),
);

const visibleConversations = computed(() => {
    const term = search.value.trim().toLowerCase();
    const digits = term.replace(/\D/g, '');

    return sortedConversations.value.filter((c) => {
        if (filter.value === 'unread' && !c.unread) return false;
        if (filter.value === 'human' && !c.needsHuman) return false;
        if (filter.value === 'manuela' && (!c.aiEnabled || c.status === 'resolved')) return false;
        if (filter.value === 'resolved' && c.status !== 'resolved') return false;
        if (filter.value !== 'resolved' && filter.value !== 'all' && c.status === 'resolved') return false;
        if (!term) return true;

        return (c.name ?? '').toLowerCase().includes(term)
            || (digits && (c.phone ?? '').includes(digits))
            || (c.preview ?? '').toLowerCase().includes(term);
    });
});

const totalUnread = computed(() => conversations.value.filter((c) => c.unread > 0).length);

// Mensagens agrupadas por dia (chip de data) e em sequência do mesmo
// remetente: só a primeira da sequência tem o "rabinho", igual ao WhatsApp.
const timeline = computed(() => {
    const items = [];
    let lastDay = null;
    let previous = null;

    for (const message of messages.value) {
        const day = message.at ? new Date(message.at).toDateString() : null;
        if (day && day !== lastDay) {
            items.push({ kind: 'day', key: `d-${message.id}`, label: dayLabel(message.at) });
            lastDay = day;
            previous = null;
        }
        const first = !previous || previous.direction !== message.direction || previous.sentBy !== message.sentBy;
        items.push({ kind: 'message', key: `m-${message.id}`, message, first });
        previous = message;
    }

    return items;
});

const composerBlocked = computed(() => {
    if (!active.value) return null;
    if (!props.status.readyToSend) return 'Credenciais da Meta ainda não configuradas: a mensagem fica salva aqui, mas não é entregue.';
    if (!active.value.insideWindow) return 'Fora da janela de 24h da Meta: o cliente precisa escrever primeiro (ou use um modelo aprovado em Disparos).';
    return null;
});

watch(totalUnread, (count) => {
    document.title = count ? `(${count}) ${originalTitle}` : originalTitle;
});

onMounted(() => {
    originalTitle = document.title.replace(/^\(\d+\)\s*/, '');
    const fromUrl = Number(new URLSearchParams(window.location.search).get('c'));
    if (fromUrl && conversations.value.some((c) => c.id === fromUrl)) openConversation(fromUrl);
    document.addEventListener('visibilitychange', onVisibility);
    document.addEventListener('keydown', onGlobalKey);
    schedulePoll(POLL_VISIBLE_MS);
});

onBeforeUnmount(() => {
    clearTimeout(pollTimer);
    document.removeEventListener('visibilitychange', onVisibility);
    document.removeEventListener('keydown', onGlobalKey);
    document.title = originalTitle;
});

function onVisibility() {
    if (document.visibilityState === 'visible') {
        clearTimeout(pollTimer);
        poll();
    }
}

function onGlobalKey(event) {
    if (event.key !== 'Escape') return;
    if (emojiOpen.value) emojiOpen.value = false;
    else if (menuOpen.value) menuOpen.value = false;
    else if (drawerOpen.value) drawerOpen.value = false;
    else if (active.value) closeConversation();
}

function schedulePoll(ms) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
}

async function poll() {
    if (polling) return;
    polling = true;

    try {
        const params = new URLSearchParams({ since });
        if (activeId.value) params.set('conversation', activeId.value);

        const data = await request(`/admin/whatsapp/conversas/atualizacoes?${params}`);
        since = data.serverTime;
        connectionLost.value = false;

        let newInbound = false;
        for (const conversation of data.conversations) {
            const previous = conversations.value.find((c) => c.id === conversation.id);
            if (conversation.unread > (previous?.unread ?? 0) && conversation.id !== activeId.value) newInbound = true;
            upsertConversation(conversation);
        }

        if (data.messages.length && activeId.value) {
            const nearBottom = isNearBottom();
            const added = mergeMessages(data.messages);
            if (added.some((m) => m.direction === 'inbound')) {
                if (document.visibilityState !== 'visible') newInbound = true;
                markRead(activeId.value);
            }
            if (added.length) {
                if (nearBottom) scrollToBottom();
                else showNewBelow.value = true;
            }
        }

        if (newInbound) beep();
    } catch {
        connectionLost.value = true;
    } finally {
        polling = false;
        schedulePoll(document.visibilityState === 'visible' ? POLL_VISIBLE_MS : POLL_HIDDEN_MS);
    }
}

function upsertConversation(conversation) {
    const index = conversations.value.findIndex((c) => c.id === conversation.id);
    // Chave sendo trocada agora: não deixa um poll antigo desfazer o clique.
    const keep = toggling.value.has(conversation.id) && index !== -1 ? { aiEnabled: conversations.value[index].aiEnabled } : {};
    if (index === -1) conversations.value.push(conversation);
    else conversations.value[index] = { ...conversations.value[index], ...conversation, ...keep };

    if (conversation.id === activeId.value && conversation.unread > 0 && document.visibilityState === 'visible') {
        conversations.value.find((c) => c.id === conversation.id).unread = 0;
    }
}

// Junta mensagens novas/atualizadas (status ✓ → ✓✓ → azul) por id.
function mergeMessages(incoming) {
    const added = [];
    for (const message of incoming) {
        const index = messages.value.findIndex((m) => m.id === message.id);
        if (index === -1) {
            messages.value.push(message);
            added.push(message);
        } else {
            messages.value[index] = message;
        }
    }
    if (added.length) messages.value.sort((a, b) => a.id - b.id);
    return added;
}

async function openConversation(id) {
    if (activeId.value === id) return;

    activeId.value = id;
    messages.value = [];
    hasMore.value = false;
    draft.value = '';
    actionError.value = null;
    showNewBelow.value = false;
    menuOpen.value = false;
    emojiOpen.value = false;
    loadingMessages.value = true;

    const url = new URL(window.location.href);
    url.searchParams.set('c', id);
    window.history.replaceState(window.history.state, '', url);

    try {
        const data = await request(`/admin/whatsapp/conversas/${id}/mensagens`);
        if (activeId.value !== id) return;
        messages.value = data.messages;
        hasMore.value = data.hasMore;
        upsertConversation(data.conversation);
        await scrollToBottom();
        markRead(id);
        nextTick(() => composer.value?.focus());
    } catch {
        actionError.value = 'Não consegui carregar essa conversa. Tente de novo.';
    } finally {
        loadingMessages.value = false;
    }
}

function closeConversation() {
    activeId.value = null;
    drawerOpen.value = false;
    menuOpen.value = false;
    const url = new URL(window.location.href);
    url.searchParams.delete('c');
    window.history.replaceState(window.history.state, '', url);
}

async function loadOlder() {
    if (!messages.value.length || loadingOlder.value) return;
    loadingOlder.value = true;
    const box = messagesBox.value;
    const previousHeight = box?.scrollHeight ?? 0;

    try {
        const id = activeId.value;
        const data = await request(`/admin/whatsapp/conversas/${id}/mensagens?before=${messages.value[0].id}`);
        if (activeId.value !== id) return;
        messages.value = [...data.messages, ...messages.value];
        hasMore.value = data.hasMore;
        await nextTick();
        if (box) box.scrollTop = box.scrollHeight - previousHeight;
    } finally {
        loadingOlder.value = false;
    }
}

async function markRead(id) {
    const conversation = conversations.value.find((c) => c.id === id);
    if (conversation) conversation.unread = 0;
    try {
        await request(`/admin/whatsapp/conversas/${id}/lida`, { method: 'POST' });
    } catch {
        // Sem problema: o próximo poll acerta o contador.
    }
}

async function send() {
    const body = draft.value.trim();
    if (!body || sending.value || !active.value) return;

    sending.value = true;
    actionError.value = null;
    emojiOpen.value = false;
    const id = active.value.id;

    try {
        const data = await request(`/admin/whatsapp/conversas/${id}/mensagens`, { method: 'POST', body: { body } });
        draft.value = '';
        resizeComposer();
        mergeMessages([data.message]);
        upsertConversation(data.conversation);
        scrollToBottom();
        if (data.message.status === 'failed') actionError.value = 'A Meta recusou a mensagem. Passe o mouse no ícone vermelho pra ver o motivo.';
    } catch (error) {
        actionError.value = error.message || 'Não foi possível enviar.';
    } finally {
        sending.value = false;
        nextTick(() => composer.value?.focus());
    }
}

// Chave seletora de cada conversa: ligada = Manuela responde,
// desligada = só humanos. Muda na hora na tela e confirma no servidor.
async function toggleManuela(conversation) {
    if (!conversation || toggling.value.has(conversation.id)) return;

    const target = conversations.value.find((c) => c.id === conversation.id);
    const next = !target.aiEnabled;
    target.aiEnabled = next;
    toggling.value = new Set(toggling.value).add(target.id);

    try {
        const data = await request(`/admin/whatsapp/conversas/${target.id}/manuela`, {
            method: 'POST',
            body: { ai_enabled: next },
        });
        const done = new Set(toggling.value);
        done.delete(target.id);
        toggling.value = done;
        upsertConversation(data.conversation);
    } catch (error) {
        target.aiEnabled = !next;
        actionError.value = error.message;
        const done = new Set(toggling.value);
        done.delete(target.id);
        toggling.value = done;
    }
}

async function deleteConversation() {
    const target = active.value;
    menuOpen.value = false;
    if (!target || !window.confirm(`Apagar a conversa com ${target.name || target.phone}? As mensagens somem daqui (o cliente continua com elas no celular).`)) return;

    try {
        await request(`/admin/whatsapp/conversas/${target.id}`, { method: 'DELETE' });
        closeConversation();
        conversations.value = conversations.value.filter((c) => c.id !== target.id);
    } catch (error) {
        actionError.value = error.message;
    }
}

async function toggleResolved() {
    if (!active.value) return;
    menuOpen.value = false;
    try {
        const data = await request(`/admin/whatsapp/conversas/${active.value.id}/status`, {
            method: 'POST',
            body: { status: active.value.status === 'resolved' ? 'open' : 'resolved' },
        });
        upsertConversation(data.conversation);
    } catch (error) {
        actionError.value = error.message;
    }
}

function insertEmoji(emoji) {
    const el = composer.value;
    const start = el?.selectionStart ?? draft.value.length;
    const end = el?.selectionEnd ?? draft.value.length;
    draft.value = draft.value.slice(0, start) + emoji + draft.value.slice(end);
    nextTick(() => {
        el?.focus();
        el?.setSelectionRange(start + emoji.length, start + emoji.length);
        resizeComposer();
    });
}

function onComposerKey(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
    }
}

function resizeComposer() {
    nextTick(() => {
        const el = composer.value;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 168)}px`;
    });
}

function isNearBottom() {
    const box = messagesBox.value;
    if (!box) return true;
    return box.scrollHeight - box.scrollTop - box.clientHeight < 120;
}

async function scrollToBottom() {
    await nextTick();
    const box = messagesBox.value;
    if (box) box.scrollTop = box.scrollHeight;
    showNewBelow.value = false;
}

function onMessagesScroll() {
    if (isNearBottom()) showNewBelow.value = false;
}

async function request(url, { method = 'GET', body } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(method !== 'GET' ? { 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        let message = `Erro ${response.status}`;
        try {
            const data = await response.json();
            message = data.message || message;
        } catch {
            // resposta sem JSON
        }
        if (response.status === 419) message = 'Sessão expirada. Recarregue a página.';
        throw new Error(message);
    }

    return response.json();
}

function cookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : '';
}

function readSoundPref() {
    try {
        return localStorage.getItem('kz-wa-sound') !== '0';
    } catch {
        return true;
    }
}

function toggleSound() {
    soundOn.value = !soundOn.value;
    try {
        localStorage.setItem('kz-wa-sound', soundOn.value ? '1' : '0');
    } catch {
        // navegador sem storage
    }
}

let audioContext = null;
function beep() {
    if (!soundOn.value) return;
    try {
        audioContext ??= new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gain = audioContext.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, audioContext.currentTime);
        oscillator.frequency.setValueAtTime(1320, audioContext.currentTime + 0.09);
        gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.15, audioContext.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.25);
        oscillator.connect(gain).connect(audioContext.destination);
        oscillator.start();
        oscillator.stop(audioContext.currentTime + 0.26);
    } catch {
        // navegador bloqueou áudio antes de interação
    }
}

const AVATAR_COLORS = ['#25d366', '#53bdeb', '#ff8a65', '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#60a5fa'];

function avatarColor(conversation) {
    return AVATAR_COLORS[(conversation?.id ?? 0) % AVATAR_COLORS.length];
}

function initials(conversation) {
    const name = (conversation?.name ?? '').trim();
    if (!name) return null;
    const parts = name.split(/\s+/);
    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function displayName(conversation) {
    return conversation?.name || formatPhone(conversation?.phone);
}

function formatPhone(phone) {
    const digits = String(phone ?? '').replace(/\D/g, '');
    const match = digits.match(/^55(\d{2})(\d{4,5})(\d{4})$/);
    return match ? `+55 ${match[1]} ${match[2]}-${match[3]}` : (digits ? `+${digits}` : 'Sem número');
}

function listTime(iso) {
    if (!iso) return '';
    const date = new Date(iso);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (date.toDateString() === today.toDateString()) return hourMinute(iso);
    if (date.toDateString() === yesterday.toDateString()) return 'Ontem';
    if (today - date < 6 * 86400000) return date.toLocaleDateString('pt-BR', { weekday: 'long' });
    return date.toLocaleDateString('pt-BR');
}

function dayLabel(iso) {
    const label = listTime(iso);
    if (/^\d{2}:\d{2}$/.test(label)) return 'Hoje';
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function hourMinute(iso) {
    return iso ? new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) : '';
}

function tickTitle(message) {
    return {
        read: 'Lida',
        delivered: 'Entregue',
        sent: 'Enviada',
        failed: message.error || 'Falhou',
        draft_no_token: 'Não enviada: falta o token da Meta',
    }[message.status] ?? 'Enviando';
}

function mediaUrl(message) {
    return `/admin/whatsapp/conversas/midia/${message.id}`;
}

function linkify(text) {
    const escaped = String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    return escaped
        .replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" class="wa-link">$1</a>')
        .replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>')
        .replace(/_([^_\n]+)_/g, '<em>$1</em>')
        .replace(/~([^~\n]+)~/g, '<s>$1</s>');
}
</script>

<template>
    <Head title="Conversas" />

    <div class="wa-app fixed inset-0 z-40 flex overflow-hidden">
        <!-- Barra de ícones (igual à coluna da esquerda do WhatsApp Web) -->
        <nav class="wa-rail hidden w-16 shrink-0 flex-col items-center justify-between py-3 md:flex">
            <div class="flex flex-col items-center gap-2">
                <button type="button" class="wa-rail-btn wa-rail-btn-active relative" title="Conversas" @click="closeConversation">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"><path d="M4.5 19.5l1.1-3.6A8 8 0 1 1 8.4 19z" /></svg>
                    <span v-if="totalUnread" class="wa-rail-badge">{{ totalUnread }}</span>
                </button>
                <Link href="/admin" class="wa-rail-btn" title="Voltar ao painel">
                    <i class="fas fa-table-columns text-[19px]"></i>
                </Link>
                <Link href="/admin/whatsapp/disparos" class="wa-rail-btn" title="Disparos">
                    <i class="fas fa-bullhorn text-[18px]"></i>
                </Link>
            </div>
            <div class="flex flex-col items-center gap-2">
                <button type="button" class="wa-rail-btn" :title="soundOn ? 'Som de nova mensagem: ligado' : 'Som de nova mensagem: desligado'" @click="toggleSound">
                    <i :class="soundOn ? 'fas fa-bell' : 'fas fa-bell-slash'" class="text-[18px]"></i>
                </button>
                <button type="button" class="wa-rail-btn" :title="isDark ? 'Tema claro' : 'Tema escuro'" @click="toggleTheme">
                    <i :class="isDark ? 'fas fa-sun' : 'fas fa-moon'" class="text-[18px]"></i>
                </button>
                <Link v-if="can('*')" href="/admin/whatsapp" class="wa-rail-btn" title="Configurações do WhatsApp">
                    <i class="fas fa-gear text-[19px]"></i>
                </Link>
                <span class="mt-1 flex h-10 w-10 items-center justify-center overflow-hidden rounded-full bg-[#dfe5e7] text-sm font-semibold text-[#54656f] dark:bg-[#6a7175] dark:text-white" :title="user?.name">
                    <img v-if="user?.avatar_url" :src="user.avatar_url" alt="" class="h-full w-full object-cover">
                    <template v-else>{{ user?.initials }}</template>
                </span>
            </div>
        </nav>

        <!-- Lista de conversas -->
        <aside
            class="wa-side min-w-0 flex-col md:flex md:w-[30%] md:min-w-[340px] md:max-w-[460px] md:shrink-0"
            :class="active ? 'hidden' : 'flex w-full'"
        >
            <header class="flex h-16 shrink-0 items-center justify-between pl-5 pr-3">
                <div class="flex min-w-0 items-center gap-2">
                    <Link href="/admin" class="wa-icon-btn inline-flex -ml-2 md:hidden" title="Voltar ao painel"><i class="fas fa-arrow-left"></i></Link>
                    <h1 class="wa-title truncate">Conversas</h1>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" class="wa-icon-btn inline-flex md:hidden" :title="isDark ? 'Tema claro' : 'Tema escuro'" @click="toggleTheme">
                        <i :class="isDark ? 'fas fa-sun' : 'fas fa-moon'"></i>
                    </button>
                    <span class="wa-icon-btn inline-flex cursor-default" :title="connectionLost ? 'Sem conexão, tentando de novo…' : 'Atualizando em tempo real'">
                        <span class="h-2.5 w-2.5 rounded-full" :class="connectionLost ? 'bg-red-500' : 'bg-[#25d366]'"></span>
                    </span>
                </div>
            </header>

            <div v-if="!status.readyToSend || !status.autoReply" class="shrink-0 px-3 pb-2">
                <div v-if="!status.readyToSend" class="wa-banner wa-banner-warn">
                    <i class="fas fa-key"></i>
                    <span>Falta o token da Meta: dá pra ver e responder aqui, mas nada é entregue ainda.</span>
                </div>
                <div v-else class="wa-banner wa-banner-info">
                    <i class="fas fa-robot"></i>
                    <span>Conversas novas começam com a {{ attendant }} desligada. Ligue a chave na conversa, ou ative "Resposta automática" nas configurações pra todas começarem com ela.</span>
                </div>
            </div>

            <div class="shrink-0 px-3 pb-2">
                <label class="wa-search flex h-10 items-center gap-4 rounded-full px-4">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="shrink-0 opacity-70"><circle cx="10.5" cy="10.5" r="6.5" /><path d="M20 20l-4.8-4.8" /></svg>
                    <input v-model="search" type="search" placeholder="Pesquisar ou começar uma nova conversa" class="w-full border-0 bg-transparent p-0 text-[15px] focus:ring-0">
                </label>
                <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                    <button
                        v-for="item in filters"
                        :key="item.key"
                        type="button"
                        class="wa-chip shrink-0"
                        :class="{ 'wa-chip-active': filter === item.key }"
                        @click="filter = item.key"
                    >
                        {{ item.label }}
                        <span v-if="item.key === 'human' && humanCount" class="wa-human-count">{{ humanCount }}</span>
                    </button>
                </div>
            </div>

            <div class="wa-list min-h-0 flex-1 overflow-y-auto">
                <div
                    v-for="conversation in visibleConversations"
                    :key="conversation.id"
                    role="button"
                    tabindex="0"
                    class="wa-row flex cursor-pointer items-center"
                    :class="{ 'wa-row-active': conversation.id === activeId }"
                    @click="openConversation(conversation.id)"
                    @keydown.enter="openConversation(conversation.id)"
                >
                    <span class="flex h-[72px] shrink-0 items-center pl-3 pr-[15px]">
                        <span class="flex h-[49px] w-[49px] items-center justify-center rounded-full text-[17px] font-medium text-white" :class="{ 'wa-story': conversation.needsHuman }" :style="{ backgroundColor: avatarColor(conversation) }" :title="conversation.needsHuman ? 'Precisa de humano' : undefined">
                            <template v-if="initials(conversation)">{{ initials(conversation) }}</template>
                            <svg v-else viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><circle cx="12" cy="8" r="4.2" /><path d="M3.5 21c.6-4.6 4.2-7 8.5-7s7.9 2.4 8.5 7z" /></svg>
                        </span>
                    </span>
                    <span class="wa-row-body flex h-[72px] min-w-0 flex-1 flex-col justify-center pr-[15px]">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="wa-row-name truncate">{{ displayName(conversation) }}</span>
                            <span class="wa-row-time shrink-0" :class="{ 'wa-row-time-unread': conversation.unread }">{{ listTime(conversation.lastMessageAt) }}</span>
                        </span>
                        <span class="mt-0.5 flex items-center justify-between gap-2">
                            <span class="wa-row-preview flex min-w-0 items-center gap-1">
                                <svg v-if="conversation.previewDirection === 'outbound'" viewBox="0 0 18 11" width="16" height="11" class="shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M1 5.8l3.3 3.4L11.2 1.6" /><path d="M7.4 8.6l.7.6L15.2 1.6" /></svg>
                                <span class="truncate">{{ conversation.preview }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span v-if="conversation.needsHuman" class="wa-human-badge" title="A conversa precisa que uma pessoa responda"><i class="fas fa-hand"></i>Humano</span>
                                <span v-if="conversation.unread" class="wa-unread">{{ conversation.unread }}</span>
                                <button
                                    type="button"
                                    role="switch"
                                    :aria-checked="conversation.aiEnabled"
                                    class="wa-switch wa-switch-sm"
                                    :class="{ 'wa-switch-on': conversation.aiEnabled }"
                                    :title="conversation.aiEnabled ? `${attendant} atendendo (clique pra humanos assumirem)` : `Humanos atendendo (clique pra ${attendant} assumir)`"
                                    @click.stop="toggleManuela(conversation)"
                                    @keydown.enter.stop
                                >
                                    <span class="wa-switch-knob"></span>
                                </button>
                            </span>
                        </span>
                    </span>
                </div>

                <p v-if="!visibleConversations.length" class="wa-muted px-8 py-14 text-center text-sm">
                    {{ conversations.length ? 'Nenhuma conversa nesse filtro.' : 'Nenhuma conversa ainda. Quando um cliente escrever no WhatsApp oficial, aparece aqui na hora.' }}
                </p>
            </div>
        </aside>

        <!-- Chat -->
        <section class="wa-main relative min-w-0 flex-1 flex-col" :class="active ? 'flex' : 'hidden md:flex'">
            <template v-if="active">
                <header class="wa-head relative z-20 flex h-[59px] shrink-0 items-center gap-1 px-4">
                    <button type="button" class="wa-icon-btn inline-flex -ml-2 md:hidden" title="Voltar" @click="closeConversation">
                        <i class="fas fa-arrow-left"></i>
                    </button>
                    <button type="button" class="flex min-w-0 flex-1 items-center gap-[15px] text-left" @click="drawerOpen = true">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-[15px] font-medium text-white" :class="{ 'wa-story': active.needsHuman }" :style="{ backgroundColor: avatarColor(active) }">
                            <template v-if="initials(active)">{{ initials(active) }}</template>
                            <svg v-else viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><circle cx="12" cy="8" r="4.2" /><path d="M3.5 21c.6-4.6 4.2-7 8.5-7s7.9 2.4 8.5 7z" /></svg>
                        </span>
                        <span class="min-w-0">
                            <span class="wa-head-name block truncate">{{ displayName(active) }}</span>
                            <span class="wa-head-sub block truncate">
                                <span v-if="active.needsHuman" class="wa-human-badge mr-1.5 align-middle"><i class="fas fa-hand"></i>Precisa de humano</span>
                                <template v-if="active.status === 'resolved'">encerrada · </template>
                                clique para mostrar os dados do contato
                            </span>
                        </span>
                    </button>

                    <!-- Chave seletora da conversa: ligada = Manuela, desligada = humanos -->
                    <label class="wa-agent-toggle flex shrink-0 cursor-pointer select-none items-center gap-2 rounded-full px-3 py-1.5" :title="active.aiEnabled ? `${attendant} está respondendo esta conversa` : 'Só humanos respondem esta conversa'">
                        <span class="hidden text-[13px] sm:inline">
                            <template v-if="active.aiEnabled"><i class="fas fa-robot mr-1 text-[#00a884]"></i>{{ attendant }}</template>
                            <template v-else><i class="fas fa-headset mr-1"></i>Humanos</template>
                        </span>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="active.aiEnabled"
                            class="wa-switch"
                            :class="{ 'wa-switch-on': active.aiEnabled }"
                            @click="toggleManuela(active)"
                        >
                            <span class="wa-switch-knob"></span>
                        </button>
                    </label>

                    <div class="relative">
                        <button type="button" class="wa-icon-btn inline-flex" title="Mais opções" @click="menuOpen = !menuOpen">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><circle cx="12" cy="5.5" r="1.9" /><circle cx="12" cy="12" r="1.9" /><circle cx="12" cy="18.5" r="1.9" /></svg>
                        </button>
                        <div v-if="menuOpen" class="fixed inset-0 z-30" @click="menuOpen = false"></div>
                        <div v-if="menuOpen" class="wa-menu absolute right-0 top-11 z-40 w-60 py-2">
                            <button type="button" class="wa-menu-item" @click="drawerOpen = true; menuOpen = false">Dados do contato</button>
                            <button type="button" class="wa-menu-item" @click="toggleManuela(active); menuOpen = false">
                                {{ active.aiEnabled ? 'Humanos assumem a conversa' : `${attendant} assume a conversa` }}
                            </button>
                            <button type="button" class="wa-menu-item" @click="toggleResolved">{{ active.status === 'resolved' ? 'Reabrir conversa' : 'Encerrar conversa' }}</button>
                            <button type="button" class="wa-menu-item" @click="closeConversation">Fechar conversa</button>
                            <button type="button" class="wa-menu-item wa-menu-danger" @click="deleteConversation">Apagar conversa</button>
                        </div>
                    </div>
                </header>

                <!-- Papel de parede fica parado; só as mensagens rolam por cima -->
                <div class="wa-wallpaper relative min-h-0 flex-1">
                    <div ref="messagesBox" class="absolute inset-0 overflow-y-auto px-[5%] pb-2 pt-3 lg:px-[7%]" @scroll.passive="onMessagesScroll">
                        <div v-if="hasMore" class="mb-2 flex justify-center">
                            <button type="button" class="wa-system-chip" :disabled="loadingOlder" @click="loadOlder">
                                {{ loadingOlder ? 'Carregando…' : 'Carregar mensagens anteriores' }}
                            </button>
                        </div>
                        <div v-if="loadingMessages" class="flex justify-center py-10">
                            <span class="wa-system-chip"><i class="fas fa-circle-notch fa-spin mr-1"></i> Carregando mensagens…</span>
                        </div>
                        <div v-if="!loadingMessages && !hasMore && messages.length" class="mb-3 flex justify-center">
                            <span class="wa-notice"><i class="fas fa-circle-info mr-1"></i> Conversa pelo WhatsApp oficial da {{ status.brandName }}. Clientes recebem as respostas da {{ attendant }} ou do time.</span>
                        </div>

                        <template v-for="item in timeline" :key="item.key">
                            <div v-if="item.kind === 'day'" class="my-3 flex justify-center">
                                <span class="wa-system-chip">{{ item.label }}</span>
                            </div>
                            <div
                                v-else
                                class="flex"
                                :class="[item.message.direction === 'outbound' ? 'justify-end' : 'justify-start', item.first ? 'mt-3' : 'mt-[2px]']"
                            >
                                <div
                                    class="wa-bubble relative max-w-[85%] sm:max-w-[65%]"
                                    :class="[
                                        item.message.direction === 'outbound' ? 'wa-bubble-out' : 'wa-bubble-in',
                                        item.first && (item.message.direction === 'outbound' ? 'rounded-tr-none' : 'rounded-tl-none'),
                                    ]"
                                >
                                    <svg v-if="item.first && item.message.direction === 'inbound'" viewBox="0 0 8 13" width="8" height="13" class="wa-tail -left-2"><path d="M8 0H1.4C.3 0-.3 1.3.5 2.1L8 11.6z" /></svg>
                                    <svg v-if="item.first && item.message.direction === 'outbound'" viewBox="0 0 8 13" width="8" height="13" class="wa-tail -right-2"><path d="M0 0h6.6c1.1 0 1.7 1.3.9 2.1L0 11.6z" /></svg>

                                    <p
                                        v-if="item.first && item.message.direction === 'outbound' && item.message.sentBy"
                                        class="wa-sender"
                                        :class="item.message.sentBy === 'manuela' ? 'wa-sender-bot' : 'wa-sender-human'"
                                    >
                                        <i v-if="item.message.sentBy === 'manuela'" class="fas fa-robot mr-1"></i>{{ item.message.sentBy === 'manuela' ? attendant : item.message.sentBy }}
                                    </p>

                                    <template v-if="item.message.hasMedia">
                                        <a v-if="item.message.type === 'image' || item.message.type === 'sticker'" :href="mediaUrl(item.message)" target="_blank" rel="noopener" class="-mx-1 -mt-0.5 mb-1 block">
                                            <img :src="mediaUrl(item.message)" loading="lazy" alt="Imagem recebida" class="max-h-[330px] rounded-md object-cover" :class="item.message.type === 'sticker' ? 'w-36' : 'w-[330px] max-w-full'">
                                        </a>
                                        <audio v-else-if="item.message.type === 'audio'" :src="mediaUrl(item.message)" controls preload="none" class="mb-1 h-11 w-[300px] max-w-full"></audio>
                                        <video v-else-if="item.message.type === 'video'" :src="mediaUrl(item.message)" controls preload="metadata" class="-mx-1 mb-1 max-h-[330px] w-[330px] max-w-full rounded-md"></video>
                                        <a v-else :href="mediaUrl(item.message)" target="_blank" rel="noopener" class="wa-doc mb-1 flex items-center gap-3 rounded-md px-3 py-3">
                                            <svg viewBox="0 0 24 30" width="26" height="32"><path d="M2 0h14l8 8v20a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2z" fill="#e9edef" /><path d="M16 0l8 8h-6a2 2 0 0 1-2-2z" fill="#c4ccd0" /></svg>
                                            <span class="min-w-0 truncate text-sm">{{ item.message.fileName || 'Documento' }}</span>
                                        </a>
                                    </template>
                                    <p v-else-if="!['text', 'button', 'interactive', 'template'].includes(item.message.type)" class="wa-muted italic">
                                        <i class="fas fa-paperclip mr-1"></i> Mensagem do tipo "{{ item.message.type }}"
                                    </p>

                                    <span v-if="item.message.type === 'audio' && item.message.transcription" class="wa-muted block text-[12px]"><i class="fas fa-closed-captioning mr-1"></i>Transcrição do áudio</span>
                                    <span v-if="item.message.body && !(item.message.hasMedia && item.message.type === 'document')" class="wa-text whitespace-pre-wrap break-words" v-html="linkify(item.message.body)"></span>
                                    <!-- Espaço reservado pro horário não encostar no texto (técnica do WhatsApp) -->
                                    <span class="inline-block" :class="item.message.direction === 'outbound' ? 'w-[74px]' : 'w-[52px]'"></span>

                                    <span class="wa-meta absolute bottom-[3px] right-[7px] flex items-center gap-[3px]">
                                        {{ hourMinute(item.message.at) }}
                                        <span v-if="item.message.direction === 'outbound'" :title="tickTitle(item.message)" class="inline-flex">
                                            <svg v-if="item.message.status === 'read' || item.message.status === 'delivered'" viewBox="0 0 18 11" width="16" height="11" fill="none" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" :class="item.message.status === 'read' ? 'text-[#53bdeb]' : 'wa-tick'" stroke="currentColor"><path d="M1 5.8l3.3 3.4L11.2 1.6" /><path d="M7.4 8.6l.7.6L15.2 1.6" /></svg>
                                            <svg v-else-if="item.message.status === 'sent'" viewBox="0 0 13 11" width="12" height="11" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="wa-tick"><path d="M1 5.8l3.3 3.4L11.2 1.6" /></svg>
                                            <svg v-else-if="item.message.status === 'failed'" viewBox="0 0 16 16" width="14" height="14" class="text-red-500"><circle cx="8" cy="8" r="7" fill="currentColor" /><path d="M8 4.2v4.6M8 11.2v.4" stroke="#fff" stroke-width="1.7" stroke-linecap="round" /></svg>
                                            <svg v-else viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" :class="item.message.status === 'draft_no_token' ? 'text-amber-500' : 'wa-tick'"><circle cx="8" cy="8" r="6.3" /><path d="M8 4.6V8l2.3 1.5" /></svg>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <button
                        v-if="showNewBelow"
                        type="button"
                        class="wa-scroll-down absolute bottom-4 right-5 z-10 flex h-[42px] w-[42px] items-center justify-center rounded-full"
                        title="Ir para as mensagens novas"
                        @click="scrollToBottom"
                    >
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6" /></svg>
                        <span class="absolute -right-1 -top-1 h-3.5 w-3.5 rounded-full bg-[#25d366]"></span>
                    </button>
                </div>

                <footer class="wa-composer relative z-20 shrink-0 px-4 py-[5px]">
                    <div v-if="emojiOpen" class="wa-emoji-panel absolute bottom-full left-0 right-0 grid max-h-64 grid-cols-8 gap-1 overflow-y-auto p-3 sm:grid-cols-12 lg:grid-cols-16">
                        <button v-for="emoji in EMOJIS" :key="emoji" type="button" class="flex h-10 items-center justify-center rounded-md text-2xl hover:bg-black/5 dark:hover:bg-white/10" @click="insertEmoji(emoji)">{{ emoji }}</button>
                    </div>
                    <p v-if="composerBlocked" class="wa-banner wa-banner-warn mb-1.5 mt-1">
                        <i class="fas fa-triangle-exclamation"></i><span>{{ composerBlocked }}</span>
                    </p>
                    <p v-if="actionError" class="wa-banner wa-banner-error mb-1.5 mt-1">
                        <i class="fas fa-circle-exclamation"></i><span>{{ actionError }}</span>
                    </p>
                    <p v-else-if="active.aiEnabled" class="wa-muted mb-1 mt-1 text-center text-[12.5px]">
                        <i class="fas fa-robot mr-1"></i>A {{ attendant }} está atendendo. Se você mandar uma mensagem, a chave desliga e os humanos assumem.
                    </p>
                    <form class="flex min-h-[52px] items-end gap-2 py-[5px]" @submit.prevent="send">
                        <button type="button" class="wa-icon-btn inline-flex mb-[3px]" :class="{ 'wa-icon-btn-on': emojiOpen }" title="Emojis" @click="emojiOpen = !emojiOpen">
                            <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><circle cx="12" cy="12" r="9.2" /><path d="M8 14.2c1 1.6 2.4 2.4 4 2.4s3-.8 4-2.4" /><circle cx="9" cy="9.8" r=".6" fill="currentColor" /><circle cx="15" cy="9.8" r=".6" fill="currentColor" /></svg>
                        </button>
                        <textarea
                            ref="composer"
                            v-model="draft"
                            rows="1"
                            placeholder="Digite uma mensagem"
                            class="wa-input max-h-[168px] min-h-[42px] flex-1 resize-none rounded-lg border-0 px-3 py-[9px] text-[15px] leading-6 focus:ring-0"
                            @keydown="onComposerKey"
                            @input="resizeComposer"
                        ></textarea>
                        <button
                            type="submit"
                            class="wa-send mb-[1px] flex h-[42px] w-[42px] shrink-0 items-center justify-center rounded-full"
                            :class="{ 'wa-send-ready': draft.trim() }"
                            :disabled="!draft.trim() || sending"
                            title="Enviar (Enter)"
                        >
                            <i v-if="sending" class="fas fa-circle-notch fa-spin"></i>
                            <svg v-else viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M3.4 20.4l17.5-7.5a1 1 0 0 0 0-1.8L3.4 3.6a.9.9 0 0 0-1.3 1l1.6 6.3 9.3 1.1-9.3 1.1-1.6 6.3a.9.9 0 0 0 1.3 1z" /></svg>
                        </button>
                    </form>
                </footer>
            </template>

            <!-- Tela inicial (sem conversa aberta) -->
            <div v-else class="wa-intro flex flex-1 flex-col items-center justify-center px-10 text-center">
                <svg viewBox="0 0 320 190" width="320" height="190" class="mb-8 max-w-full">
                    <rect x="30" y="20" width="200" height="130" rx="10" class="wa-ill-screen" />
                    <rect x="42" y="32" width="176" height="106" rx="4" class="wa-ill-glass" />
                    <path d="M10 160h240l-14 12H24z" class="wa-ill-screen" />
                    <rect x="60" y="48" width="84" height="14" rx="7" fill="#fff" />
                    <rect x="114" y="72" width="90" height="14" rx="7" fill="#d9fdd3" />
                    <rect x="60" y="96" width="64" height="14" rx="7" fill="#fff" />
                    <rect x="200" y="54" width="72" height="124" rx="12" class="wa-ill-phone" />
                    <rect x="207" y="64" width="58" height="100" rx="4" class="wa-ill-glass" />
                    <circle cx="236" cy="113" r="18" fill="#25d366" />
                    <path d="M228.5 121.5l1.6-4.8a8.6 8.6 0 1 1 3.2 3.2z" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round" />
                </svg>
                <h2 class="wa-intro-title">WhatsApp {{ status.brandName }}</h2>
                <p class="wa-muted mt-4 max-w-[560px] text-sm leading-5">
                    Todas as conversas do WhatsApp oficial chegam aqui sozinhas, sem recarregar a página.<br>
                    Em cada conversa, a chave <strong>{{ attendant }}</strong> decide quem responde: ligada, a {{ attendant }} da equipe da Naia atende; desligada, só humanos.
                </p>
                <p class="wa-muted mt-10 flex items-center gap-1.5 text-[13px]">
                    <span class="h-2 w-2 rounded-full" :class="connectionLost ? 'bg-red-500' : 'bg-[#25d366]'"></span>
                    {{ connectionLost ? 'Sem conexão com o servidor, tentando de novo…' : (status.manuelaRemote ? `${attendant} conectada ao Hermes` : `${attendant} respondendo pelas regras locais (Hermes ainda não conectado)`) }}
                </p>
            </div>
        </section>

        <!-- Dados do contato (painel da direita, igual ao WhatsApp Web) -->
        <aside v-if="active && drawerOpen" class="wa-drawer absolute inset-0 z-30 flex flex-col md:relative md:inset-auto md:w-[30%] md:min-w-[320px] md:max-w-[420px] md:shrink-0">
            <header class="wa-head flex h-[59px] shrink-0 items-center gap-6 px-6">
                <button type="button" class="wa-icon-btn inline-flex -ml-2" title="Fechar" @click="drawerOpen = false">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
                <span class="text-base">Dados do contato</span>
            </header>
            <div class="wa-drawer-body min-h-0 flex-1 overflow-y-auto">
                <section class="wa-drawer-card flex flex-col items-center px-6 pb-6 pt-7">
                    <span class="flex h-[200px] w-[200px] items-center justify-center rounded-full text-6xl font-light text-white" :class="{ 'wa-story wa-story-lg': active.needsHuman }" :style="{ backgroundColor: avatarColor(active) }">
                        <template v-if="initials(active)">{{ initials(active) }}</template>
                        <svg v-else viewBox="0 0 24 24" width="110" height="110" fill="currentColor"><circle cx="12" cy="8" r="4.2" /><path d="M3.5 21c.6-4.6 4.2-7 8.5-7s7.9 2.4 8.5 7z" /></svg>
                    </span>
                    <p class="mt-4 text-center text-2xl">{{ displayName(active) }}</p>
                    <p class="wa-muted mt-1 text-base">{{ formatPhone(active.phone) }}</p>
                </section>

                <section class="wa-drawer-card mt-2.5 px-7 py-4">
                    <p class="wa-muted mb-3 text-sm">Atendimento</p>
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-base"><i class="fas fa-robot mr-2 text-[#00a884]"></i>{{ attendant }} atende esta conversa</p>
                            <p class="wa-muted mt-0.5 text-[13px]">{{ active.aiEnabled ? `Ligada: a ${attendant} responde o cliente sozinha.` : 'Desligada: só humanos respondem.' }}</p>
                        </div>
                        <button type="button" role="switch" :aria-checked="active.aiEnabled" class="wa-switch shrink-0" :class="{ 'wa-switch-on': active.aiEnabled }" @click="toggleManuela(active)">
                            <span class="wa-switch-knob"></span>
                        </button>
                    </div>
                    <p v-if="active.needsHuman" class="wa-banner wa-banner-warn mt-4">
                        <i class="fas fa-hand"></i><span>A {{ attendant }} pediu ajuda de uma pessoa nesta conversa.</span>
                    </p>
                    <p class="wa-muted mt-4 text-[13px]">
                        <i class="far fa-clock mr-1"></i>
                        {{ active.insideWindow ? 'Dentro da janela de 24h: dá pra responder com texto livre.' : 'Fora da janela de 24h: só modelo aprovado até o cliente escrever de novo.' }}
                    </p>
                </section>

                <section class="wa-drawer-card mt-2.5 py-2">
                    <button type="button" class="wa-drawer-action" @click="toggleResolved">
                        <i :class="active.status === 'resolved' ? 'fas fa-rotate-left' : 'fas fa-circle-check'" class="w-6"></i>
                        {{ active.status === 'resolved' ? 'Reabrir conversa' : 'Encerrar conversa' }}
                    </button>
                    <button type="button" class="wa-drawer-action" @click="deleteConversation">
                        <i class="fas fa-trash-can w-6"></i>
                        Apagar conversa
                    </button>
                </section>
            </div>
        </aside>
    </div>
</template>

<style scoped>
/* Medidas e cores do WhatsApp Web (tema claro). Escuro no bloco de baixo. */
.wa-app {
    --wa-panel: #ffffff;
    --wa-panel-head: #f0f2f5;
    --wa-rail: #f0f2f5;
    --wa-border: #e9edef;
    --wa-text: #111b21;
    --wa-muted: #667781;
    --wa-icon: #54656f;
    --wa-hover: #f5f6f6;
    --wa-active: #f0f2f5;
    --wa-search: #f0f2f5;
    --wa-bubble-in: #ffffff;
    --wa-bubble-out: #d9fdd3;
    --wa-wall: #efeae2;
    --wa-wall-opacity: 0.06;
    --wa-chip: #ffffff;
    --wa-system: #ffffff;
    --wa-system-text: #54656f;
    --wa-notice: #ffeecd;
    --wa-notice-text: #54656f;
    --wa-input: #ffffff;
    --wa-intro: #f0f2f5;
    --wa-menu: #ffffff;
    --wa-drawer-bg: #f0f2f5;
    --wa-green: #00a884;
    --wa-unread: #25d366;
    --wa-chip-active-bg: #e7fce3;
    --wa-chip-active-text: #008069;
    --wa-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
    font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, 'Lucida Grande', Arial, Ubuntu, Cantarell, 'Fira Sans', sans-serif;
    color: var(--wa-text);
    background: var(--wa-panel);
    -webkit-font-smoothing: antialiased;
}

.wa-rail { background: var(--wa-rail); border-right: 1px solid var(--wa-border); }
.wa-rail-btn { position: relative; display: inline-flex; height: 40px; width: 40px; align-items: center; justify-content: center; border-radius: 9999px; color: var(--wa-icon); transition: background-color 0.15s; }
.wa-rail-btn:hover { background: rgba(11, 20, 26, 0.06); }
.wa-rail-btn-active { background: rgba(11, 20, 26, 0.1); color: var(--wa-text); }
.wa-rail-badge { position: absolute; top: -2px; right: -4px; min-width: 19px; height: 19px; padding: 0 5px; border-radius: 9999px; background: var(--wa-unread); color: #fff; font-size: 11px; font-weight: 600; line-height: 19px; text-align: center; }

.wa-side { background: var(--wa-panel); border-right: 1px solid var(--wa-border); }
.wa-title { font-size: 22px; font-weight: 700; color: var(--wa-text); }
.wa-icon-btn { height: 40px; width: 40px; align-items: center; justify-content: center; border-radius: 9999px; color: var(--wa-icon); transition: background-color 0.15s; }
.wa-icon-btn:hover, .wa-icon-btn-on { background: rgba(11, 20, 26, 0.06); }
.wa-search { background: var(--wa-search); color: var(--wa-icon); }
.wa-search input { color: var(--wa-text); }
.wa-search input::placeholder { color: var(--wa-muted); }
.wa-chip { border-radius: 9999px; border: 1px solid var(--wa-border); background: var(--wa-chip); color: var(--wa-muted); padding: 5px 12px; font-size: 14px; line-height: 20px; transition: background-color 0.15s; }
.wa-chip:hover { background: var(--wa-hover); }
.wa-chip-active, .wa-chip-active:hover { border-color: transparent; background: var(--wa-chip-active-bg); color: var(--wa-chip-active-text); }

.wa-row { background: var(--wa-panel); transition: background-color 0.1s; }
.wa-row:hover { background: var(--wa-hover); }
.wa-row-active, .wa-row-active:hover { background: var(--wa-active); }
.wa-row-body { border-top: 1px solid var(--wa-border); }
.wa-row:first-child .wa-row-body { border-top-color: transparent; }
.wa-row:hover .wa-row-body, .wa-row-active .wa-row-body, .wa-row:hover + .wa-row .wa-row-body, .wa-row-active + .wa-row .wa-row-body { border-top-color: transparent; }
.wa-row-name { font-size: 17px; line-height: 21px; color: var(--wa-text); }
.wa-row-time { font-size: 12px; line-height: 20px; color: var(--wa-muted); }
.wa-row-time-unread { color: var(--wa-unread); font-weight: 600; }
.wa-row-preview { font-size: 14px; line-height: 20px; color: var(--wa-muted); }
/* Precisa de humano: badge vermelha + anel de stories em volta da foto */
.wa-human-badge { display: inline-flex; height: 20px; align-items: center; gap: 4px; border-radius: 9999px; background: #ea0038; padding: 0 8px; font-size: 11.5px; font-weight: 600; line-height: 1; color: #fff; white-space: nowrap; }
.wa-human-badge i { font-size: 10px; }
.wa-human-count { margin-left: 6px; display: inline-flex; min-width: 18px; height: 18px; align-items: center; justify-content: center; border-radius: 9999px; background: #ea0038; padding: 0 5px; font-size: 11px; font-weight: 700; color: #fff; }
.wa-story { position: relative; }
.wa-story::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 9999px;
    padding: 2.5px;
    background: conic-gradient(from 200deg, #feda75, #fa7e1e, #ea0038, #d62976, #962fbf, #feda75);
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    pointer-events: none;
}
.wa-story-lg::before { inset: -9px; padding: 5px; }
.wa-unread { display: inline-flex; min-width: 20px; height: 20px; align-items: center; justify-content: center; border-radius: 9999px; background: var(--wa-unread); padding: 0 6px; font-size: 12px; font-weight: 600; color: #fff; }
.wa-muted { color: var(--wa-muted); }

/* Chave seletora (Manuela ligada / humanos) */
.wa-switch { position: relative; display: inline-block; width: 36px; height: 20px; border-radius: 9999px; background: #c1c7cb; transition: background-color 0.2s; flex-shrink: 0; }
.wa-switch-knob { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 9999px; background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3); transition: transform 0.2s; }
.wa-switch-on { background: var(--wa-green); }
.wa-switch-on .wa-switch-knob { transform: translateX(16px); }
.wa-switch-sm { width: 28px; height: 16px; }
.wa-switch-sm .wa-switch-knob { width: 12px; height: 12px; }
.wa-switch-sm.wa-switch-on .wa-switch-knob { transform: translateX(12px); }
.wa-agent-toggle { color: var(--wa-icon); }
.wa-agent-toggle:hover { background: rgba(11, 20, 26, 0.05); }

.wa-main { background: var(--wa-wall); }
.wa-head { background: var(--wa-panel-head); border-left: 1px solid var(--wa-border); }
.wa-head-name { font-size: 16px; line-height: 21px; color: var(--wa-text); }
.wa-head-sub { font-size: 13px; line-height: 20px; color: var(--wa-muted); }
.wa-menu { background: var(--wa-menu); border-radius: 3px; box-shadow: 0 2px 5px rgba(11, 20, 26, 0.26), 0 2px 10px rgba(11, 20, 26, 0.16); }
.wa-menu-item { display: block; width: 100%; padding: 9px 24px; text-align: left; font-size: 14.5px; color: var(--wa-text); }
.wa-menu-item.wa-menu-danger { color: #ea0038; }
.wa-menu-item:hover { background: var(--wa-hover); }

.wa-wallpaper { background-color: var(--wa-wall); }
.wa-wallpaper::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background-image: url('./assets/wallpaper.svg');
    background-repeat: repeat;
    background-size: 412px;
    opacity: var(--wa-wall-opacity);
}

.wa-system-chip { border-radius: 7.5px; background: var(--wa-system); color: var(--wa-system-text); padding: 5px 12px 6px; font-size: 12.5px; line-height: 21px; box-shadow: var(--wa-shadow); }
.wa-notice { max-width: 560px; border-radius: 7.5px; background: var(--wa-notice); color: var(--wa-notice-text); padding: 5px 12px 6px; text-align: center; font-size: 12.5px; line-height: 18px; box-shadow: var(--wa-shadow); }

.wa-bubble { border-radius: 7.5px; padding: 6px 7px 8px 9px; font-size: 14.2px; line-height: 19px; color: var(--wa-text); box-shadow: var(--wa-shadow); }
.wa-bubble-in { background: var(--wa-bubble-in); }
.wa-bubble-out { background: var(--wa-bubble-out); }
.wa-tail { position: absolute; top: 0; }
.wa-bubble-in .wa-tail { fill: var(--wa-bubble-in); }
.wa-bubble-out .wa-tail { fill: var(--wa-bubble-out); }
.wa-sender { margin-bottom: 2px; font-size: 12.8px; font-weight: 500; line-height: 22px; }
.wa-sender-bot { color: #008069; }
.wa-sender-human { color: #7f66ff; }
.wa-meta { font-size: 11px; line-height: 15px; color: var(--wa-muted); white-space: nowrap; }
.wa-tick { color: #8696a0; }
.wa-doc { background: rgba(11, 20, 26, 0.05); }
.wa-text :deep(.wa-link) { color: #027eb5; text-decoration: none; }
.wa-text :deep(.wa-link:hover) { text-decoration: underline; }

.wa-scroll-down { background: var(--wa-panel); color: var(--wa-icon); box-shadow: 0 1px 1px rgba(11, 20, 26, 0.06), 0 2px 5px rgba(11, 20, 26, 0.2); }

.wa-composer { background: var(--wa-panel-head); border-left: 1px solid var(--wa-border); }
.wa-input { background: var(--wa-input); color: var(--wa-text); outline: none; box-shadow: none; }
.wa-input:focus { outline: none; box-shadow: none; }
.wa-input::placeholder { color: var(--wa-muted); }
.wa-send { color: var(--wa-icon); transition: background-color 0.15s, color 0.15s; }
.wa-send-ready { background: var(--wa-green); color: #fff; }
.wa-send-ready:hover { background: #008f72; }
.wa-emoji-panel { background: var(--wa-panel-head); border-top: 1px solid var(--wa-border); }

.wa-banner { display: flex; align-items: flex-start; gap: 8px; border-radius: 8px; padding: 8px 12px; font-size: 13px; line-height: 18px; }
.wa-banner i { margin-top: 2px; }
.wa-banner-warn { background: #fff3c4; color: #7a5b00; }
.wa-banner-info { background: #e1f3fb; color: #1d5875; }
.wa-banner-error { background: #fde2e1; color: #a1271d; }

.wa-intro { background: var(--wa-intro); border-left: 1px solid var(--wa-border); }
.wa-intro-title { font-size: 32px; font-weight: 300; color: var(--wa-text); }
.wa-ill-screen { fill: #dfe5e7; }
.wa-ill-glass { fill: #c9e7da; }
.wa-ill-phone { fill: #8696a0; }

.wa-drawer { background: var(--wa-drawer-bg); border-left: 1px solid var(--wa-border); }
.wa-drawer-card { background: var(--wa-panel); box-shadow: 0 1px 3px rgba(11, 20, 26, 0.08); }
.wa-drawer-action { display: flex; width: 100%; align-items: center; gap: 18px; padding: 14px 30px; text-align: left; font-size: 16px; color: #ea0038; }
.wa-drawer-action:hover { background: var(--wa-hover); }
</style>

<style>
/* Tema escuro do WhatsApp Web, seguindo a classe .dark do admin. Fora do
   scoped porque :global(.dark) .x compila só pra .dark e pintaria a página. */
.dark .wa-app {
    --wa-panel: #111b21;
    --wa-panel-head: #202c33;
    --wa-rail: #202c33;
    --wa-border: #222d34;
    --wa-text: #e9edef;
    --wa-muted: #8696a0;
    --wa-icon: #aebac1;
    --wa-hover: #202c33;
    --wa-active: #2a3942;
    --wa-search: #202c33;
    --wa-bubble-in: #202c33;
    --wa-bubble-out: #005c4b;
    --wa-wall: #0b141a;
    --wa-wall-opacity: 0.07;
    --wa-chip: #111b21;
    --wa-system: #182229;
    --wa-system-text: #8696a0;
    --wa-notice: #182229;
    --wa-notice-text: #ffd279;
    --wa-input: #2a3942;
    --wa-intro: #222e35;
    --wa-menu: #233138;
    --wa-drawer-bg: #0c1317;
    --wa-chip-active-bg: #0a332c;
    --wa-chip-active-text: #25d366;
    --wa-shadow: 0 1px 0.5px rgba(11, 20, 26, 0.13);
}
.dark .wa-app .wa-wallpaper::before { filter: invert(1); }
.dark .wa-app .wa-rail-btn:hover, .dark .wa-app .wa-icon-btn:hover, .dark .wa-app .wa-icon-btn-on, .dark .wa-app .wa-agent-toggle:hover { background: rgba(255, 255, 255, 0.08); }
.dark .wa-app .wa-rail-btn-active { background: rgba(255, 255, 255, 0.1); }
.dark .wa-app .wa-sender-bot { color: #25d366; }
.dark .wa-app .wa-sender-human { color: #a78bfa; }
.dark .wa-app .wa-text .wa-link { color: #53bdeb; }
.dark .wa-app .wa-doc { background: rgba(255, 255, 255, 0.06); }
.dark .wa-app .wa-switch:not(.wa-switch-on) { background: #54656f; }
.dark .wa-app .wa-banner-warn { background: rgba(245, 158, 11, 0.12); color: #fcd34d; }
.dark .wa-app .wa-banner-info { background: rgba(83, 189, 235, 0.12); color: #9fdcf6; }
.dark .wa-app .wa-banner-error { background: rgba(239, 68, 68, 0.14); color: #fca5a5; }
.dark .wa-app .wa-ill-screen { fill: #364147; }
.dark .wa-app .wa-ill-glass { fill: #1f4b40; }
.dark .wa-app .wa-drawer-action { color: #f15c6d; }
</style>
