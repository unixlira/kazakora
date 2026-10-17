<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { StatusBadge } from '@/Shared/Components/DataTable';
import { Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    platforms: { type: Array, default: () => ['YouTube', 'TikTok', 'Facebook', 'Instagram'] },
    maxFileSize: { type: String, default: '300M' },
    timeoutSeconds: { type: Number, default: 180 },
    csrfToken: { type: String, default: '' },
    downloadUrl: { type: String, default: '/admin/download-videos' },
    previewUrl: { type: String, default: '/admin/download-videos/preview' },
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

// Prévia (miniatura, título, duração) assim que a URL é colada; em seguida
// o servidor já começa a preparar o arquivo, sem esperar clique.
const preview = ref(null);
const previewLoading = ref(false);
const videoReady = ref(false);
let preparedFor = null;
let videoJob = null;
let previewTimer = null;

const postJson = (url, body, accept) => fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
        Accept: accept,
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': props.csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
    },
    body: JSON.stringify(body),
});

const errorFrom = async (response, fallback) => {
    let payload = {};

    try {
        payload = await response.json();
    } catch (error) {
        payload = {};
    }

    return new Error(payload.message || payload.errors?.url?.[0] || fallback);
};

const formatDuration = (seconds) => {
    if (!seconds && seconds !== 0) {
        return null;
    }

    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
};

const suggestedName = computed(() => {
    const base = (preview.value?.title || 'kazakora-video')
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/#\S+/g, '')
        .replace(/[^A-Za-z0-9 _-]+/g, ' ')
        .trim().replace(/\s+/g, '-')
        .slice(0, 80);

    return `${base || 'kazakora-video'}.mp4`;
});

const fetchVideo = async (url, historyItem) => {
    const response = await postJson(props.downloadUrl, { url }, 'application/json, video/*, application/octet-stream');

    if (!response.ok) {
        throw await errorFrom(response, 'Não foi possível baixar este vídeo.');
    }

    return {
        blob: await response.blob(),
        filename: filenameFromDisposition(response.headers.get('Content-Disposition')),
        historyItem,
    };
};

const startPreparing = (url) => {
    if (preparedFor === url && videoJob) {
        return videoJob;
    }

    const historyItem = {
        id: Date.now(),
        platform: detectedPlatform.value?.label ?? 'Vídeo',
        url,
        status: 'Processando',
    };

    preparedFor = url;
    videoReady.value = false;
    isProcessing.value = true;
    recentDownloads.value = [historyItem, ...recentDownloads.value].slice(0, 5);

    const job = fetchVideo(url, historyItem);

    videoJob = job;
    job.then(() => {
        if (videoJob !== job) {
            return;
        }

        videoReady.value = true;
        message.value = 'Vídeo pronto. Escolha onde salvar no seu computador.';
        messageType.value = 'success';
        // Tenta abrir a janela "Salvar como" sozinha. O navegador só deixa
        // logo depois de um clique ou de colar; se não deixar, fica o botão.
        saveVideo({ automatic: true });
    }).catch((error) => {
        historyItem.status = 'Erro';

        if (videoJob !== job) {
            return;
        }

        videoJob = null;
        preparedFor = null;
        message.value = error.message || 'Falha temporária no servidor. Tente novamente em alguns minutos.';
        messageType.value = 'error';
    }).finally(() => {
        if (videoJob === job || videoJob === null) {
            isProcessing.value = false;
        }
    });

    return job;
};

const loadPreview = async (url) => {
    previewLoading.value = true;
    preview.value = null;
    message.value = 'Buscando o vídeo...';
    messageType.value = 'info';

    try {
        const response = await postJson(props.previewUrl, { url }, 'application/json');

        if (!response.ok) {
            throw await errorFrom(response, 'Não foi possível ler este vídeo.');
        }

        const data = await response.json();

        if (videoUrl.value.trim() !== url) {
            return;
        }

        preview.value = data;
        message.value = 'Preparando o arquivo. Mantenha esta tela aberta.';
        startPreparing(url);
    } catch (error) {
        if (videoUrl.value.trim() === url) {
            message.value = error.message;
            messageType.value = 'error';
        }
    } finally {
        previewLoading.value = false;
    }
};

watch(videoUrl, (value) => {
    clearTimeout(previewTimer);

    const url = value.trim();

    if (url === preparedFor) {
        return;
    }

    preview.value = null;
    videoReady.value = false;
    videoJob = null;
    preparedFor = null;
    isProcessing.value = false;

    if (!detectedPlatform.value || url.length <= 8) {
        message.value = null;
        return;
    }

    previewTimer = setTimeout(() => loadPreview(url), 500);
});

const saveBlobWithLink = (blob, filename) => {
    const objectUrl = window.URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = objectUrl;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => window.URL.revokeObjectURL(objectUrl), 10000);
};

let saving = false;

