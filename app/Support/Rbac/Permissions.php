<?php

namespace App\Support\Rbac;

use App\Models\RolePermission;
use App\Models\User;

/**
 * Central permission matrix for the admin panel.
 *
 * Admin is always granted everything (never stored in `role_permissions`,
 * checked as a wildcard). Manager/Subscriber grants live in the database so
 * an admin can edit them from Configurações > Usuários e Permissões.
 */
class Permissions
{
    public const CADASTROS_VIEW = 'cadastros.view';

    public const CADASTROS_CREATE = 'cadastros.create';

    public const CADASTROS_EDIT = 'cadastros.edit';

    public const CADASTROS_DELETE = 'cadastros.delete';

    public const PEDIDOS_VIEW = 'pedidos.view';

    public const PEDIDOS_EDIT = 'pedidos.edit';

    public const ESTOQUE_ADJUST = 'estoque.adjust';

    public const CONFIGURACOES_USUARIOS = 'configuracoes.usuarios';

    public const CONFIGURACOES_AUDITORIA = 'configuracoes.auditoria';

    public const RELATORIOS_VIEW = 'relatorios.view';

    public const FINANCEIRO_VIEW = 'financeiro.view';

    public const FINANCEIRO_CREATE = 'financeiro.create';

    public const FINANCEIRO_EDIT = 'financeiro.edit';

    public const FINANCEIRO_DELETE = 'financeiro.delete';

    public const OPERACIONAL_VIEW = 'operacional.view';

    public const OPERACIONAL_CREATE = 'operacional.create';

    public const OPERACIONAL_EDIT = 'operacional.edit';

    public const OPERACIONAL_DELETE = 'operacional.delete';

    public const ALL = [
        self::CADASTROS_VIEW,
        self::CADASTROS_CREATE,
        self::CADASTROS_EDIT,
        self::CADASTROS_DELETE,
        self::PEDIDOS_VIEW,
        self::PEDIDOS_EDIT,
        self::ESTOQUE_ADJUST,
        self::CONFIGURACOES_USUARIOS,
        self::CONFIGURACOES_AUDITORIA,
        self::RELATORIOS_VIEW,
        self::FINANCEIRO_VIEW,
        self::FINANCEIRO_CREATE,
        self::FINANCEIRO_EDIT,
        self::FINANCEIRO_DELETE,
        self::OPERACIONAL_VIEW,
        self::OPERACIONAL_CREATE,
        self::OPERACIONAL_EDIT,
        self::OPERACIONAL_DELETE,
    ];

    /**
     * Subset exposed in the Manager/Subscriber permission matrix editor.
     * `configuracoes.*` are intentionally excluded — those routes are
     * hard-gated to Admin only (see routes/web.php), not role-configurable.
     */
    public const CONFIGURABLE = [
        self::CADASTROS_VIEW,
        self::CADASTROS_CREATE,
        self::CADASTROS_EDIT,
        self::CADASTROS_DELETE,
        self::PEDIDOS_VIEW,
        self::PEDIDOS_EDIT,
        self::ESTOQUE_ADJUST,
        self::RELATORIOS_VIEW,
        self::FINANCEIRO_VIEW,
        self::FINANCEIRO_CREATE,
        self::FINANCEIRO_EDIT,
        self::FINANCEIRO_DELETE,
        self::OPERACIONAL_VIEW,
        self::OPERACIONAL_CREATE,
        self::OPERACIONAL_EDIT,
        self::OPERACIONAL_DELETE,
    ];

    /** Seed defaults — also the fallback used if a role/permission row is missing. */
    public const DEFAULTS = [
        User::ROLE_MANAGER => [
            self::CADASTROS_VIEW,
            self::CADASTROS_CREATE,
            self::CADASTROS_EDIT,
            self::PEDIDOS_VIEW,
            self::PEDIDOS_EDIT,
            self::ESTOQUE_ADJUST,
            self::RELATORIOS_VIEW,
            self::FINANCEIRO_VIEW,
            self::FINANCEIRO_CREATE,
            self::FINANCEIRO_EDIT,
            self::OPERACIONAL_VIEW,
            self::OPERACIONAL_CREATE,
            self::OPERACIONAL_EDIT,
        ],
        User::ROLE_SUBSCRIBER => [
            self::CADASTROS_VIEW,
            self::PEDIDOS_VIEW,
            self::RELATORIOS_VIEW,
            self::FINANCEIRO_VIEW,
            self::OPERACIONAL_VIEW,
        ],
    ];

    public static function has(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return in_array($permission, self::allFor($user), true);
    }

    /**
     * @return array<int, string>
     */
    public static function allFor(User $user): array
    {
        if ($user->isAdmin()) {
            return ['*', ...self::ALL];
        }

        if (! $user->isStaff()) {
            return [];
        }

        return RolePermission::query()
            ->where('role', $user->role)
            ->where('granted', true)
            ->pluck('permission')
            ->all();
    }
}
