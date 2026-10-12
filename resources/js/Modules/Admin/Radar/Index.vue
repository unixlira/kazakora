<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { RADAR_NOME } from '@/Shared/radarNome';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head :title="RADAR_NOME" />
    <AdminLayout>
        <section class="rounded-2xl bg-white p-6 shadow-lg shadow-slate-200/80 sm:p-8">
            <p class="text-sm font-semibold text-slate-500">Oportunidades</p>
            <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">{{ RADAR_NOME }}</h1>
            <p class="mt-2 text-sm text-slate-600">Onde ajustar para vender mais: ADS e meta de ROAS, preço, qualidade do anúncio e promoções.</p>

            <div class="mt-8 flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 px-6 py-14 text-center">
                <i class="fas fa-satellite-dish text-3xl text-slate-400" aria-hidden="true"></i>
                <p class="text-lg font-bold text-slate-700">Em construção</p>
                <p class="max-w-md text-sm text-slate-500">Esta tela ainda está sendo preparada.</p>
            </div>
        </section>
    </AdminLayout>
</template>
