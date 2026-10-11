<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WhatsAppSettingsController extends Controller
{
    public function edit(WhatsAppSettings $settings): Response
    {
        $settingsPayload = $settings->all();
        $configuredCallback = config('services.whatsapp.webhook_url') ?: url('/api/whatsapp/webhook');
        $lastInboundAt = WhatsAppConversation::query()->max('last_customer_message_at');

        return Inertia::render('Admin/WhatsApp/Index', [
            'settings' => $settingsPayload,
            'callbackUrl' => $configuredCallback,
            'requestedCallbackUrl' => config('services.whatsapp.webhook_url') ?: 'https://kazakora.devlira.com.br/api/webhooks/whatsapp',
            'credentials' => [
                'accessToken' => filled(config('services.whatsapp.access_token')),
                'phoneNumberId' => filled(config('services.whatsapp.phone_number_id')),
                'businessAccountId' => filled(config('services.whatsapp.business_account_id')),
                'appSecret' => filled(config('services.whatsapp.app_secret')),
                'readyToSend' => $settings->isReadyToSend(),
                'manuelaRemote' => app(ManuelaAgentClient::class)->isConfigured(),
            ],
            'aiUsage' => $this->aiUsage(),
            'stats' => [
                'conversations' => WhatsAppConversation::query()->count(),
                'needsHuman' => WhatsAppConversation::query()->where('needs_human', true)->count(),
                'lastInboundAt' => $lastInboundAt ? Carbon::parse($lastInboundAt)->toISOString() : null,
            ],
        ]);
    }

    public function update(Request $request, WhatsAppSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['boolean'],
            'auto_reply_enabled' => ['boolean'],
            'sandbox_mode' => ['boolean'],
            'attendant_name' => ['required', 'string', 'max:80'],
            'brand_name' => ['required', 'string', 'max:80'],
            'tone' => ['required', 'string', 'max:40'],
            'max_questions_before_close' => ['required', 'integer', 'min:1', 'max:3'],
            'store_base_url' => ['required', 'url', 'max:255'],
            'welcome_message' => ['required', 'string', 'max:800'],
            'outside_hours_message' => ['required', 'string', 'max:800'],
            'closing_template' => ['required', 'string', 'max:800'],
            'handoff_keywords' => ['required', 'string', 'max:1000'],
            'priority_categories' => ['nullable', 'string', 'max:1000'],
            'forbidden_promises' => ['required', 'string', 'max:1000'],
            'business_hours' => ['nullable', 'string', 'max:255'],
            'verify_token' => ['required', 'string', 'min:20', 'max:120'],
            'agent_instructions' => ['required', 'string', 'max:3000'],
        ]);

        $settings->setMany($validated);

        return back()->with('success', 'Configuração do WhatsApp/Manuela salva.');
    }

    public function testSend(Request $request, WhatsAppCloudApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'to' => ['required', 'string', 'max:32'],
            'message' => ['required', 'string', 'max:600'],
        ]);

        try {
            $client->sendText(preg_replace('/\D+/', '', $validated['to']), $validated['message']);
        } catch (Throwable $exception) {
            return back()->with('error', 'Teste não enviado: '.$exception->getMessage());
        }

        return back()->with('success', 'Mensagem de teste enviada pela API oficial do WhatsApp.');
    }

    /**
     * Consumo do Gemini pra conferir com o painel do Google (pedido
     * 2026-10-08: "monitorar os créditos").
     *
     * @return array<string, mixed>
     */
    private function aiUsage(): array
    {
        $periodo = fn ($desde) => GeminiUsageLog::query()
            ->where('created_at', '>=', $desde)
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(prompt_tokens), 0) as prompt, COALESCE(SUM(output_tokens), 0) as output, COALESCE(SUM(total_tokens), 0) as total')
            ->first();
        $formatar = fn ($linha) => [
            'calls' => (int) $linha->calls,
            'prompt' => (int) $linha->prompt,
            'output' => (int) $linha->output,
            'total' => (int) $linha->total,
        ];

        return [
            'today' => $formatar($periodo(now()->startOfDay())),
            'week' => $formatar($periodo(now()->subDays(6)->startOfDay())),
            'month' => $formatar($periodo(now()->startOfMonth())),
            'byModel' => GeminiUsageLog::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->selectRaw('model, purpose, COUNT(*) as calls, SUM(prompt_tokens) as prompt, SUM(output_tokens) as output, SUM(total_tokens) as total')
                ->groupBy('model', 'purpose')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($linha) => ['model' => $linha->model, 'purpose' => $linha->purpose] + $formatar($linha))
                ->all(),
        ];
    }
}
