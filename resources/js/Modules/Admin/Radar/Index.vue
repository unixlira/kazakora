<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { RADAR_NOME } from '@/Shared/radarNome';
import { Head } from '@inertiajs/vue3';

defineProps({
    canal: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`${RADAR_NOME} · ${canal.nome}`" />
    <AdminLayout>
        <section class="rounded-2xl bg-white p-6 shadow-lg shadow-slate-200/80 sm:p-8">
            <p class="text-sm font-semibold text-slate-500">{{ RADAR_NOME }}</p>
            <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ canal.nome }}</h1>
            <p class="mt-2 text-sm text-slate-600">{{ canal.descricao }}</p>

            <div class="mt-8 flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 px-6 py-14 text-center">
                <i class="text-3xl text-slate-400" :class="canal.icone" aria-hidden="true"></i>
                <p class="text-lg font-bold text-slate-700">Em construção</p>
                <p class="max-w-md text-sm text-slate-500">O {{ RADAR_NOME }} do {{ canal.nome }} ainda está sendo preparado.</p>
            </div>
        </section>
    </AdminLayout>
</template>
