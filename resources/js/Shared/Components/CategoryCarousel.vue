<script setup>
// Departamentos (pedido 2026-10-10): círculos com imagem, compactos. Clicar
// filtra a vitrine pelo departamento; clicar de novo no ativo tira o filtro.
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    categories: {
        type: Array,
        required: true,
    },
    ativa: {
        type: String,
        default: null,
    },
});

const scroller = ref(null);
const isCarousel = computed(() => props.categories.length > 8);

const scrollByPage = (direction) => {
    const el = scroller.value;
    if (!el) return;
    el.scrollBy({ left: direction * el.clientWidth * 0.8, behavior: 'smooth' });
};

const hrefDe = (category) => (props.ativa === category.slug ? '/#produtos' : `/?categoria=${category.slug}#produtos`);
</script>

<template>
    <div class="relative">
        <div ref="scroller"
            class="flex gap-4 pb-2 md:gap-6"
            :class="isCarousel ? 'no-scrollbar snap-x snap-mandatory overflow-x-auto scroll-smooth px-1' : 'flex-wrap justify-center'">
            <Link v-for="category in categories" :key="category.id" :href="hrefDe(category)"
                class="group flex w-[76px] shrink-0 snap-start flex-col items-center gap-2 text-center text-store-fg no-underline md:w-[96px]">
                <span class="flex h-[68px] w-[68px] items-center justify-center overflow-hidden rounded-full border-2 bg-white shadow-sm transition group-hover:-translate-y-0.5 group-hover:shadow-md md:h-[84px] md:w-[84px]"
                    :class="ativa === category.slug ? 'border-store-accent' : 'border-store-border'">
                    <img v-if="category.image_url" :src="category.image_url" :alt="category.name" loading="lazy" decoding="async"
                        class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                    <i v-else class="fas fa-tag text-xl text-store-fg-faint"></i>
                </span>
                <span class="line-clamp-2 text-xs font-semibold leading-tight md:text-[13px]"
                    :class="{ 'underline underline-offset-2': ativa === category.slug }">{{ category.name }}</span>
            </Link>
        </div>

        <template v-if="isCarousel">
            <button type="button" aria-label="Departamentos anteriores"
                class="absolute left-0 top-[34px] hidden h-9 w-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-store-border bg-store-bg-raised/95 text-store-fg shadow-md backdrop-blur transition hover:border-store-border-strong md:top-[42px] md:flex"
                @click="scrollByPage(-1)">
                <i class="fas fa-chevron-left text-sm"></i>
            </button>
            <button type="button" aria-label="Próximos departamentos"
                class="absolute right-0 top-[34px] hidden h-9 w-9 -translate-y-1/2 translate-x-1/2 items-center justify-center rounded-full border border-store-border bg-store-bg-raised/95 text-store-fg shadow-md backdrop-blur transition hover:border-store-border-strong md:top-[42px] md:flex"
                @click="scrollByPage(1)">
                <i class="fas fa-chevron-right text-sm"></i>
            </button>
        </template>
    </div>
</template>

<style scoped>
.no-scrollbar {
    scrollbar-width: none;
}
.no-scrollbar::-webkit-scrollbar {
    display: none;
}
</style>
