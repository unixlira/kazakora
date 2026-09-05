<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        $conversations = WhatsAppConversation::query()
            ->latest('last_message_at')
            ->latest('id')
            ->limit(40)
            ->get()
            ->map(function (WhatsAppConversation $conversation) {
                $messages = $conversation->messages()
                    ->oldest('id')
                    ->limit(80)
                    ->get()
                    ->map(fn ($message) => [
                        'id' => $message->id,
                        'direction' => $message->direction,
                        'type' => $message->type,
                        'body' => $message->body,
                        'status' => $message->status,
                        'time' => ($message->sent_at ?? $message->received_at ?? $message->created_at)?->timezone('America/Sao_Paulo')->format('H:i'),
                        'date' => ($message->sent_at ?? $message->received_at ?? $message->created_at)?->timezone('America/Sao_Paulo')->format('d/m/Y'),
                    ]);
                $displayName = $conversation->profile_name ?: 'Contato sem nome';

                return [
                    'id' => $conversation->id,
                    'wa_id' => $conversation->wa_id,
                    'phone' => $conversation->phone,
                    'profile_name' => $conversation->profile_name,
                    'display_name' => $displayName,
                    'avatar_initials' => Str::of($displayName)->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->join('') ?: 'KK',
                    'profile_photo_url' => $conversation->profile_photo_url,
                    'status' => $conversation->status,
                    'needs_human' => $conversation->needs_human,
                    'unread_count' => $conversation->unread_count,
                    'last_message_preview' => $conversation->last_message_preview ?: ($messages->last()['body'] ?? 'Nenhuma mensagem registrada ainda.'),
                    'last_message_at' => $conversation->last_message_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                    'messages' => $messages,
                ];
            });

        return Inertia::render('Admin/WhatsApp/Index', [
            'settings' => $settingsPayload,
            'conversations' => $conversations,
            'callbackUrl' => $configuredCallback,
            'requestedCallbackUrl' => config('services.whatsapp.webhook_url') ?: 'https://kazakora.devlira.com.br/api/webhooks/whatsapp',
            'credentials' => [
                'accessToken' => filled(config('services.whatsapp.access_token')),
                'phoneNumberId' => filled(config('services.whatsapp.phone_number_id')),
                'businessAccountId' => filled(config('services.whatsapp.business_account_id')),
                'appSecret' => filled(config('services.whatsapp.app_secret')),
                'readyToSend' => $settings->isReadyToSend(),
            ],
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
}
