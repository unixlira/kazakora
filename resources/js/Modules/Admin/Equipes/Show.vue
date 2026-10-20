<script setup>
/**
 * Equipes › <squad> (pedido do Lira 2026-10-20, validado com o Jonathan):
 * membros em cards; clique no card abre o painel lateral (gaveta à direita,
 * tela cheia no celular) com o que o membro faz, skills, o que precisa de OK
 * do Lira e as instruções extras. Instrução salva aqui sai pela API
 * GET /api/equipe/{slug}/instrucoes e o agente segue a partir da próxima
 * tarefa. Nada é apagado de verdade: desativar é status, excluir instrução
 * é soft delete. Confirmações ficam dentro do painel (sem modal sobre modal).
 */
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import InputError from '@/Shared/Components/InputError.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    squad: { type: Object, required: true },
    membros: { type: Array, default: () => [] },
    areas: { type: Array, default: () => [] },
    precisaOkOpcoes: { type: Object, default: () => ({}) },
});

const base = computed(() => `/admin/equipes/${props.squad.slug}`);
const naEquipe = computed(() => props.membros.filter((m) => m.status !== 'desativado'));
const desativados = computed(() => props.membros.filter((m) => m.status === 'desativado'));

const STATUS = {
    ativo: { rotulo: 'Ativo', classe: 'bg-success/10 text-success-emphasis', icone: 'fas fa-circle-play' },
    pausado: { rotulo: 'Pausado', classe: 'bg-warning/15 text-warning-emphasis', icone: 'fas fa-circle-pause' },
    desativado: { rotulo: 'Desativado', classe: 'bg-[var(--surface-muted)] text-[var(--texto-suave)]', icone: 'fas fa-circle-minus' },
};

const inicial = (nome) => (nome ?? '?').trim().charAt(0).toUpperCase();
const dataBr = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'America/Sao_Paulo' }) : '');
const plural = (n, um, varios) => `${n} ${n === 1 ? um : varios}`;

// Painel lateral: o membro aberto fica na URL (?membro=slug) para dar para
// voltar nele depois de salvar e para mandar o link.
const abertoSlug = ref(new URLSearchParams(window.location.search).get('membro'));
const aberto = computed(() => props.membros.find((m) => m.slug === abertoSlug.value) ?? null);
const painel = ref(null);
let focoAnterior = null;

function atualizarUrl(slug) {
    const url = new URL(window.location.href);
    if (slug) url.searchParams.set('membro', slug); else url.searchParams.delete('membro');
    window.history.replaceState(window.history.state, '', url);
}

function abrir(m, evento) {
    focoAnterior = evento?.currentTarget ?? document.activeElement;
    abertoSlug.value = m.slug;
    atualizarUrl(m.slug);
}

function fechar() {
    abertoSlug.value = null;
    atualizarUrl(null);
    cancelarInstrucao();
    confirmarDesativar.value = false;
    excluindo.value = null;
    nextTick(() => focoAnterior?.focus?.());
}

watch(aberto, async (m) => {
    document.body.style.overflow = m ? 'hidden' : '';
    if (m) {
        await nextTick();
        painel.value?.focus();
    }
}, { immediate: true });

const onKeydown = (e) => {
    if (e.key === 'Escape' && aberto.value) fechar();
};
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});

// Instruções extras
const formInstrucao = useForm({ texto: '' });
const adicionando = ref(false);
const campoInstrucao = ref(null);

async function novaInstrucao() {
    adicionando.value = true;
    await nextTick();
    campoInstrucao.value?.focus();
}

function cancelarInstrucao() {
    adicionando.value = false;
    formInstrucao.reset();
    formInstrucao.clearErrors();
}

function salvarInstrucao() {
    formInstrucao.post(`${base.value}/membros/${aberto.value.slug}/instrucoes`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => cancelarInstrucao(),
    });
}

const enviando = ref(null);
function alternarInstrucao(i) {
    enviando.value = i.id;
    router.patch(`${base.value}/membros/${aberto.value.slug}/instrucoes/${i.id}`, { ativa: !i.ativa }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { enviando.value = null; },
    });
}

