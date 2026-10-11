<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Jobs\SendPromotionalNotificationJob;
use App\Modules\Admin\Models\PromotionalNotificationCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PromotionalNotificationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/PromotionalNotifications/Index', [
            'campaigns' => PromotionalNotificationCampaign::query()
                ->with('creator:id,name')
                ->latest()
                ->get(['id', 'title', 'message', 'link', 'created_by', 'recipients_count', 'sent_at', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:500'],
            'link' => ['nullable', 'string', 'max:255'],
        ]);

        $campaign = PromotionalNotificationCampaign::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        SendPromotionalNotificationJob::dispatch($campaign->id);

        return back()->with('success', 'Notificação agendada — vai ser enviada pra todos os clientes em instantes.');
    }
}
