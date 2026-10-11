<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Tela "ver todas" (pedido explícito 2026-08-16) — lida + não lida
     * juntas, mais recente primeiro. A sineta do topo (HandleInertiaRequests
     * ::notificationsFor()) só mostra as não lidas; esta tela é o histórico
     * completo.
     */
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->paginate(30)
            ->through(fn ($notification) => [
                'id' => $notification->id,
                'message' => $notification->data['message'] ?? '',
                'body' => $notification->data['body'] ?? null,
                'link' => $notification->data['link'] ?? null,
                'read' => $notification->read_at !== null,
                'createdAt' => $notification->created_at->diffForHumans(),
            ]);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        /** @var Model|null $record */
        $record = $request->user()->notifications()->whereKey($notification)->first();

        $record?->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Botão de excluir aviso (pedido explícito 2026-08-16) — some de vez,
     * não é só "marcar como lida" (isso já existia via markRead). Usado
     * tanto no dropdown da sineta quanto na tela /notificacoes.
     */
    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->whereKey($notification)->delete();

        return back();
    }
}
