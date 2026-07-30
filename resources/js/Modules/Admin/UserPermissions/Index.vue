<script setup>
import AdminLayout from '@/Shared/Layouts/AdminLayout.vue';
import { DataTable } from '@/Shared/Components/DataTable';
import ActionIcon from '@/Shared/Components/ActionIcon.vue';
import { Head, router } from '@inertiajs/vue3';
import { h, reactive } from 'vue';
import { confirmDelete } from '@/Shared/notify';

const props = defineProps({
    users: { type: Array, default: () => [] },
    authUserId: { type: Number, required: true },
    roles: { type: Array, default: () => [] },
    permissions: { type: Array, default: () => [] },
    matrix: { type: Object, default: () => ({}) },
});

const roleLabels = {
    admin: 'Admin',
    manager: 'Manager',
    subscriber: 'Subscriber',
    customer: 'Cliente',
};

const permissionLabels = {
    'cadastros.view': 'Visualizar cadastros',
    'cadastros.create': 'Criar cadastros',
    'cadastros.edit': 'Editar cadastros',
    'cadastros.delete': 'Excluir cadastros',
    'pedidos.view': 'Visualizar pedidos',
    'pedidos.edit': 'Editar pedidos',
    'estoque.adjust': 'Ajustar estoque',
    'configuracoes.usuarios': 'Usuários e permissões',
    'configuracoes.auditoria': 'Auditoria',
};

const changeRole = (user, role) => {
    router.patch(`/admin/usuarios-permissoes/usuarios/${user.id}`, { role }, { preserveScroll: true });
};

const deleteUser = async (user) => {
    if (await confirmDelete({ title: `Excluir ${user.name}?`, text: 'O usuário poderá ser restaurado depois, se necessário.' })) {
        router.delete(`/admin/usuarios-permissoes/usuarios/${user.id}`, { preserveScroll: true });
    }
};

const matrixState = reactive({
    manager: Object.fromEntries(props.permissions.map((p) => [p, !!props.matrix.manager?.[p]])),
    subscriber: Object.fromEntries(props.permissions.map((p) => [p, !!props.matrix.subscriber?.[p]])),
});

const saveMatrix = (role) => {
    router.put('/admin/usuarios-permissoes/matriz', { role, permissions: matrixState[role] }, { preserveScroll: true });
};

const columns = [
    { accessorKey: 'name', header: 'Nome' },
    { accessorKey: 'email', header: 'E-mail' },
    {
        id: 'role',
        header: 'Perfil',
        enableSorting: false,
        cell: ({ row }) =>
            h('select', {
                value: row.original.role,
                class: 'rounded-lg border border-[var(--surface-border)] bg-[var(--surface)] px-2 py-1 text-sm',
                onChange: (event) => changeRole(row.original, event.target.value),
            }, props.roles.map((role) => h('option', { value: role, selected: role === row.original.role }, roleLabels[role] ?? role))),
    },
    {
        id: 'actions',
        header: 'Ações',
        enableSorting: false,
        cell: ({ row }) => h('div', { class: 'flex items-center gap-2' }, [
            h(ActionIcon, { icon: 'fa-eye', label: 'Ver perfil', color: 'slate', href: `/perfil/usuario/${row.original.id}` }),
            row.original.id !== props.authUserId
                ? h(ActionIcon, { icon: 'fa-trash', label: 'Excluir', color: 'red', onClick: () => deleteUser(row.original) })
                : null,
        ]),
    },
];
</script>

<template>
    <Head title="Usuários e Permissões" />

    <AdminLayout>
        <h1 class="mb-4 text-2xl font-bold">Usuários e Permissões</h1>

        <DataTable
            :columns="columns"
            :data="props.users"
            search-placeholder="Buscar usuário..."
            empty-message="Nenhum usuário encontrado."
        />

        <div class="mt-8 rounded-xl border border-[var(--surface-border)] bg-[var(--surface)] shadow-sm">
            <div class="border-b border-[var(--surface-border)] p-4">
                <h2 class="text-lg font-semibold">Matriz de permissões</h2>
                <p class="text-sm text-slate-400">Admin sempre tem acesso completo. Configure aqui o que Manager e Subscriber podem fazer.</p>
            </div>

            <div v-for="role in ['manager', 'subscriber']" :key="role" class="border-b border-[var(--surface-border)] p-4 last:border-0">
                <h3 class="mb-3 font-semibold">{{ roleLabels[role] }}</h3>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="permission in props.permissions" :key="permission" class="flex items-center gap-2 text-sm">
                        <input v-model="matrixState[role][permission]" type="checkbox" class="rounded border-[var(--surface-border)]">
                        {{ permissionLabels[permission] ?? permission }}
                    </label>
                </div>
                <button type="button"
                    class="mt-4 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-emphasis"
                    @click="saveMatrix(role)">
                    Salvar permissões de {{ roleLabels[role] }}
                </button>
            </div>
        </div>
    </AdminLayout>
</template>
