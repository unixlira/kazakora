<script setup>
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    banners: {
        type: Array,
        required: true,
    },
});

const current = ref(0);
let timer = null;

const start = () => {
    stop();
    if (props.banners.length > 1) {
        timer = setInterval(next, 5000);
    }
};

const stop = () => {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
};

const next = () => {
    current.value = (current.value + 1) % props.banners.length;
};

const prev = () => {
    current.value = (current.value - 1 + props.banners.length) % props.banners.length;
};

const goTo = (index) => {
    current.value = index;
    start();
};

onMounted(start);
onUnmounted(stop);
</script>

<template>
    <section
        class="group relative w-full overflow-hidden"
        @mouseenter="stop"
        @mouseleave="start"
    >
        <div class="relative aspect-square w-full md:aspect-[21/9] lg:aspect-[3/1]">
            <template v-for="(banner, index) in banners" :key="banner.id">
                <component
                    :is="banner.link_url ? 'a' : 'div'"
                    v-show="index === current"
                    :href="banner.link_url || undefined"
                    class="absolute inset-0 block"
                >
                    <img :src="banner.image_url" :alt="banner.title || 'Banner promocional'" class="hidden h-full w-full object-cover md:block">
                    <img :src="banner.image_url_mobile || banner.image_url" :alt="banner.title || 'Banner promocional'" class="block h-full w-full object-cover md:hidden">
                    <div v-if="banner.title" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent p-4 sm:p-6">
                        <p class="font-display text-lg font-semibold text-white sm:text-2xl">{{ banner.title }}</p>
                    </div>
                </component>
            </template>
        </div>

        <template v-if="banners.length > 1">
            <button type="button" aria-label="Banner anterior"
                class="absolute left-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-store-fg opacity-0 shadow transition-opacity group-hover:opacity-100 sm:left-4"
                @click="prev(); start()">
                <i class="fas fa-chevron-left text-sm"></i>
            </button>
            <button type="button" aria-label="Próximo banner"
                class="absolute right-2 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-full bg-white/80 text-store-fg opacity-0 shadow transition-opacity group-hover:opacity-100 sm:right-4"
                @click="next(); start()">
                <i class="fas fa-chevron-right text-sm"></i>
            </button>

            <div class="absolute inset-x-0 bottom-3 flex justify-center gap-2">
                <button v-for="(banner, index) in banners" :key="banner.id" type="button"
                    :aria-label="`Ir para o banner ${index + 1}`"
                    class="h-2 rounded-full bg-store-accent transition-all"
                    :class="index === current ? 'w-6 opacity-100' : 'w-2 opacity-50'"
                    @click="goTo(index)"
                />
            </div>
        </template>
    </section>
</template>
