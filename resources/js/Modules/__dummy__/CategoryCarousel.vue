<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    categories: {
        type: Array,
        required: true,
    },
});

const scroller = ref(null);
const isCarousel = computed(() => props.categories.length > 5);

const scrollByPage = (direction) => {
    const el = scroller.value;
    if (!el) return;
    el.scrollBy({ left: direction * el.clientWidth * 0.8, behavior: 'smooth' });
};
</script>

<template>
    <div class="relative">
        <div ref="scroller"
            class="flex gap-3 pb-2"
            :class="isCarousel ? 'no-scrollbar snap-x snap-mandatory overflow-x-auto scroll-smooth' : 'flex-wrap justify-center'">
            <a v-for="category in categories" :key="category.id" href="#produtos"
                class="group flex min-w-[156px] shrink-0 snap-start items-center gap-3 rounded-2xl border border-store-border bg-store-bg-raised/95 p-2.5 text-left text-store-fg no-underline shadow-sm transition hover:-translate-y-0.5 hover:border-store-border-strong hover:shadow-[0_14px_32px_var(--store-shadow)]">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-store-bg-sunken">
                    <img v-if="category.image_url" :src="category.image_url" :alt="category.name" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                    <i v-else class="fas fa-tag text-lg text-store-accent opacity-70"></i>
                </div>
                <span class="line-clamp-2 text-sm font-semibold leading-tight">{{ category.name }}</span>
            </a>
        </div>

        <template v-if="isCarousel">
            <button type="button" aria-label="Categorias anteriores"
                class="absolute left-0 top-1/2 flex h-9 w-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-store-border bg-store-bg-raised/95 text-store-fg shadow-md backdrop-blur transition hover:border-store-border-strong"
                @click="scrollByPage(-1)">
                <i class="fas fa-chevron-left text-sm"></i>
            </button>
            <button type="button" aria-label="Próximas categorias"
                class="absolute right-0 top-1/2 flex h-9 w-9 -translate-y-1/2 translate-x-1/2 items-center justify-center rounded-full border border-store-border bg-store-bg-raised/95 text-store-fg shadow-md backdrop-blur transition hover:border-store-border-strong"
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
