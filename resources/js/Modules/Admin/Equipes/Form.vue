<script setup>
/**
 * Adicionar e editar membro de uma squad (Equipes, pedido do Lira
 * 2026-10-20). Tela própria: /admin/equipes/<squad>/membros/nova e
 * .../<membro>/editar. "Precisa de OK para" é só cadastro e exibição.
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps({
    squad: { type: Object, required: true },
    membro: { type: Object, default: null },
    outrosMembros: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    precisaOkOpcoes: { type: Object, default: () => ({}) },
});

const editando = computed(() => !!props.membro);
const base = `/admin/equipes/${props.squad.slug}`;

const form = useForm({
    nome: props.membro?.nome ?? '',
    slug: props.membro?.slug ?? '',
    funcao: props.membro?.funcao ?? '',
    area: props.membro?.area ?? 'outra',
    o_que_faz: props.membro?.o_que_faz ?? '',
    skills: (props.membro?.skills ?? []).map((s) => ({ ...s })),
    responde_para_id: props.membro?.responde_para_id ?? '',
    precisa_ok: props.membro ? [...props.membro.precisa_ok] : Object.keys(props.precisaOkOpcoes),
    status: props.membro?.status ?? 'ativo',
    cor: props.membro?.cor ?? '',
    foto: null,
    remover_foto: false,
});

// Slug sugerido pelo nome enquanto a pessoa não mexe nele (só no cadastro).
const slugMexido = ref(editando.value);
const sugerirSlug = (texto) => texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
function onNome() {
    if (!slugMexido.value) form.slug = sugerirSlug(form.nome);
}

const corDaArea = computed(() => props.areas.find((a) => a.valor === form.area)?.cor ?? '#64748b');
const corFinal = computed(() => form.cor || corDaArea.value);
const previaFoto = ref(props.membro?.foto ?? null);
function onFoto(e) {
    const arquivo = e.target.files?.[0] ?? null;
    form.foto = arquivo;
    form.remover_foto = false;
    previaFoto.value = arquivo ? URL.createObjectURL(arquivo) : props.membro?.foto ?? null;
}
function tirarFoto() {
    form.foto = null;
    form.remover_foto = true;
    previaFoto.value = null;
}

// Skills em cards (pedido do Lira 2026-10-20): clicar no card abre um modal
// centralizado com o nome e a descrição numa área de texto que cresce (com
// rolagem se passar da tela). O modal mexe numa cópia: "Aplicar" leva para
// o formulário, "Cancelar" descarta. Gravar continua sendo no Salvar da tela.
const removerSkill = (i) => form.skills.splice(i, 1);

const skillAberta = ref(null); // índice em form.skills, ou -1 para skill nova
const rascunho = ref({ nome: '', descricao: '' });
const erroRascunho = ref('');
const campoNomeSkill = ref(null);
const campoDescSkill = ref(null);
let focoAntesModal = null;

function ajustarAltura() {
    const el = campoDescSkill.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight + 2}px`;
}

async function abrirSkill(i, evento) {
    focoAntesModal = evento?.currentTarget ?? document.activeElement;
    skillAberta.value = i;
    rascunho.value = i >= 0 ? { ...form.skills[i] } : { nome: '', descricao: '' };
    erroRascunho.value = '';
    document.body.style.overflow = 'hidden';
    await nextTick();
    ajustarAltura();
    (i >= 0 ? campoDescSkill : campoNomeSkill).value?.focus();
}

function fecharSkill() {
    skillAberta.value = null;
    document.body.style.overflow = '';
    nextTick(() => focoAntesModal?.focus?.());
}

function aplicarSkill() {
    const nome = rascunho.value.nome.trim();
    if (!nome) {
        erroRascunho.value = 'Dê um nome para a skill.';
        campoNomeSkill.value?.focus();
        return;
    }
    const skill = { ...rascunho.value, nome };
    if (skillAberta.value >= 0) form.skills[skillAberta.value] = skill;
    else form.skills.push(skill);
    fecharSkill();
}

const onKeydown = (e) => {
    if (e.key === 'Escape' && skillAberta.value !== null) fecharSkill();
};
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

const erroSkills = computed(() => Object.entries(form.errors).find(([k]) => k.startsWith('skills'))?.[1]);

function enviar() {
    const opcoes = { forceFormData: true, preserveScroll: true };
    form.transform((d) => ({
        ...d,
        responde_para_id: d.responde_para_id || null,
        cor: d.cor || null,
        skills: d.skills.filter((s) => s.nome.trim() !== ''),
        remover_foto: d.remover_foto ? 1 : 0,
        ...(editando.value ? { _method: 'put' } : {}),
    }));
    if (editando.value) form.post(`${base}/membros/${props.membro.slug}`, opcoes);
    else form.post(`${base}/membros`, opcoes);
}

const campo = 'mt-1 w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm text-[var(--surface-foreground)]';
</script>

<template>
    <Head :title="editando ? `Editar ${membro.nome}` : `Adicionar membro na ${squad.nome}`" />

    <AdminLayout>
        <nav class="mb-1 text-sm" aria-label="Caminho">
            <Link :href="base" class="font-semibold text-primary hover:underline">
                <i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>{{ squad.nome }}
            </Link>
        </nav>
        <h1 class="mb-6 text-2xl font-bold">{{ editando ? `Editar ${membro.nome}` : 'Adicionar membro' }}</h1>

        <form class="max-w-3xl space-y-6" @submit.prevent="enviar">
            <section class="space-y-4 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-base font-bold">Quem é</h2>

                <div class="flex items-center gap-4">
                    <img v-if="previaFoto" :src="previaFoto" alt="" width="64" height="64" class="h-16 w-16 rounded-full object-cover">
                    <span v-else class="flex h-16 w-16 items-center justify-center rounded-full text-2xl font-bold text-white" :style="{ background: corFinal }" aria-hidden="true">
                        {{ (form.nome || '?').trim().charAt(0).toUpperCase() }}
                    </span>
                    <div class="flex flex-wrap items-end gap-3 text-sm">
                        <div>
                            <label for="foto" class="block font-medium">Foto (opcional)</label>
                            <input id="foto" type="file" accept="image/*" class="mt-1 block text-sm" @change="onFoto">
                            <InputError :message="form.errors.foto" />
                        </div>
                        <button v-if="previaFoto" type="button" class="font-semibold text-error hover:underline" @click="tirarFoto">Tirar foto</button>
                        <div>
                            <label for="cor" class="block font-medium">Cor (opcional)</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input id="cor" type="color" :value="corFinal" class="h-9 w-12 cursor-pointer rounded border border-[var(--surface-border)]" @input="form.cor = $event.target.value">
                                <button v-if="form.cor" type="button" class="text-xs font-semibold text-primary hover:underline" @click="form.cor = ''">Usar a cor da área</button>
                            </div>
                            <InputError :message="form.errors.cor" />
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="nome" class="block text-sm font-medium">Nome</label>
                        <input id="nome" v-model="form.nome" name="nome" type="text" required maxlength="80" autocomplete="off" :class="campo" @input="onNome">
                        <InputError :message="form.errors.nome" />
                    </div>
                    <div>
                        <label for="slug" class="block text-sm font-medium">Identificador do agente</label>
                        <input id="slug" v-model="form.slug" name="slug" type="text" required maxlength="80" autocomplete="off" spellcheck="false"
                            placeholder="ex.: caue-shopee" :class="[campo, 'font-mono']" @input="slugMexido = true">
                        <p class="mt-1 text-xs text-[var(--texto-suave)]">Igual ao nome do arquivo do agente (.claude/agents/<span translate="no">{{ form.slug || 'slug' }}</span>.md).</p>
                        <InputError :message="form.errors.slug" />
                    </div>
                </div>

                <div>
                    <label for="funcao" class="block text-sm font-medium">Função em uma linha</label>
                    <input id="funcao" v-model="form.funcao" name="funcao" type="text" required maxlength="160" autocomplete="off" :class="campo"
                        placeholder="ex.: Estrategista de Shopee: ADS, ROAS, preço e anúncios">
                    <InputError :message="form.errors.funcao" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="area" class="block text-sm font-medium">Área</label>
                        <select id="area" v-model="form.area" name="area" :class="campo">
                            <option v-for="a in areas" :key="a.valor" :value="a.valor">{{ a.rotulo }}</option>
                        </select>
                        <InputError :message="form.errors.area" />
                    </div>
                    <div>
                        <label for="responde" class="block text-sm font-medium">Responde para</label>
                        <select id="responde" v-model="form.responde_para_id" name="responde_para_id" :class="campo">
                            <option value="">Homer</option>
                            <option v-for="m in outrosMembros" :key="m.id" :value="m.id">{{ m.nome }} ({{ m.funcao }})</option>
                        </select>
                        <InputError :message="form.errors.responde_para_id" />
                    </div>
                </div>

                <div>
                    <label for="o_que_faz" class="block text-sm font-medium">O que faz</label>
                    <textarea id="o_que_faz" v-model="form.o_que_faz" name="o_que_faz" rows="3" maxlength="2000" :class="campo"
                        placeholder="2 ou 3 linhas sobre o trabalho dele."></textarea>
                    <InputError :message="form.errors.o_que_faz" />
                </div>
            </section>

            <section class="space-y-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold">Skills</h2>
                        <p class="text-xs text-[var(--texto-suave)]">Clique numa skill para ler e mudar o nome e a descrição. A mudança só grava no Salvar lá embaixo.</p>
                    </div>
                </div>
                <p v-if="!form.skills.length" class="rounded-lg border border-dashed border-[var(--surface-border)] px-3 py-4 text-sm text-[var(--texto-suave)]">Nenhuma skill ainda.</p>
                <ul v-else class="grid gap-3 sm:grid-cols-2" aria-label="Skills do membro">
                    <li v-for="(s, i) in form.skills" :key="i" class="relative">
                        <button type="button"
                            class="flex h-full w-full flex-col rounded-lg border bg-[var(--surface)] p-3 pr-10 text-left transition-[border-color,box-shadow] hover:border-[var(--color-primary)] hover:shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-primary)] motion-reduce:transition-none"
                            :class="Object.keys(form.errors).some((k) => k.startsWith(`skills.${i}.`)) ? 'border-error' : 'border-[var(--surface-border)]'"
                            :aria-label="`Abrir a skill ${s.nome}`"
                            @click="abrirSkill(i, $event)">
                            <code class="truncate text-sm font-semibold" translate="no">{{ s.nome }}</code>
                            <span class="mt-1 line-clamp-2 text-xs text-[var(--texto-suave)]">{{ s.descricao || 'Sem descrição.' }}</span>
                        </button>
                        <button type="button" class="absolute top-2 right-2 rounded-lg p-1.5 text-[var(--texto-suave)] hover:text-error" :aria-label="`Tirar a skill ${s.nome || i + 1}`" @click="removerSkill(i)">
                            <i class="fas fa-trash-can" aria-hidden="true"></i>
                        </button>
                    </li>
                </ul>
                <InputError :message="erroSkills" />
                <div class="flex justify-end">
                    <button type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-[var(--color-primary)] px-3 py-1.5 text-sm font-semibold text-[var(--color-primary)] transition hover:bg-[var(--color-primary)] hover:text-white"
                        @click="abrirSkill(-1, $event)">
                        <i class="fas fa-plus" aria-hidden="true"></i> Adicionar skill
                    </button>
                </div>
            </section>

            <section class="space-y-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-base font-bold">Precisa de OK do Lira para</h2>
                <p class="text-xs text-[var(--texto-suave)]">Só cadastro e exibição: não dá nem tira permissão de verdade. As regras de OK do Lira valem sempre.</p>
                <fieldset class="grid gap-2 sm:grid-cols-2">
                    <legend class="sr-only">Ações que precisam de OK do Lira</legend>
                    <label v-for="(rotulo, chave) in precisaOkOpcoes" :key="chave" class="flex items-center gap-2 text-sm">
                        <input v-model="form.precisa_ok" type="checkbox" :value="chave" name="precisa_ok[]" class="rounded border-[var(--surface-border)]">
                        {{ rotulo }}
                    </label>
                </fieldset>
            </section>

            <section class="space-y-3 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-5 shadow-sm">
                <h2 class="text-base font-bold">{{ editando ? 'Status' : 'Status inicial' }}</h2>
                <fieldset class="flex flex-wrap gap-4">
                    <legend class="sr-only">Status</legend>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.status" type="radio" value="ativo" name="status"> Ativo</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="form.status" type="radio" value="pausado" name="status"> Pausado</label>
                    <label v-if="editando" class="flex items-center gap-2 text-sm"><input v-model="form.status" type="radio" value="desativado" name="status"> Desativado</label>
                </fieldset>
                <InputError :message="form.errors.status" />
            </section>

            <div class="flex items-center gap-3">
                <button type="submit" :disabled="form.processing"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:cursor-not-allowed disabled:opacity-50">
                    {{ form.processing ? 'Salvando…' : (editando ? 'Salvar alterações' : 'Adicionar membro') }}
                </button>
                <Link :href="editando ? `${base}?membro=${membro.slug}` : base" class="text-sm font-semibold text-[var(--texto-suave)] hover:underline">Cancelar</Link>
            </div>
        </form>

        <!-- Modal da skill. O .admin-shell traz as cores do tema para fora do
             AdminLayout (mesmo motivo do painel lateral da Show). -->
        <Teleport to="body">
          <div class="admin-shell contents">
            <Transition enter-active-class="transition-opacity duration-150 motion-reduce:transition-none" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-100 motion-reduce:transition-none" leave-to-class="opacity-0">
                <div v-if="skillAberta !== null" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="fecharSkill">
                    <form role="dialog" aria-modal="true" aria-labelledby="modal-skill-titulo"
                        class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] text-[var(--surface-foreground)] shadow-2xl"
                        style="overscroll-behavior: contain"
                        @submit.prevent="aplicarSkill">
                        <header class="flex items-center justify-between gap-3 border-b border-[var(--surface-border)] px-5 py-4">
                            <h2 id="modal-skill-titulo" class="text-lg font-bold">{{ skillAberta >= 0 ? 'Skill' : 'Nova skill' }}</h2>
                            <button type="button" class="-m-1 rounded-lg p-2 text-[var(--texto-suave)] hover:bg-[var(--surface-muted)] hover:text-[var(--surface-foreground)]" aria-label="Fechar" @click="fecharSkill">
                                <i class="fas fa-xmark text-lg" aria-hidden="true"></i>
                            </button>
                        </header>
                        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4">
                            <div>
                                <label for="modal-skill-nome" class="block text-sm font-medium">Nome</label>
                                <input id="modal-skill-nome" ref="campoNomeSkill" v-model="rascunho.nome" type="text" maxlength="80" required
                                    placeholder="nome-da-skill" spellcheck="false" autocomplete="off" :class="[campo, 'font-mono']">
                                <InputError :message="erroRascunho" />
                            </div>
                            <div>
                                <label for="modal-skill-desc" class="block text-sm font-medium">Descrição</label>
                                <textarea id="modal-skill-desc" ref="campoDescSkill" v-model="rascunho.descricao" rows="6" maxlength="2000"
                                    placeholder="Para que serve, quando usar, o que conferir" aria-describedby="modal-skill-conta"
                                    :class="[campo, 'max-h-[55vh] min-h-32 resize-y overflow-y-auto leading-relaxed']"
                                    @input="ajustarAltura"></textarea>
                                <p id="modal-skill-conta" class="mt-0.5 text-right text-xs text-[var(--texto-suave)]">{{ (rascunho.descricao ?? '').length }}/2000</p>
                            </div>
                        </div>
                        <footer class="flex justify-end gap-2 border-t border-[var(--surface-border)] px-5 py-3">
                            <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-semibold" @click="fecharSkill">Cancelar</button>
                            <button type="submit" class="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-emphasis">
                                {{ skillAberta >= 0 ? 'Aplicar' : 'Adicionar' }}
                            </button>
                        </footer>
                    </form>
                </div>
            </Transition>
          </div>
        </Teleport>
    </AdminLayout>
</template>
