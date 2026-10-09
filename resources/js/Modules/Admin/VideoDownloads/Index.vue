<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    platforms: { type: Array, default: () => ['YouTube', 'TikTok', 'Facebook', 'Instagram'] },
    maxFileSize: { type: String, default: '300M' },
    timeoutSeconds: { type: Number, default: 180 },
    csrfToken: { type: String, default: '' },
    downloadUrl: { type: String, default: '/admin/download-videos' },
});

const page = usePage();
const videoUrl = ref('');
const isProcessing = ref(false);
const message = ref(page.props.flash?.error || null);
const messageType = ref(page.props.flash?.error ? 'error' : null);
const recentDownloads = ref([]);

const supportedPlatforms = [
    {
        key: 'youtube',
        label: 'YouTube',
        icon: 'fab fa-youtube',
        hosts: ['youtube.com', 'youtu.be'],
        badgeClass: 'bg-red-50 text-red-700 ring-1 ring-red-100',
        panelClass: 'from-red-500/20 to-red-950/10',
    },
    {
        key: 'tiktok',
        label: 'TikTok',
        icon: 'fab fa-tiktok',
        hosts: ['tiktok.com'],
        badgeClass: 'bg-slate-900 text-white ring-1 ring-slate-700',
        panelClass: 'from-cyan-400/20 to-slate-950/10',
    },
    {
        key: 'facebook',
        label: 'Facebook',
        icon: 'fab fa-facebook',
        hosts: ['facebook.com', 'fb.watch'],
        badgeClass: 'bg-blue-50 text-blue-700 ring-1 ring-blue-100',
        panelClass: 'from-blue-500/20 to-blue-950/10',
    },
    {
        key: 'instagram',
        label: 'Instagram',
        icon: 'fab fa-instagram',
        hosts: ['instagram.com', 'instagr.am'],
        badgeClass: 'bg-pink-50 text-pink-700 ring-1 ring-pink-100',
        panelClass: 'from-pink-500/20 to-orange-950/10',
    },
];

const normalizedHost = computed(() => {
    try {
        return new URL(videoUrl.value.trim()).hostname.replace(/^www\./, '').toLowerCase();
    } catch (error) {
        return '';
    }
});

const detectedPlatform = computed(() => supportedPlatforms.find((platform) => (
    platform.hosts.some((host) => normalizedHost.value === host || normalizedHost.value.endsWith(`.${host}`))
)) ?? null);

const canSubmit = computed(() => Boolean(
    videoUrl.value.trim().length > 8 && detectedPlatform.value && !isProcessing.value,
));

const helperText = computed(() => {
    if (!videoUrl.value.trim()) {
        return 'Cole uma URL de vídeo para começar.';
    }

    if (!detectedPlatform.value) {
        return 'Plataforma não reconhecida. Use YouTube, TikTok, Facebook ou Instagram.';
    }

    return `Plataforma detectada: ${detectedPlatform.value.label}`;
});

const messageClasses = computed(() => ({
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-rose-200 bg-rose-50 text-rose-800',
    info: 'border-blue-200 bg-blue-50 text-blue-800',
}[messageType.value] ?? 'border-slate-200 bg-slate-50 text-slate-700'));

const shortUrl = (url) => (url.length > 72 ? `${url.slice(0, 69)}...` : url);

const filenameFromDisposition = (disposition) => {
    const utfMatch = disposition?.match(/filename\*=UTF-8''([^;]+)/i);
    const asciiMatch = disposition?.match(/filename="?([^";]+)"?/i);
    const filename = utfMatch?.[1] || asciiMatch?.[1];

    return filename ? decodeURIComponent(filename) : 'kazakora-video.mp4';
};

