<script setup>
// Logos e favicon (pedido 2026-10-10): logo do topo, logo do rodapé e favicon,
// com dica de medida e conferência de tipo/tamanho antes de enviar.
import InputError from '@/Shared/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';

const props = defineProps({
    marca: { type: Object, required: true },
});

const ITENS = [
    {
        chave: 'logo_nav', titulo: 'Logo do topo (menu)', atual: () => props.marca.logoNav, fundo: 'bg-white',
        dica: 'PNG com fundo transparente, letras escuras. Medida recomendada 520 × 92 px (formato deitado, ~5,6:1). Até 2 MB.',
        aceita: '.png,.webp,.jpg,.jpeg', tipos: ['image/png', 'image/webp', 'image/jpeg'], maxKb: 2048,
    },
    {
        chave: 'logo_rodape', titulo: 'Logo do rodapé', atual: () => props.marca.logoRodape, fundo: 'bg-[#0b0b0b]',
        dica: 'PNG com fundo transparente e letras claras (o rodapé é escuro). Medida recomendada 850 × 150 px. Até 2 MB.',
        aceita: '.png,.webp,.jpg,.jpeg', tipos: ['image/png', 'image/webp', 'image/jpeg'], maxKb: 2048,
    },
    {
        chave: 'favicon', titulo: 'Favicon (ícone da aba)', atual: () => props.marca.favicon, fundo: 'bg-slate-100',
        dica: 'PNG quadrado de 512 × 512 px (aceita de 32 a 1024 px) ou arquivo .ico. Até 512 KB. Vale para a loja e o admin.',
        aceita: '.png,.ico', tipos: ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'], maxKb: 512, quadrado: true,
    },
];

const form = useForm({ logo_nav: null, logo_rodape: null, favicon: null, restaurar: [] });
const previa = reactive({});
const erroLocal = reactive({});

const medir = (arquivo) => new Promise((resolve) => {
    if (!arquivo.type.startsWith('image/') || arquivo.type.includes('icon')) return resolve(null);
    const img = new Image();
    img.onload = () => resolve({ largura: img.naturalWidth, altura: img.naturalHeight });
    img.onerror = () => resolve(null);
    img.src = URL.createObjectURL(arquivo);
});

const escolher = async (item, evento) => {
    const arquivo = evento.target.files[0] ?? null;
    erroLocal[item.chave] = null;
    form[item.chave] = null;
    previa[item.chave] = null;
    if (!arquivo) return;

    const extensao = arquivo.name.split('.').pop().toLowerCase();
    if (!item.tipos.includes(arquivo.type) && !item.aceita.includes(`.${extensao}`)) {
        erroLocal[item.chave] = `Tipo de arquivo não aceito. Use ${item.aceita.replaceAll(',', ', ')}.`;
        evento.target.value = '';
        return;
    }
    if (arquivo.size > item.maxKb * 1024) {
        erroLocal[item.chave] = `Arquivo grande demais: ${(arquivo.size / 1024).toFixed(0)} KB (máximo ${item.maxKb >= 1024 ? `${item.maxKb / 1024} MB` : `${item.maxKb} KB`}).`;
        evento.target.value = '';
        return;
    }
    const medida = await medir(arquivo);
    if (item.quadrado && medida && medida.largura !== medida.altura) {
        erroLocal[item.chave] = `O favicon precisa ser quadrado (este tem ${medida.largura} × ${medida.altura} px).`;
        evento.target.value = '';
        return;
    }
    if (!item.quadrado && medida && medida.largura < 200) {
        erroLocal[item.chave] = `Imagem pequena demais (${medida.largura} px de largura; mínimo 200 px).`;
        evento.target.value = '';
        return;
    }

    form[item.chave] = arquivo;
    previa[item.chave] = URL.createObjectURL(arquivo);
    form.restaurar = form.restaurar.filter((chave) => chave !== item.chave);
};

const restaurar = (item) => {
    form[item.chave] = null;
    previa[item.chave] = null;
    if (!form.restaurar.includes(item.chave)) form.restaurar.push(item.chave);
};

const salvar = () => {
    form.post('/admin/banners/marca', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => { form.reset(); Object.keys(previa).forEach((chave) => { previa[chave] = null; }); },
    });
};

const temMudanca = () => ITENS.some((item) => form[item.chave]) || form.restaurar.length > 0;
</script>

<template>
    <section class="mt-10">
        <h2 class="mb-1 text-xl font-bold">Logos e favicon</h2>
        <p class="mb-5 text-sm text-slate-500 dark:text-slate-400">
            Aparecem no topo e no rodapé da loja, no checkout e na aba do navegador (loja e admin). Envie só o que quiser trocar.
        </p>

        <form class="grid grid-cols-1 gap-4 lg:grid-cols-3" @submit.prevent="salvar">
            <div v-for="item in ITENS" :key="item.chave"
                class="flex flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 shadow-sm">
                <h3 class="font-semibold">{{ item.titulo }}</h3>

                <div class="mt-3 flex h-28 items-center justify-center rounded-lg border border-dashed border-slate-300 p-3" :class="item.fundo">
                    <span v-if="form.restaurar.includes(item.chave)" class="text-xs text-slate-400">Volta para o padrão da loja ao salvar</span>
                    <img v-else :src="previa[item.chave] ?? item.atual()" :alt="item.titulo"
                        class="max-h-full max-w-full object-contain" :class="item.quadrado ? 'h-16 w-16' : ''">
                </div>
                <p class="mt-1 text-[11px] text-slate-400">
                    {{ previa[item.chave] ? 'Prévia do arquivo novo' : (marca.personalizado?.[item.chave] ? 'Enviado pelo admin' : 'Padrão da loja') }}
                </p>

                <p class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-xs text-sky-800 dark:bg-sky-500/10 dark:text-sky-200">
                    <i class="fas fa-ruler-combined mr-1"></i> {{ item.dica }}
                </p>

                <label class="mt-3 cursor-pointer rounded-lg border border-[var(--surface-border)] px-3 py-2 text-center text-sm font-medium hover:bg-slate-50 dark:hover:bg-slate-800">
                    <i class="fas fa-upload mr-1"></i> Escolher arquivo
                    <input type="file" class="hidden" :accept="item.aceita" @change="escolher(item, $event)">
                </label>
                <button v-if="marca.personalizado?.[item.chave] && !form.restaurar.includes(item.chave)" type="button"
                    class="mt-2 text-xs text-slate-500 underline hover:text-red-600" @click="restaurar(item)">
                    Voltar ao padrão da loja
                </button>
                <InputError :message="erroLocal[item.chave] || form.errors[item.chave]" />
            </div>

            <div class="lg:col-span-3">
                <button type="submit" :disabled="form.processing || !temMudanca()"
                    class="rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-emphasis disabled:opacity-50">
                    <i class="fas mr-1" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-check'"></i>
                    {{ form.processing ? 'Enviando...' : 'Salvar logos e favicon' }}
                </button>
            </div>
        </form>
    </section>
</template>
