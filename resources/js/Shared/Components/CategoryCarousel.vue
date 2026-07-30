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
            class="flex gap-6 pb-1"
            :class="isCarousel ? 'no-scrollbar snap-x snap-mandatory overflow-x-auto scroll-smooth' : 'flex-wrap justify-center'">
            <a v-for="category in categories" :key="category.id" href="#produtos"
                class="flex w-[141px] shrink-0 snap-start flex-col items-center gap-2 text-center text-store-fg no-underline">
                <div class="h-[141px] w-[141px] overflow-hidden rounded-full border border-store-border bg-store-bg-raised">
                    <img v-if="category.image_url" :src="category.image_url" :alt="category.name" class="h-full w-full object-cover">
                    <div v-else class="flex h-full w-full items-center justify-center">
                        <i class="fas fa-tag text-4xl text-store-accent opacity-50"></i>
                    </div>
                </div>
                <span class="text-sm font-medium leading-tight">{{ category.name }}</span>
            </a>
        </div>

        <template v-if="isCarousel">
            <button type="button" aria-label="Categorias anteriores"
                class="absolute left-0 top-[53px] flex h-9 w-9 -translate-x-1/2 items-center justify-center rounded-full border border-store-border-strong bg-store-bg-raised shadow"
                @click="scrollByPage(-1)">
                <i class="fas fa-chevron-left text-sm"></i>
            </button>
            <button type="button" aria-label="Próximas categorias"
                class="absolute right-0 top-[53px] flex h-9 w-9 translate-x-1/2 items-center justify-center rounded-full border border-store-border-strong bg-store-bg-raised shadow"
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
