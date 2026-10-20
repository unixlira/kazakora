<script setup>
// Equipe › membro (pedido do Lira 2026-10-20): as skills atuais do agente
// (config/equipe.php) e os comandos extras para turbinar a skill. Comando
// não se apaga: desativar tira do agente e dá para reativar depois.
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    membro: { type: Object, required: true },
    comandos: { type: Array, default: () => [] },
});

const LIMITE = 2000;
const form = useForm({ texto: '' });
const salvando = ref(null);
const ativos = computed(() => props.comandos.filter((c) => c.ativo).length);

const adicionar = () => {
    form.post(`/admin/equipe/${props.membro.slug}/comandos`, {
        preserveScroll: true,
        onSuccess: () => form.reset('texto'),
    });
};

const alternar = (comando) => {
    salvando.value = comando.id;
    router.patch(`/admin/equipe/${props.membro.slug}/comandos/${comando.id}`, { ativo: !comando.ativo }, {
        preserveScroll: true,
        onFinish: () => { salvando.value = null; },
    });
};
</script>

<template>
    <Head :title="`Equipe · ${membro.nome}`" />
    <AdminLayout>
        <div class="space-y-6" :class="`icone-cor-${membro.cor}`">
            <Link href="/admin/equipe" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:underline">
                <i class="fas fa-arrow-left text-xs" aria-hidden="true"></i> Equipe
            </Link>

            <header class="flex flex-col gap-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm sm:flex-row sm:items-center">
                <span class="avatar-cor flex h-16 w-16 shrink-0 items-center justify-center rounded-full text-2xl font-bold" aria-hidden="true">{{ membro.inicial }}</span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-2xl font-bold">{{ membro.nome }}</h1>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold fundo-cor-suave">
                            <i class="icone-cor mr-1" :class="membro.icone" aria-hidden="true"></i>{{ membro.cargo }}
                        </span>
                    </div>
                    <p class="mt-2 max-w-3xl text-sm text-slate-600 dark:text-slate-300">{{ membro.descricao }}</p>
                </div>
            </header>

            <div class="grid gap-6 lg:grid-cols-5">
                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm lg:col-span-2" aria-labelledby="titulo-skills">
                    <h2 id="titulo-skills" class="text-lg font-semibold">Skills atuais</h2>
                    <p class="mt-1 text-xs text-slate-500">Funções e responsabilidades de hoje.</p>
                    <ul class="mt-4 list-none space-y-2.5">
                        <li v-for="(skill, i) in membro.skills" :key="i" class="flex gap-2.5 text-sm">
                            <i class="fas fa-circle-check icone-cor mt-0.5" aria-hidden="true"></i>
                            <span>{{ skill }}</span>
                        </li>
                    </ul>
                    <p v-if="membro.limites" class="mt-5 rounded-lg border border-[var(--surface-border)] bg-[var(--surface-muted)] p-3 text-xs text-slate-600 dark:text-slate-300">
                        <i class="fas fa-shield-halved mr-1 text-slate-500" aria-hidden="true"></i>{{ membro.limites }}
                    </p>
                </section>

                <section class="rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm lg:col-span-3" aria-labelledby="titulo-comandos">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 id="titulo-comandos" class="text-lg font-semibold">Adicionar comandos</h2>
                        <span class="text-xs text-slate-500">{{ ativos }} ativo(s)</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Instruções extras para turbinar a skill de {{ membro.nome }}. Só os ativos chegam até {{ membro.slug === 'manuela' ? 'ela' : 'o agente' }}; as regras de segurança continuam valendo acima de tudo.</p>

                    <form class="mt-4" @submit.prevent="adicionar">
                        <label for="novo-comando" class="sr-only">Novo comando para {{ membro.nome }}</label>
                        <textarea id="novo-comando" v-model="form.texto" rows="4" :maxlength="LIMITE" name="texto" autocomplete="off"
                            :placeholder="`Escreva uma instrução nova para ${membro.nome}`"
                            class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--cor-icone)]"
                            :aria-invalid="form.errors.texto ? 'true' : undefined" aria-describedby="comando-ajuda"></textarea>
                        <div class="mt-2 flex items-center justify-between gap-3">
                            <p id="comando-ajuda" class="text-xs" :class="form.errors.texto ? 'text-red-600' : 'text-slate-500'" aria-live="polite">
                                {{ form.errors.texto || `${form.texto.length}/${LIMITE}` }}
                            </p>
                            <button type="submit" class="avatar-cor rounded-lg px-4 py-2 text-sm font-semibold hover:opacity-90 disabled:opacity-60"
                                :disabled="form.processing || !form.texto.trim()">
                                {{ form.processing ? 'Adicionando…' : 'Adicionar' }}
                            </button>
                        </div>
                    </form>

                    <p v-if="!comandos.length" class="mt-6 text-sm text-slate-500">Nenhum comando extra ainda.</p>
                    <ul v-else class="mt-6 list-none divide-y divide-[var(--surface-border)]">
                        <li v-for="c in comandos" :key="c.id" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start">
                            <div class="min-w-0 flex-1" :class="{ 'opacity-60': !c.ativo }">
                                <p class="whitespace-pre-line break-words text-sm" :class="{ 'line-through': !c.ativo }">{{ c.texto }}</p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ c.autor }} · {{ c.criadoEm }}
                                    <span v-if="!c.ativo"> · desativado em {{ c.alteradoEm }}</span>
                                </p>
                            </div>
                            <button type="button" class="shrink-0 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-xs font-medium hover:bg-[var(--surface-muted)] disabled:opacity-60"
                                :disabled="salvando === c.id" @click="alternar(c)">
                                {{ salvando === c.id ? 'Salvando…' : (c.ativo ? 'Desativar' : 'Reativar') }}
                            </button>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
