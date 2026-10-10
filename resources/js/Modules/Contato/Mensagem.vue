<script setup>
// Fale conosco > Mandar mensagem (pedido 2026-10-10). O campo "site" fica
// escondido: gente não vê nem preenche, robô preenche e a mensagem é descartada.
import AppLayout from '@/Shared/Layouts/AppLayout.vue';
import { COMPANY } from '@/Shared/company.js';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    formToken: { type: String, required: true },
});

const page = usePage();
const enviada = ref(false);

const form = useForm({
    nome: page.props.auth?.user?.name ?? '',
    email: page.props.auth?.user?.email ?? '',
    telefone: '',
    assunto: '',
    mensagem: '',
    site: '',
    form_token: props.formToken,
});

const enviar = () => form.post('/fale-conosco', {
    preserveScroll: true,
    onSuccess: () => {
        enviada.value = true;
        form.reset('assunto', 'mensagem', 'telefone');
    },
});

const campo = 'w-full rounded-lg border border-store-border-strong bg-store-bg px-3 py-2.5 text-sm font-normal text-store-fg focus:border-store-accent focus:outline-none';
</script>

<template>
    <Head title="Fale conosco" />

    <AppLayout>
        <div class="mx-auto grid max-w-[1040px] gap-8 px-4 py-12 md:grid-cols-[1fr_320px] md:px-6">
            <section>
                <h1 class="font-display text-3xl font-semibold">
                    <i class="fa-regular fa-envelope mr-2 text-store-accent"></i>Mandar mensagem
                </h1>
                <p class="mt-2 text-sm text-store-fg-muted">Escreva pra gente — respondemos no seu e-mail o quanto antes.</p>

                <div v-if="enviada" class="mt-8 rounded-2xl border border-green-200 bg-green-50 p-6 text-center text-green-800">
                    <i class="fa-solid fa-circle-check text-3xl"></i>
                    <p class="mt-2 font-semibold">Mensagem enviada!</p>
                    <p class="mt-1 text-sm">Já recebemos e vamos responder no seu e-mail.</p>
                    <button type="button" class="mt-4 text-sm font-semibold underline" @click="enviada = false">Mandar outra mensagem</button>
                </div>

                <form v-else class="relative mt-8 flex flex-col gap-4 rounded-2xl border border-store-border bg-store-bg-raised p-6" novalidate @submit.prevent="enviar">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-sm font-medium">
                            Nome
                            <input v-model="form.nome" type="text" autocomplete="name" maxlength="120" required :class="campo">
                            <span v-if="form.errors.nome" class="text-xs text-red-600">{{ form.errors.nome }}</span>
                        </label>
                        <label class="flex flex-col gap-1 text-sm font-medium">
                            E-mail
                            <input v-model="form.email" type="email" autocomplete="email" maxlength="160" required :class="campo">
                            <span v-if="form.errors.email" class="text-xs text-red-600">{{ form.errors.email }}</span>
                        </label>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex flex-col gap-1 text-sm font-medium">
                            <span>Telefone <span class="font-normal text-store-fg-faint">(opcional)</span></span>
                            <input v-model="form.telefone" type="tel" autocomplete="tel" maxlength="30" :class="campo">
                            <span v-if="form.errors.telefone" class="text-xs text-red-600">{{ form.errors.telefone }}</span>
                        </label>
                        <label class="flex flex-col gap-1 text-sm font-medium">
                            Assunto
                            <input v-model="form.assunto" type="text" maxlength="120" required placeholder="Ex.: dúvida sobre um produto" :class="campo">
                            <span v-if="form.errors.assunto" class="text-xs text-red-600">{{ form.errors.assunto }}</span>
                        </label>
                    </div>
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        Mensagem
                        <textarea v-model="form.mensagem" rows="6" maxlength="3000" required :class="campo"></textarea>
                        <span class="text-right text-xs font-normal text-store-fg-faint">{{ form.mensagem.length }}/3000</span>
                        <span v-if="form.errors.mensagem" class="text-xs text-red-600">{{ form.errors.mensagem }}</span>
                    </label>

                    <!-- Armadilha pra robô: invisível pra quem usa o site. -->
                    <div class="absolute -left-[9999px] h-px w-px overflow-hidden" aria-hidden="true">
                        <label>Seu site <input v-model="form.site" type="text" name="site" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg bg-[#0FB930] px-4 py-3 font-semibold text-white hover:brightness-95 disabled:opacity-50">
                        <i class="fa-solid mr-1.5" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-paper-plane'"></i>
                        Enviar mensagem
                    </button>
                    <p class="text-center text-xs text-store-fg-faint"><i class="fa-solid fa-lock mr-1"></i>Seus dados ficam protegidos e só usamos para responder você.</p>
                </form>
            </section>

            <aside class="flex flex-col gap-4">
                <a :href="COMPANY.whatsappLink" target="_blank" rel="noopener"
                    class="flex items-center gap-4 rounded-2xl bg-[#25D366] p-5 text-white no-underline transition hover:brightness-95">
                    <i class="fa-brands fa-whatsapp text-4xl"></i>
                    <span>
                        <span class="block text-sm opacity-90">Prefere conversar agora?</span>
                        <span class="block font-semibold">WhatsApp {{ COMPANY.whatsappDisplay }}</span>
                    </span>
                </a>
                <div class="rounded-2xl border border-store-border bg-store-bg-raised p-5 text-sm text-store-fg-muted">
                    <p class="font-semibold text-store-fg"><i class="fa-regular fa-clock mr-1.5"></i>Atendimento</p>
                    <p class="mt-1">{{ COMPANY.horario }}</p>
                    <p class="mt-4 font-semibold text-store-fg"><i class="fa-regular fa-building mr-1.5"></i>{{ COMPANY.nomeFantasia }}</p>
                    <p class="mt-1">CNPJ {{ COMPANY.cnpj }}<br>{{ COMPANY.enderecoResumido }}</p>
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
