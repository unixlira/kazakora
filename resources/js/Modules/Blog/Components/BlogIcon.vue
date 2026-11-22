<script setup>
// Ícones de traço único do Caderno (SVG inline, 24x24, traço 1.8).
defineProps({ name: { type: String, required: true }, filled: Boolean });
</script>
<template>
  <svg class="blog-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <path v-if="name === 'heart'" :fill="filled ? 'currentColor' : 'none'" d="M12 20.5s-7.5-4.6-9.2-9.4C1.6 7.6 3.9 4 7.4 4c2 0 3.6 1.1 4.6 2.7C13 5.1 14.6 4 16.6 4c3.5 0 5.8 3.6 4.6 7.1-1.7 4.8-9.2 9.4-9.2 9.4z"/>
    <path v-else-if="name === 'comment'" d="M4 5.5h16v10H9.5L5 19.5v-4H4z"/>
    <template v-else-if="name === 'share'"><circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="M8.2 10.8l7.6-4.1M8.2 13.2l7.6 4.1"/></template>
    <template v-else-if="name === 'link'"><path d="M10 14a4.5 4.5 0 006.4 0l3-3a4.5 4.5 0 00-6.4-6.4l-1.2 1.2"/><path d="M14 10a4.5 4.5 0 00-6.4 0l-3 3a4.5 4.5 0 006.4 6.4l1.2-1.2"/></template>
    <template v-else-if="name === 'whatsapp'"><path d="M4.5 19.5l1.2-3.9A8 8 0 1112 20a8 8 0 01-3.6-.9z"/><path d="M9.2 8.6c.2-.5.5-.6.8-.6h.5l.9 2.1-.6.8c.5 1 1.3 1.8 2.3 2.3l.8-.6 2.1.9v.5c0 .3-.1.6-.6.8-1.6.8-6.8-3.3-6.2-6.2z"/></template>
    <template v-else-if="name === 'clock'"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></template>
    <path v-else-if="name === 'arrow'" d="M5 12h14M13 6l6 6-6 6"/>
    <path v-else-if="name === 'arrow-left'" d="M19 12H5M11 6l-6 6 6 6"/>
    <path v-else-if="name === 'close'" d="M6 6l12 12M18 6L6 18"/>
    <template v-else-if="name === 'menu'"><path d="M4 7h16M4 12h16M4 17h16"/></template>
    <template v-else-if="name === 'play'"><path d="M8 5.5v13l10.5-6.5z"/></template>
  </svg>
</template>
