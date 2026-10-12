<script setup>
// Logo de cada fonte do Radar no selo com a cor da marca (pedido do Lira
// 2026-10-12: manter logo e cor de cada plataforma para bater o olho).
defineProps({
    marca: { type: String, required: true }, // tiktok | ml | google
});
</script>

<template>
    <span class="marca" :class="`marca-${marca}`" aria-hidden="true">
        <svg v-if="marca === 'tiktok'" viewBox="0 0 24 24" class="tiktok">
            <path fill="currentColor" d="M12.53.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z" />
        </svg>
        <svg v-else-if="marca === 'ml'" viewBox="0 0 24 24">
            <!-- Mãozinhas em branco sobre o oval azul (pedido do Lira 2026-10-12: mais contraste). -->
            <ellipse cx="12" cy="12" rx="10.6" ry="7.6" fill="#2d3277" />
            <path d="M5.2 11.2l3-2.1 2.6 1.1 2.2-1.4 2.9.4 2.9 2.2M8.4 13.2l1.4 1.3m.6-2.4l2.3 2.2m.4-2.4l2 1.9m-7.7-1.1l1.2 1.2" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <svg v-else viewBox="0 0 48 48">
            <path fill="#fbbc05" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 8 3l5.7-5.6C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z" />
            <path fill="#ea4335" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 8 3l5.7-5.6C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z" />
            <path fill="#34a853" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z" />
            <path fill="#4285f4" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z" />
        </svg>
    </span>
</template>

<style scoped>
.marca {
    display: inline-grid;
    flex: none;
    place-items: center;
    width: var(--marca-tamanho, 2.5rem);
    height: var(--marca-tamanho, 2.5rem);
    border-radius: var(--marca-raio, 10px);
}
.marca svg { width: 60%; height: 60%; }
.marca-tiktok { background: #000000; color: #ffffff; }
.marca-tiktok .tiktok { filter: drop-shadow(-1px -1px 0 #25f4ee) drop-shadow(1px 1px 0 #fe2c55); }
.marca-ml { background: #ffe600; }
.marca-google { background: #ffffff; border: 1px solid #e6e2e3; }
</style>
