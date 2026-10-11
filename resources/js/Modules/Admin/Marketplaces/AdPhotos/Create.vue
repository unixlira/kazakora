<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    marketplaces: {
        type: Object,
        default: () => ({}),
    },
    limits: {
        type: Object,
        default: () => ({ description: 6000, immutableNotes: 2000, referenceLinks: 2000, imageMaxMb: 10 }),
    },
    generationStatus: {
        type: Object,
        default: () => ({}),
    },
});

const page = usePage();
const flashSuccess = computed(() => page.props.flash?.success);
const imagePreview = ref(null);
const dragActive = ref(false);

const form = useForm({
    marketplace: 'shopee',
    product_name: '',
    category_hint: '',
    description: '',
    immutable_notes: '',
    reference_links: '',
    product_image: null,
});

const selectedMarketplace = computed(() => props.marketplaces[form.marketplace] ?? props.marketplaces.shopee ?? {});
const descriptionCount = computed(() => form.description.length);
const immutableCount = computed(() => form.immutable_notes.length);
const referenceCount = computed(() => form.reference_links.length);
const canSubmit = computed(() => form.description.trim().length > 0 && !form.processing);

const setImage = (file) => {
    if (!file) return;

    form.product_image = file;
    imagePreview.value = URL.createObjectURL(file);
};

const onFileChange = (event) => {
    setImage(event.target.files?.[0]);
};

const onDrop = (event) => {
    dragActive.value = false;
    setImage(event.dataTransfer.files?.[0]);
};

