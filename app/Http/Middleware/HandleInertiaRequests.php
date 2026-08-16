<?php

namespace App\Http\Middleware;

use App\Modules\Cart\Support\CartManager;
use App\Modules\Catalog\Models\Favorite;
use App\Notifications\InvoiceIssuanceFailedNotification;
use App\Notifications\LabelUnavailableNotification;
use App\Notifications\LowStockNotification;
use App\Notifications\OversellDetectedNotification;
use App\Notifications\PrintJobFailedNotification;
use App\Support\Rbac\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Notificações operacionais internas (NF-e falhou, etiqueta presa,
     * estoque negativo evitado) — só existem porque Notification::send()
     * manda pra cada admin (User::where('role', ROLE_ADMIN)), então já são
     * corretamente isoladas por usuário (a relação notifications() do
     * Laravel filtra por notifiable_id, nunca vaza entre contas). O
     * problema real era outro: a MESMA sineta/prop é usada tanto em
     * AdminLayout quanto em AppLayout (loja) — um admin navegando pela loja
     * como cliente via a própria conta via esses alertas internos ali,
     * fora de contexto. Filtra esses tipos fora do contexto admin.
     */
    private const ADMIN_ONLY_NOTIFICATION_TYPES = [
        InvoiceIssuanceFailedNotification::class,
        LabelUnavailableNotification::class,
        PrintJobFailedNotification::class,
        OversellDetectedNotification::class,
        LowStockNotification::class,
    ];

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'role', 'avatar_url', 'initials'),
            ],
            'permissions' => fn () => $request->user() ? Permissions::allFor($request->user()) : [],
            'cart' => fn () => [
                'count' => app(CartManager::class)->count(),
            ],
            'favorites' => fn () => [
                'count' => $request->user() ? Favorite::query()->where('user_id', $request->user()->id)->count() : 0,
            ],
            'notifications' => fn () => $this->notificationsFor($request),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'warning' => $request->session()->get('warning'),
                // Estoque baixo no login (pedido explícito 2026-08-16) —
                // ver AuthenticatedSessionController::store() e
                // LowStockAlertModal.vue.
                'lowStockProducts' => $request->session()->get('lowStockProducts'),
            ],
        ];
    }

    /**
     * @return array{unreadCount: int, items: array<int, array<string, mixed>>}
     */
    private function notificationsFor(Request $request): array
    {
        $user = $request->user();

        if (! $user) {
            return ['unreadCount' => 0, 'items' => []];
        }

        // Fora de /admin (loja), mesmo um usuário com role admin não deve
        // ver os alertas operacionais internos ali — essa sineta é a
        // mesma dos dois layouts, só o conteúdo muda por contexto.
        // ->notifications() é chamado de novo (não reaproveitado/clonado)
        // pra cada query — MorphMany não clona a Builder interna de forma
        // confiável, então reconstruir do zero evita as duas consultas se
        // contaminarem.
        $isAdminArea = $request->is('admin*');

        $scope = function ($query) use ($isAdminArea) {
            if (! $isAdminArea) {
                $query->whereNotIn('type', self::ADMIN_ONLY_NOTIFICATION_TYPES);
            }

            return $query;
        };

        return [
            'unreadCount' => $scope($user->notifications())->whereNull('read_at')->count(),
            // Pedido explícito 2026-08-16: "quando são visualizadas elas
            // não aparecem na barra de notificação" — a sineta/dropdown só
            // mostra as NÃO lidas agora (antes mostrava as últimas 8
            // independente de lida ou não). A lista completa (lida +
            // não lida) mora na tela /notificacoes, ver NotificationController::index().
            'items' => $scope($user->notifications())->whereNull('read_at')->latest()->limit(8)->get()->map(fn ($notification) => [
                'id' => $notification->id,
                'message' => $notification->data['message'] ?? '',
                // 'body'/'link' só existem em PromotionalNotification por
                // enquanto (cupom/promoção) — null nos outros tipos, front
                // já trata como opcional.
                'body' => $notification->data['body'] ?? null,
                'link' => $notification->data['link'] ?? null,
                'read' => $notification->read_at !== null,
                'createdAt' => $notification->created_at->diffForHumans(),
            ])->all(),
        ];
    }
}
