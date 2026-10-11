<script setup>
// Conta criada no checkout com senha temporária (pedido 2026-10-10): ao
// entrar, pede para trocar por uma senha pessoal. "Agora não" esconde só
// nesta visita; o aviso volta na próxima até a senha ser trocada.
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const page = usePage();
const CHAVE = 'kazakora_trocar_senha_depois';

const adiado = ref((() => {
    try {
        return window.sessionStorage.getItem(CHAVE) === '1';
    } catch {
        return false;
    }
})());

const aberto = computed(() => Boolean(page.props.auth?.user?.deve_trocar_senha) && !adiado.value);
const mostrar = ref(false);

const form = useForm({ password: '', password_confirmation: '' });

const salvar = () => form.put('/perfil/senha', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
});

const depois = () => {
    adiado.value = true;
    try {
        window.sessionStorage.setItem(CHAVE, '1');
    } catch {
        // sem armazenamento: fecha só até recarregar
    }
};
</script>

<template>
    <Teleport to="body">
        <div v-if="aberto" class="fixed inset-0 z-[90] flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true" aria-labelledby="trocar-senha-titulo">
            <form class="w-full max-w-md rounded-2xl bg-white p-6 text-slate-800 shadow-2xl [color-scheme:light]" @submit.prevent="salvar">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-orange-100 text-2xl text-[#f27a2a]">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <h2 id="trocar-senha-titulo" class="mt-4 text-center text-xl font-bold">Crie sua senha pessoal</h2>
                <p class="mt-2 text-center text-sm text-slate-500">
                    Você entrou com a senha temporária que mandamos por e-mail. Por segurança, troque por uma senha só sua.
                </p>

                <label class="mt-5 block text-sm font-medium">
                    Nova senha
                    <div class="relative mt-1">
                        <input v-model="form.password" :type="mostrar ? 'text' : 'password'" autocomplete="new-password" minlength="8" required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2.5 pr-10 text-sm focus:border-[#f27a2a] focus:outline-none">
                        <button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 px-1 text-slate-400" :aria-label="mostrar ? 'Esconder senha' : 'Mostrar senha'" @click="mostrar = !mostrar">
                            <i class="fa-regular" :class="mostrar ? 'fa-eye-slash' : 'fa-eye'"></i>
                        </button>
                    </div>
                </label>
                <label class="mt-3 block text-sm font-medium">
                    Repita a nova senha
                    <input v-model="form.password_confirmation" :type="mostrar ? 'text' : 'password'" autocomplete="new-password" required
                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-[#f27a2a] focus:outline-none">
                </label>
                <p class="mt-1 text-xs text-slate-400">Pelo menos 8 caracteres.</p>
                <p v-if="form.errors.password" class="mt-2 text-sm text-red-600">{{ form.errors.password }}</p>

                <button type="submit" :disabled="form.processing"
                    class="mt-5 w-full rounded-lg bg-[#0FB930] px-4 py-3 font-semibold text-white hover:brightness-95 disabled:opacity-50">
                    <i class="fa-solid mr-1.5" :class="form.processing ? 'fa-spinner animate-spin' : 'fa-lock'"></i>
                    Salvar minha senha
                </button>
                <button type="button" class="mt-2 w-full py-2 text-sm text-slate-500 hover:text-slate-700" @click="depois">Agora não</button>
            </form>
        </div>
    </Teleport>
</template>