const excluindo = ref(null);
function excluirInstrucao(i) {
    enviando.value = i.id;
    router.delete(`${base.value}/membros/${aberto.value.slug}/instrucoes/${i.id}`, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { enviando.value = null; excluindo.value = null; },
    });
}

// Status do membro
const confirmarDesativar = ref(false);
const mudandoStatus = ref(false);
function mudarStatus(status) {
    mudandoStatus.value = true;
    router.patch(`${base.value}/membros/${aberto.value.slug}/status`, { status }, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { mudandoStatus.value = false; confirmarDesativar.value = false; },
    });
}

const flash = computed(() => usePage().props.flash?.success);
</script>

<template>
    <Head :title="`Equipes › ${squad.nome}`" />

    <AdminLayout>
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-[var(--texto-suave)]">Equipes</p>
                <h1 class="text-2xl font-bold">{{ squad.nome }}</h1>
                <p class="mt-0.5 max-w-[65ch] text-sm text-[var(--texto-suave)]">
                    Os especialistas de IA que trabalham para o Homer. Clique num membro para ver o que ele faz e mudar as instruções extras dele.
                </p>
            </div>
            <Link v-if="naEquipe.length" :href="`${base}/membros/nova`"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-emphasis active:translate-y-px">
                <i class="fas fa-user-plus" aria-hidden="true"></i> Adicionar membro
            </Link>
        </div>

        <!-- Estado vazio -->
        <section v-if="!naEquipe.length" class="rounded-xl border border-dashed border-[var(--surface-border)] bg-[var(--surface)] px-6 py-12 text-center">
            <i class="fas fa-people-group text-3xl text-[var(--texto-suave)]" aria-hidden="true"></i>
            <h2 class="mt-3 text-lg font-bold">Ninguém na {{ squad.nome }} ainda</h2>
            <p class="mt-1 text-sm text-[var(--texto-suave)]">Adicione o primeiro especialista para começar a delegar.</p>
            <Link :href="`${base}/membros/nova`"
                class="mt-4 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-emphasis">
                <i class="fas fa-user-plus" aria-hidden="true"></i> Adicionar membro
            </Link>
        </section>

        <!-- Cards: o card inteiro é o botão que abre o painel -->
        <ul v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-label="Membros da equipe">
            <li v-for="m in naEquipe" :key="m.id">
                <button type="button"
                    class="group flex h-full w-full flex-col rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] p-4 text-left shadow-sm transition-[border-color,box-shadow,transform] hover:border-[var(--color-primary)] hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--color-primary)] active:scale-[0.99] motion-reduce:transition-none"
                    :class="m.status === 'pausado' ? 'opacity-80' : ''"
                    :aria-label="`Abrir ${m.nome}, ${m.funcao}, ${STATUS[m.status]?.rotulo}`"
                    @click="abrir(m, $event)">
                    <div class="flex items-start gap-3">
                        <img v-if="m.foto" :src="m.foto" alt="" width="48" height="48" class="h-12 w-12 shrink-0 rounded-full object-cover">
                        <span v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-lg font-bold text-white" :style="{ background: m.cor }" aria-hidden="true">
                            {{ inicial(m.nome) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <h2 class="truncate text-base font-bold">{{ m.nome }}</h2>
                                <span class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold" :class="STATUS[m.status]?.classe">
                                    <i :class="STATUS[m.status]?.icone" aria-hidden="true"></i>{{ STATUS[m.status]?.rotulo }}
                                </span>
                            </div>
                            <p class="mt-0.5 truncate text-sm text-[var(--texto-suave)]" :title="m.funcao">{{ m.funcao }}</p>
                        </div>
                    </div>
                    <span class="block flex-1 pt-4" aria-hidden="true"></span>
                    <p class="flex items-center gap-3 border-t border-[var(--surface-border)] pt-3 text-xs text-[var(--texto-suave)]">
                        <span><i class="fas fa-wand-magic-sparkles mr-1" aria-hidden="true"></i>{{ plural(m.skills.length, 'skill', 'skills') }}</span>
                        <span><i class="fas fa-list-check mr-1" aria-hidden="true"></i>{{ plural(m.instrucoes_ativas, 'instrução extra', 'instruções extras') }}</span>
                    </p>
                </button>
            </li>
        </ul>

        <details v-if="desativados.length" class="mt-6 text-sm">
            <summary class="cursor-pointer font-semibold text-[var(--texto-suave)]">{{ plural(desativados.length, 'membro desativado', 'membros desativados') }}</summary>
            <ul class="mt-3 flex flex-wrap gap-2">
                <li v-for="m in desativados" :key="m.id">
                    <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 hover:border-[var(--color-primary)]" @click="abrir(m, $event)">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold text-white opacity-60" :style="{ background: m.cor }" aria-hidden="true">{{ inicial(m.nome) }}</span>
                        {{ m.nome }}
                    </button>
                </li>
            </ul>
        </details>

        <!-- Painel lateral. O Teleport tira o painel de dentro do AdminLayout:
             o .admin-shell aqui traz de volta as cores do tema (sem ele o
             --surface não existe e o painel fica transparente no escuro). -->
        <Teleport to="body">
          <div class="admin-shell contents">
            <Transition enter-active-class="transition-opacity duration-200 motion-reduce:transition-none" enter-from-class="opacity-0" leave-active-class="transition-opacity duration-150 motion-reduce:transition-none" leave-to-class="opacity-0">
                <div v-if="aberto" class="fixed inset-0 z-50 bg-black/40" aria-hidden="true" @click="fechar"></div>
            </Transition>
            <Transition enter-active-class="transition-transform duration-200 ease-out motion-reduce:transition-none" enter-from-class="translate-x-full" leave-active-class="transition-transform duration-150 ease-in motion-reduce:transition-none" leave-to-class="translate-x-full">
                <aside v-if="aberto" ref="painel" tabindex="-1" role="dialog" aria-modal="true" :aria-labelledby="`painel-${aberto.slug}`"
                    class="fixed inset-y-0 right-0 z-50 flex w-full flex-col bg-[var(--surface)] text-[var(--surface-foreground)] shadow-2xl outline-none sm:max-w-lg sm:border-l sm:border-[var(--surface-border)]"
                    style="overscroll-behavior: contain">
                    <!-- Cabeçalho -->
                    <header class="flex items-start gap-3 border-b border-[var(--surface-border)] p-5">
                        <img v-if="aberto.foto" :src="aberto.foto" alt="" width="56" height="56" class="h-14 w-14 shrink-0 rounded-full object-cover">
                        <span v-else class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full text-xl font-bold text-white" :style="{ background: aberto.cor }" aria-hidden="true">{{ inicial(aberto.nome) }}</span>
                        <div class="min-w-0 flex-1">
                            <h2 :id="`painel-${aberto.slug}`" class="text-xl font-bold">{{ aberto.nome }}</h2>
                            <p class="text-sm text-[var(--texto-suave)]">{{ aberto.funcao }}</p>
                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold" :class="STATUS[aberto.status]?.classe">
                                    <i :class="STATUS[aberto.status]?.icone" aria-hidden="true"></i>{{ STATUS[aberto.status]?.rotulo }}
                                </span>
                                <span class="text-[var(--texto-suave)]">Responde para <strong class="text-[var(--surface-foreground)]">{{ aberto.responde_para }}</strong></span>
                                <code class="rounded bg-[var(--surface-muted)] px-1.5 py-0.5 text-[var(--texto-suave)]" translate="no">{{ aberto.slug }}</code>
                            </p>
                        </div>
                        <button type="button" class="-m-1 rounded-lg p-2 text-[var(--texto-suave)] hover:bg-[var(--surface-muted)] hover:text-[var(--surface-foreground)]" aria-label="Fechar painel" @click="fechar">
                            <i class="fas fa-xmark text-lg" aria-hidden="true"></i>
                        </button>
                    </header>

                    <div class="flex-1 space-y-6 overflow-y-auto p-5">
                        <p v-if="flash" role="status" aria-live="polite" class="rounded-lg bg-success/10 px-3 py-2 text-sm font-semibold text-success-emphasis">
                            <i class="fas fa-check mr-1" aria-hidden="true"></i>{{ flash }}
                        </p>

                        <section>
                            <h3 class="text-sm font-bold">O que faz</h3>
                            <p class="mt-1 text-sm leading-relaxed text-[var(--texto-suave)]">{{ aberto.o_que_faz || 'Sem descrição.' }}</p>
                        </section>

                        <section>
                            <h3 class="text-sm font-bold">Skills</h3>
                            <p v-if="!aberto.skills.length" class="mt-1 text-sm text-[var(--texto-suave)]">Nenhuma skill cadastrada.</p>
                            <ul v-else class="mt-2 space-y-2">
                                <li v-for="s in aberto.skills" :key="s.nome" class="text-sm">
                                    <code class="font-semibold" translate="no">{{ s.nome }}</code>
                                    <p v-if="s.descricao" class="whitespace-pre-line text-[var(--texto-suave)]">{{ s.descricao }}</p>
                                </li>
                            </ul>
                        </section>

                        <section>
                            <h3 class="text-sm font-bold">Precisa de OK do Lira para</h3>
                            <ul class="mt-2 flex flex-wrap gap-2">
                                <li v-for="(rotulo, chave) in precisaOkOpcoes" :key="chave"
                                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold"
                                    :class="aberto.precisa_ok.includes(chave) ? 'border-[var(--color-primary)] text-[var(--surface-foreground)]' : 'border-[var(--surface-border)] text-[var(--texto-suave)] line-through'">
                                    <i :class="aberto.precisa_ok.includes(chave) ? 'fas fa-lock' : 'fas fa-lock-open'" aria-hidden="true"></i>{{ rotulo }}
                                </li>
                            </ul>
                            <p class="mt-2 text-xs text-[var(--texto-suave)]">Só cadastro. As regras de OK do Lira valem sempre, para todos, acima das instruções extras.</p>
                        </section>

                        <section>
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-bold">Instruções extras do {{ aberto.nome }}</h3>
                                    <p class="text-xs text-[var(--texto-suave)]">O {{ aberto.nome }} segue isto em toda tarefa, além das skills dele.</p>
                                </div>
                            </div>

                            <p v-if="!aberto.instrucoes.length && !adicionando" class="mt-3 rounded-lg border border-dashed border-[var(--surface-border)] px-3 py-4 text-sm text-[var(--texto-suave)]">
                                Nenhuma instrução extra. O {{ aberto.nome }} segue só as skills dele.
                            </p>

                            <ul v-if="aberto.instrucoes.length" class="mt-3 space-y-2">
                                <li v-for="i in aberto.instrucoes" :key="i.id" class="rounded-lg border border-[var(--surface-border)] p-3" :class="i.ativa ? '' : 'bg-[var(--surface-muted)]/50'">
                                    <p class="whitespace-pre-line text-sm" :class="i.ativa ? '' : 'text-[var(--texto-suave)]'">{{ i.texto }}</p>
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-[var(--texto-suave)]">
                                        <span>{{ dataBr(i.criada_em) }}<template v-if="i.autor">, por {{ i.autor }}</template></span>
                                        <div class="flex items-center gap-3">
                                            <label class="inline-flex cursor-pointer items-center gap-2 font-semibold">
                                                <input type="checkbox" role="switch" class="peer sr-only" :checked="i.ativa" :disabled="enviando === i.id" @change="alternarInstrucao(i)">
                                                <span class="relative h-5 w-9 rounded-full bg-[var(--surface-border)] transition-colors peer-checked:bg-success peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--color-primary)] after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-transform peer-checked:after:translate-x-4 motion-reduce:after:transition-none" aria-hidden="true"></span>
                                                {{ i.ativa ? 'Ligada' : 'Desligada' }}
                                            </label>
                                            <button v-if="excluindo !== i.id" type="button" class="font-semibold text-error hover:underline" @click="excluindo = i.id">Excluir</button>
                                        </div>
                                    </div>
                                    <div v-if="excluindo === i.id" class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-error/10 p-2 text-xs" role="alert">
                                        <span class="font-semibold">Excluir esta instrução?</span>
                                        <button type="button" class="rounded-lg bg-error px-2.5 py-1 font-semibold text-white disabled:opacity-50" :disabled="enviando === i.id" @click="excluirInstrucao(i)">Excluir</button>
                                        <button type="button" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2.5 py-1 font-semibold" @click="excluindo = null">Manter</button>
                                    </div>
                                </li>
                            </ul>

                            <form v-if="adicionando" class="mt-3 space-y-2 rounded-lg border border-[var(--color-primary)] p-3" @submit.prevent="salvarInstrucao">
                                <label for="nova-instrucao" class="block text-sm font-semibold">Nova instrução para o {{ aberto.nome }}</label>
                                <textarea id="nova-instrucao" ref="campoInstrucao" v-model="formInstrucao.texto" rows="3" maxlength="2000" required
                                    name="texto" autocomplete="off"
                                    placeholder="Ex.: Antes de sugerir preço de Relâmpago, confira o menor preço dos últimos 7 dias."
                                    class="w-full rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-2 text-sm text-[var(--surface-foreground)]"></textarea>
                                <InputError :message="formInstrucao.errors.texto" />
                                <div class="flex gap-2">
                                    <button type="submit" :disabled="formInstrucao.processing"
                                        class="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-emphasis disabled:opacity-50">
                                        {{ formInstrucao.processing ? 'Salvando…' : 'Salvar instrução' }}
                                    </button>
                                    <button type="button" class="rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-semibold" @click="cancelarInstrucao">Cancelar</button>
                                </div>
                            </form>
                            <button v-else type="button"
                                class="mt-3 inline-flex items-center gap-2 rounded-lg border border-[var(--color-primary)] px-3 py-1.5 text-sm font-semibold text-[var(--color-primary)] transition hover:bg-[var(--color-primary)] hover:text-white"
                                @click="novaInstrucao">
                                <i class="fas fa-plus" aria-hidden="true"></i> Adicionar instrução
                            </button>
                        </section>
                    </div>

                    <!-- Rodapé -->
                    <footer class="border-t border-[var(--surface-border)] p-4">
                        <div v-if="confirmarDesativar" class="mb-3 rounded-lg bg-error/10 p-3 text-sm" role="alert">
                            <p class="font-semibold">Desativar o {{ aberto.nome }}? Ele para de receber tarefas. Dá para reativar depois.</p>
                            <div class="mt-2 flex gap-2">
                                <button type="button" class="rounded-lg bg-error px-3 py-1.5 font-semibold text-white disabled:opacity-50" :disabled="mudandoStatus" @click="mudarStatus('desativado')">Desativar</button>
                                <button type="button" class="rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-3 py-1.5 font-semibold" @click="confirmarDesativar = false">Manter</button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex gap-2">
                                <Link :href="`${base}/membros/${aberto.slug}/editar`"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-emphasis">
                                    <i class="fas fa-pen" aria-hidden="true"></i> Editar
                                </Link>
                                <button v-if="aberto.status === 'ativo'" type="button" :disabled="mudandoStatus"
                                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-semibold disabled:opacity-50" @click="mudarStatus('pausado')">
                                    <i class="fas fa-pause" aria-hidden="true"></i> Pausar
                                </button>
                                <button v-else type="button" :disabled="mudandoStatus"
                                    class="inline-flex items-center gap-2 rounded-lg border border-[var(--surface-border)] px-3 py-1.5 text-sm font-semibold disabled:opacity-50" @click="mudarStatus('ativo')">
                                    <i class="fas fa-play" aria-hidden="true"></i> {{ aberto.status === 'pausado' ? 'Retomar' : 'Reativar' }}
                                </button>
                            </div>
                            <button v-if="aberto.status !== 'desativado' && !confirmarDesativar" type="button"
                                class="text-sm font-semibold text-error hover:underline" @click="confirmarDesativar = true">
                                Desativar membro
                            </button>
                        </div>
                    </footer>
                </aside>
            </Transition>
          </div>
        </Teleport>
    </AdminLayout>
</template>