const submit = () => {
    form.post('/admin/marketplaces/fotos-anuncio', {
        preserveScroll: true,
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Criar Foto de Anúncio" />

    <AdminLayout>
        <section class="overflow-hidden rounded-3xl bg-slate-950 text-white shadow-2xl shadow-slate-300">
            <div class="relative px-6 py-8 sm:px-8 lg:px-10">
                <div class="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-purple-400/25 blur-3xl"></div>
                <div class="absolute bottom-0 left-1/3 h-52 w-52 rounded-full bg-fuchsia-400/10 blur-3xl"></div>

                <div class="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-4xl">
                        <span class="inline-flex items-center rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.24em] text-purple-100 ring-1 ring-white/15">
                            Novo produto · Matriz automática
                        </span>
                        <h1 class="mt-5 text-3xl font-black tracking-tight sm:text-4xl lg:text-5xl">
                            Criar foto de anúncio
                        </h1>
                        <p class="mt-4 max-w-3xl text-sm leading-6 text-slate-300 sm:text-base">
                            Envie foto, descrição e categoria. A KazaKora monta títulos, descrições Shopee/ML, dados técnicos seguros e a matriz de imagens para aprovação.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/10 bg-white/10 p-5 text-sm text-slate-200 backdrop-blur xl:max-w-sm">
                        <p class="font-bold text-white">{{ generationStatus.label }}</p>
                        <p class="mt-2 leading-6">{{ generationStatus.guardrail }}</p>
                    </div>
                </div>
            </div>
        </section>

        <section v-if="flashSuccess" class="mt-5 rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 shadow-sm">
            {{ flashSuccess }}
        </section>

        <form class="mt-6 grid gap-6 xl:grid-cols-[minmax(0,0.95fr)_minmax(420px,1.05fr)]" @submit.prevent="submit">
            <section class="space-y-5 rounded-3xl bg-white p-5 shadow-2xl shadow-slate-200/80 sm:p-6">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.2em] text-slate-400">Entrada do produto</p>
                    <h2 class="mt-2 text-2xl font-black text-slate-900">Produto automático</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-500">
                        Preencha o mínimo real. Fiscal, NCM e GTIN ficam pendentes quando não houver dado verificado.
                    </p>
                </div>

                <label class="block">
                    <span class="text-sm font-black text-slate-700">Marketplace principal</span>
                    <select v-model="form.marketplace" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-800 outline-none transition focus:border-purple-400 focus:bg-white focus:ring-4 focus:ring-purple-100">
                        <option v-for="(marketplace, key) in marketplaces" :key="key" :value="key">
                            {{ marketplace.label }} · {{ marketplace.imageCount }} imagens
                        </option>
                    </select>
                    <p class="mt-2 text-xs leading-5 text-slate-500">{{ selectedMarketplace.tone }}</p>
                    <p v-if="form.errors.marketplace" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.marketplace }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-black text-slate-700">Nome do produto</span>
                    <input v-model="form.product_name" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-purple-400 focus:bg-white focus:ring-4 focus:ring-purple-100" placeholder="Ex: Organizador dobrável multiuso">
                    <p v-if="form.errors.product_name" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.product_name }}</p>
                </label>

                <label class="block">
                    <span class="text-sm font-black text-slate-700">Categoria</span>
                    <input v-model="form.category_hint" type="text" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none transition focus:border-purple-400 focus:bg-white focus:ring-4 focus:ring-purple-100" placeholder="Ex: Casa e Decoração · Organizadores">
                    <p v-if="form.errors.category_hint" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.category_hint }}</p>
                </label>

                <div>
                    <span class="text-sm font-black text-slate-700">Foto do produto</span>
                    <label
                        class="mt-2 flex min-h-52 cursor-pointer flex-col items-center justify-center rounded-3xl border-2 border-dashed bg-slate-50 p-5 text-center transition"
                        :class="dragActive ? 'border-purple-400 bg-purple-50' : 'border-slate-200 hover:border-purple-300'"
                        @dragover.prevent="dragActive = true"
                        @dragleave.prevent="dragActive = false"
                        @drop.prevent="onDrop"
                    >
                        <input type="file" class="hidden" accept="image/*" @change="onFileChange">
                        <img v-if="imagePreview" :src="imagePreview" alt="Prévia do produto" class="mb-4 max-h-44 rounded-2xl object-contain ring-1 ring-slate-100">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white text-lg text-emerald-600 shadow-sm">
                            <i class="fas fa-cloud-arrow-up"></i>
                        </span>
                        <span class="mt-3 text-sm font-black text-slate-800">Arraste a foto aqui ou clique para enviar</span>
                        <span class="mt-1 text-xs text-slate-500">PNG, JPG ou WebP até {{ limits.imageMaxMb }} MB</span>
                    </label>
                    <p v-if="form.errors.product_image" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.product_image }}</p>
                </div>
            </section>

            <section class="space-y-5 rounded-3xl bg-white p-5 shadow-2xl shadow-slate-200/80 sm:p-6">
                <label class="block">
                    <span class="flex items-center justify-between gap-3 text-sm font-black text-slate-700">
                        Descrição e ficha técnica
                        <small class="font-bold text-slate-400">{{ descriptionCount }}/{{ limits.description }}</small>
                    </span>
                    <textarea v-model="form.description" rows="9" :maxlength="limits.description" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-800 outline-none transition focus:border-purple-400 focus:bg-white focus:ring-4 focus:ring-purple-100" placeholder="Cole a descrição completa, medidas, materiais, benefícios, compatibilidades e observações comerciais."></textarea>
                    <p v-if="form.errors.description" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.description }}</p>
                </label>

                <label class="block">
                    <span class="flex items-center justify-between gap-3 text-sm font-black text-slate-700">
                        O que não pode mudar
                        <small class="font-bold text-slate-400">{{ immutableCount }}/{{ limits.immutableNotes }}</small>
                    </span>
                    <textarea v-model="form.immutable_notes" rows="4" :maxlength="limits.immutableNotes" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-800 outline-none transition focus:border-purple-400 focus:bg-white focus:ring-4 focus:ring-purple-100" placeholder="Ex: cor real, formato, proporção, embalagem, rótulo, tecido, medidas, ferragens, modelo..."></textarea>
                    <p v-if="form.errors.immutable_notes" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.immutable_notes }}</p>
                </label>

                <label class="block">
                    <span class="flex items-center justify-between gap-3 text-sm font-black text-slate-700">
                        Links e referências
                        <small class="font-bold text-slate-400">{{ referenceCount }}/{{ limits.referenceLinks }}</small>
                    </span>
                    <textarea v-model="form.reference_links" rows="3" :maxlength="limits.referenceLinks" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-800 outline-none transition focus:border-purple-400 focus:bg-white focus:ring-4 focus:ring-purple-100" placeholder="Cole anúncio atual, fornecedor, concorrentes ou referências visuais."></textarea>
                    <p v-if="form.errors.reference_links" class="mt-2 text-xs font-bold text-red-600">{{ form.errors.reference_links }}</p>
                </label>

                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm leading-6 text-amber-800">
                    <p class="font-black">Fluxo seguro</p>
                    <p class="mt-1">O botão cria produto automático em rascunho, títulos, descrições e matriz. Publicar na Shopee/Mercado Livre só depois da sua aprovação final.</p>
                </div>

                <div class="flex flex-col gap-3 sm:flex-row">
                    <Link href="/admin/marketplaces/fotos-anuncio" class="rounded-2xl bg-slate-100 px-5 py-4 text-center text-sm font-black uppercase tracking-wide text-slate-600 transition hover:bg-slate-200 sm:w-44">
                        Voltar
                    </Link>
                    <button type="submit" :disabled="!canSubmit" class="flex-1 rounded-2xl bg-slate-950 px-5 py-4 text-sm font-black uppercase tracking-wide text-white shadow-xl transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ form.processing ? 'Gerando produto...' : 'Gerar produto automaticamente' }}
                    </button>
                </div>
            </section>
        </form>
    </AdminLayout>
</template>