const saveVideo = async ({ automatic = false } = {}) => {
    const url = videoUrl.value.trim();

    if (saving || !url || !detectedPlatform.value) {
        return;
    }

    saving = true;

    try {
        const job = startPreparing(url);
        let handle = null;

        // "Salvar como" do Chrome/Edge: abre já no clique (antes do arquivo
        // ficar pronto, senão o navegador recusa) e grava quando chegar.
        if (window.showSaveFilePicker) {
            try {
                handle = await window.showSaveFilePicker({
                    suggestedName: suggestedName.value,
                    types: [{ description: 'Vídeo MP4', accept: { 'video/mp4': ['.mp4'] } }],
                });
            } catch (error) {
                if (error.name === 'AbortError' || automatic) {
                    return;
                }

                handle = null;
            }
        } else if (automatic) {
            return;
        }

        const { blob, filename, historyItem } = await job;

        if (handle) {
            const writable = await handle.createWritable();
            await writable.write(blob);
            await writable.close();
            message.value = `Vídeo salvo como ${handle.name}.`;
        } else {
            saveBlobWithLink(blob, filename);
            message.value = 'Download iniciado. Confira a pasta Downloads do navegador.';
        }

        historyItem.status = 'Concluído';
        messageType.value = 'success';
    } catch (error) {
        message.value = error.message || 'Falha temporária no servidor. Tente novamente em alguns minutos.';
        messageType.value = 'error';
    } finally {
        saving = false;
    }
};

const submitDownload = () => saveVideo();

const canSave = computed(() => Boolean(
    videoUrl.value.trim().length > 8 && detectedPlatform.value && !previewLoading.value,
));
</script>

<template>
    <Head title="Baixar vídeos" />

    <AdminLayout>
        <div class="mb-6 flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.25em] text-primary">
                    Ferramenta interna
                </p>
                <h1 class="mt-1 text-2xl font-bold text-[var(--text-primary)] md:text-3xl">
                    Baixar vídeos
                </h1>
                <p class="mt-1 max-w-3xl text-sm text-slate-500">
                    Cole uma URL pública ou autorizada do YouTube, TikTok, Facebook ou Instagram e baixe o arquivo pelo navegador.
                </p>
            </div>
        </div>

        <section class="mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-[#1f1a1c] via-[#2b2326] to-[#4a2616] p-5 text-white shadow-2xl shadow-black/10 md:p-7">
            <div class="grid gap-6 lg:grid-cols-[1.25fr_0.75fr] lg:items-center">
                <div>
                    <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white/10 text-xl">
                        <i class="fas fa-video"></i>
                    </div>

                    <h2 class="text-2xl font-black md:text-3xl">
                        Baixar vídeo para uso interno
                    </h2>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300">
                        Cole o link: a prévia aparece, o download começa sozinho e, quando terminar, abre a janela para escolher onde salvar. Vídeos do TikTok vêm sem marca d'água.
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
                                :disabled="!canSave"
                            >
                                <span v-if="previewLoading">
                                    <i class="fas fa-spinner mr-2 animate-spin"></i>
                                    Buscando
                                </span>
                                <span v-else-if="isProcessing">
                                    <i class="fas fa-spinner mr-2 animate-spin"></i>
                                    Preparando
                                </span>
                                <span v-else-if="videoReady">
                                    <i class="fas fa-floppy-disk mr-2"></i>
                                    Salvar no computador
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

                <div v-if="preview || previewLoading" class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
                    <div class="relative mx-auto aspect-video w-full overflow-hidden rounded-xl bg-black/40">
                        <img
                            v-if="preview?.thumbnail"
                            :src="preview.thumbnail"
                            :alt="preview.title"
                            class="h-full w-full object-contain"
                        >
                        <div v-else class="flex h-full w-full items-center justify-center text-3xl text-white/40">
                            <i :class="previewLoading ? 'fas fa-spinner animate-spin' : 'fas fa-film'"></i>
                        </div>
                        <span
                            v-if="formatDuration(preview?.duration)"
                            class="absolute bottom-2 right-2 rounded-md bg-black/75 px-2 py-0.5 text-xs font-semibold"
                        >
                            {{ formatDuration(preview.duration) }}
                        </span>
                    </div>

                    <template v-if="preview">
                        <p class="mt-3 line-clamp-2 text-sm font-semibold">{{ preview.title }}</p>
                        <p v-if="preview.uploader" class="mt-1 truncate text-xs text-slate-300">{{ preview.uploader }}</p>

                        <div class="mt-3 flex flex-wrap gap-2 text-xs font-semibold">
                            <span v-if="preview.semMarcaDagua" class="rounded-full bg-emerald-400/15 px-3 py-1 text-emerald-200 ring-1 ring-emerald-300/30">
                                <i class="fas fa-check mr-1"></i>
                                Sem marca d'água
                            </span>
                            <span v-if="isProcessing" class="rounded-full bg-white/10 px-3 py-1 text-slate-200">
                                <i class="fas fa-spinner mr-1 animate-spin"></i>
                                Preparando arquivo
                            </span>
                            <span v-else-if="videoReady" class="rounded-full bg-white/10 px-3 py-1 text-slate-200">
                                <i class="fas fa-circle-check mr-1"></i>
                                Pronto para salvar
                            </span>
                        </div>
                    </template>
                    <p v-else class="mt-3 text-sm text-slate-300">Buscando a prévia do vídeo...</p>
                </div>

                <div v-else class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur">
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
                    <li class="flex gap-3"><span class="font-bold text-primary">3.</span> Confira a prévia; o download começa sozinho.</li>
                    <li class="flex gap-3"><span class="font-bold text-primary">4.</span> Escolha a pasta na janela "Salvar como" (ou clique em “Salvar no computador”).</li>
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