const submitDownload = async () => {
    if (!canSubmit.value) {
        return;
    }

    const url = videoUrl.value.trim();
    const historyItem = {
        id: Date.now(),
        platform: detectedPlatform.value.label,
        url,
        status: 'Processando',
    };

    isProcessing.value = true;
    message.value = 'Preparando o vídeo. Mantenha esta tela aberta até o download iniciar.';
    messageType.value = 'info';
    recentDownloads.value = [historyItem, ...recentDownloads.value].slice(0, 5);

    try {
        const response = await fetch(props.downloadUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json, video/*, application/octet-stream',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': props.csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ url }),
        });

        if (!response.ok) {
            let payload = {};

            try {
                payload = await response.json();
            } catch (error) {
                payload = {};
            }

            throw new Error(payload.message || payload.errors?.url?.[0] || 'Não foi possível baixar este vídeo.');
        }

        const blob = await response.blob();
        const downloadUrl = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        const disposition = response.headers.get('Content-Disposition');

        link.href = downloadUrl;
        link.download = filenameFromDisposition(disposition);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(downloadUrl);

        historyItem.status = 'Concluído';
        message.value = 'Download iniciado. Confira a pasta Downloads do navegador.';
        messageType.value = 'success';
    } catch (error) {
        historyItem.status = 'Erro';
        message.value = error.message || 'Falha temporária no servidor. Tente novamente em alguns minutos.';
        messageType.value = 'error';
    } finally {
        isProcessing.value = false;
    }
};
</script>

<template>
    <Head title="Download de vídeos" />

    <AdminLayout>
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-primary">
                    Ferramenta interna
                </p>
                <h1 class="mt-1 text-2xl font-bold text-[var(--text-primary)] md:text-3xl">
                    Download de vídeos
                </h1>
                <p class="mt-1 max-w-3xl text-sm text-slate-500">
                    Cole uma URL pública ou autorizada do YouTube, TikTok, Facebook ou Instagram e baixe o arquivo pelo navegador.
                </p>
            </div>
        </div>

        <section class="mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-slate-950 via-slate-900 to-emerald-950 p-5 text-white shadow-2xl shadow-slate-300/30 md:p-7">
            <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr] lg:items-center">
                <div>
                    <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-xl">
                        <i class="fas fa-video"></i>
                    </div>

                    <h2 class="text-2xl font-black md:text-3xl">
                        Baixar vídeo para uso interno
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300">
                        Quando o processamento terminar, o navegador vai salvar o arquivo normalmente na pasta Downloads do seu computador.
                    </p>

                    <form class="mt-6 space-y-3" @submit.prevent="submitDownload">
                        <div class="flex flex-col gap-3 sm:flex-row">
                            <div class="relative flex-1">
                                <i class="fas fa-link absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                <input
                                    v-model="videoUrl"
                                    type="url"
                                    inputmode="url"
                                    autocomplete="off"
                                    placeholder="Cole aqui a URL do vídeo..."
                                    class="h-14 w-full rounded-2xl border border-white/10 bg-white px-12 text-base font-medium text-slate-900 outline-none ring-primary/30 placeholder:text-slate-400 focus:ring-4"
                                >
                            </div>

                            <button
                                type="submit"
                                class="h-14 rounded-2xl bg-primary px-6 text-sm font-bold text-white shadow-lg shadow-primary/20 transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50 sm:min-w-44"
                                :disabled="!canSubmit"
                            >
                                <span v-if="isProcessing">
                                    <i class="fas fa-spinner mr-2 animate-spin"></i>
                                    Preparando
                                </span>
                                <span v-else>
                                    <i class="fas fa-download mr-2"></i>
                                    Baixar vídeo
                                </span>
                            </button>
                        </div>

                        <div class="flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                            <p :class="detectedPlatform ? 'text-emerald-300' : 'text-slate-300'">
                                {{ helperText }}
                            </p>
                            <span v-if="detectedPlatform" class="inline-flex w-fit items-center gap-2 rounded-full px-3 py-1 text-xs font-bold" :class="detectedPlatform.badgeClass">
                                <i :class="detectedPlatform.icon"></i>
                                {{ detectedPlatform.label }}
                            </span>
                        </div>
                    </form>

                    <div v-if="message" class="mt-5 rounded-2xl border p-4 text-sm" :class="messageClasses">
                        {{ message }}
                    </div>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-300">
                        Plataformas suportadas
                    </h3>

                    <div class="mt-4 grid grid-cols-2 gap-3">
                        <div
                            v-for="platform in supportedPlatforms"
                            :key="platform.key"
                            class="rounded-xl bg-gradient-to-br p-3 ring-1 ring-white/10"
                            :class="platform.panelClass"
                        >
                            <i :class="platform.icon"></i>
                            <p class="mt-2 text-sm font-semibold">{{ platform.label }}</p>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl border border-amber-300/30 bg-amber-400/10 p-3 text-xs text-amber-100">
                        <p class="font-semibold">Uso interno e direitos autorais</p>
                        <p class="mt-1">
                            Baixe apenas vídeos próprios, públicos ou autorizados para uso interno da KazaKora.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-[0.8fr_1.2fr]">
            <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-base font-semibold text-[var(--text-primary)]">Como funciona</h2>
                <ol class="mt-4 space-y-3 text-sm text-slate-500">
                    <li class="flex gap-3"><span class="font-bold text-primary">1.</span> Copie o link público ou autorizado do vídeo.</li>
                    <li class="flex gap-3"><span class="font-bold text-primary">2.</span> Cole no campo principal desta tela.</li>
                    <li class="flex gap-3"><span class="font-bold text-primary">3.</span> Clique em “Baixar vídeo”.</li>
                    <li class="flex gap-3"><span class="font-bold text-primary">4.</span> Aguarde o servidor preparar o arquivo.</li>
                    <li class="flex gap-3"><span class="font-bold text-primary">5.</span> Confira a pasta Downloads do navegador.</li>
                </ol>

                <div class="mt-5 rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)]/50 p-4 text-xs text-slate-500">
                    Limites atuais: até {{ maxFileSize }}, com tempo máximo de {{ timeoutSeconds }} segundos por tentativa.
                </div>
            </div>

            <div class="rounded-2xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-[var(--text-primary)]">Histórico desta sessão</h2>
                        <p class="text-xs text-slate-400">Visual local, sem salvar no sistema.</p>
                    </div>

                    <button
                        v-if="recentDownloads.length"
                        type="button"
                        class="text-xs font-semibold text-primary hover:underline"
                        @click="recentDownloads = []"
                    >
                        Limpar
                    </button>
                </div>

                <div v-if="recentDownloads.length" class="space-y-3">
                    <div
                        v-for="item in recentDownloads"
                        :key="item.id"
                        class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface-muted)]/40 p-3 text-sm"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-semibold text-[var(--text-primary)]">{{ item.platform }}</p>
                                <p class="truncate text-xs text-slate-400">{{ shortUrl(item.url) }}</p>
                            </div>

                            <StatusBadge class="w-fit" :status="item.status" context="video_download" />
                        </div>
                    </div>
                </div>

                <p v-else class="rounded-xl border border-dashed border-[var(--surface-border)] p-5 text-sm text-slate-400">
                    Os downloads desta sessão aparecerão aqui.
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
