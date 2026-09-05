<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Models\WhatsAppCampaign;
use App\Modules\WhatsApp\Models\WhatsAppCampaignRecipient;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WhatsAppCampaignController extends Controller
{
    private const REAL_SEND_CONFIRMATION = 'ENVIAR WHATSAPP';
    private const MAX_RECIPIENTS_PER_BATCH = 200;

    public function index(WhatsAppSettings $settings): Response
    {
        $campaigns = WhatsAppCampaign::query()
            ->withCount('recipients')
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (WhatsAppCampaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'mode' => $campaign->mode,
                'status' => $campaign->status,
                'dry_run' => $campaign->dry_run,
                'template_name' => $campaign->template_name,
                'template_language' => $campaign->template_language,
                'media_type' => $campaign->media_type,
                'media_original_name' => $campaign->media_original_name,
                'media_url' => $campaign->media_path ? Storage::disk('public')->url($campaign->media_path) : null,
                'total_recipients' => $campaign->total_recipients,
                'sent_count' => $campaign->sent_count,
                'failed_count' => $campaign->failed_count,
                'created_at' => $campaign->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'finished_at' => $campaign->finished_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Admin/WhatsApp/Campaigns', [
            'campaigns' => $campaigns,
            'credentials' => [
                'accessToken' => filled(config('services.whatsapp.access_token')),
                'phoneNumberId' => filled(config('services.whatsapp.phone_number_id')),
                'readyToSend' => $settings->isReadyToSend(),
            ],
            'settings' => [
                'enabled' => $settings->bool('enabled'),
                'sandbox_mode' => $settings->bool('sandbox_mode'),
                'store_base_url' => $settings->get('store_base_url', 'https://kazakora.devlira.com.br'),
            ],
            'limits' => [
                'maxRecipientsPerBatch' => self::MAX_RECIPIENTS_PER_BATCH,
                'realSendConfirmation' => self::REAL_SEND_CONFIRMATION,
            ],
        ]);
    }

    public function store(Request $request, WhatsAppSettings $settings, WhatsAppCloudApiClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['required', Rule::in(['text', 'template'])],
            'numbers_text' => ['required', 'string', 'max:12000'],
            'message_body' => ['nullable', 'string', 'max:1000'],
            'template_name' => ['nullable', 'required_if:mode,template', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/'],
            'template_language' => ['nullable', 'required_if:mode,template', 'string', 'max:16'],
            'media_file' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,video/mp4,video/3gpp', 'max:16384'],
            'dry_run' => ['boolean'],
            'confirmation' => ['nullable', 'string', 'max:40'],
        ]);

        $recipients = $this->parseRecipients($validated['numbers_text']);

        if ($recipients === []) {
            return back()->with('error', 'Nenhum número válido encontrado. Use DDI + DDD + número, por exemplo 5511999999999.');
        }

        if (count($recipients) > self::MAX_RECIPIENTS_PER_BATCH) {
            return back()->with('error', 'Limite de '.self::MAX_RECIPIENTS_PER_BATCH.' contatos por lote para evitar bloqueio/erro operacional. Divida a campanha em partes.');
        }

        $dryRun = filter_var($validated['dry_run'] ?? true, FILTER_VALIDATE_BOOL);
        $hasMediaFile = $request->hasFile('media_file');

        if (($validated['mode'] ?? 'text') === 'text' && blank($validated['message_body'] ?? null) && ! $hasMediaFile) {
            return back()->withErrors(['message_body' => 'Digite uma mensagem ou anexe uma imagem/vídeo para enviar.']);
        }

        if ($hasMediaFile) {
            $uploadedMedia = $request->file('media_file');
            $mediaMime = (string) $uploadedMedia->getMimeType();
            if (str_starts_with($mediaMime, 'image/') && $uploadedMedia->getSize() > 5 * 1024 * 1024) {
                return back()->withErrors(['media_file' => 'Imagem para WhatsApp deve ter no máximo 5 MB.']);
            }
        }

        if (! $dryRun && $settings->bool('sandbox_mode')) {
            return back()->with('error', 'Modo cauteloso/sandbox está ligado. Desligue nas configurações do WhatsApp antes de um disparo real.');
        }

        if (! $dryRun && ! $settings->isReadyToSend()) {
            return back()->with('error', 'Credenciais do WhatsApp incompletas no .env. Disparo real bloqueado.');
        }

        if (! $dryRun && ($validated['confirmation'] ?? '') !== self::REAL_SEND_CONFIRMATION) {
            return back()->with('error', 'Disparo real bloqueado. Digite exatamente '.self::REAL_SEND_CONFIRMATION.' no campo de confirmação.');
        }

        $mediaInfo = $this->storeMediaAttachment($request);

        $campaign = DB::transaction(function () use ($validated, $recipients, $dryRun, $mediaInfo) {
            $campaign = WhatsAppCampaign::query()->create([
                'name' => $validated['name'],
                'mode' => $validated['mode'],
                'status' => $dryRun ? 'dry_run' : 'running',
                'template_name' => $validated['template_name'] ?? null,
                'template_language' => $validated['template_language'] ?? 'pt_BR',
                'message_body' => $validated['message_body'] ?? null,
                'media_type' => $mediaInfo['type'] ?? null,
                'media_path' => $mediaInfo['path'] ?? null,
                'media_original_name' => $mediaInfo['original_name'] ?? null,
                'media_mime' => $mediaInfo['mime'] ?? null,
                'total_recipients' => count($recipients),
                'dry_run' => $dryRun,
                'created_by' => request()->user()?->id,
                'started_at' => $dryRun ? null : now(),
                'metadata' => ['source' => 'admin_manual_batch'],
            ]);

            foreach ($recipients as $recipient) {
                $campaign->recipients()->create([
                    'phone' => $recipient['phone'],
                    'name' => $recipient['name'],
                    'status' => $dryRun ? 'preview' : 'pending',
                ]);
            }

            return $campaign->fresh('recipients');
        });

        if ($dryRun) {
            return back()->with('success', 'Prévia criada com '.count($recipients).' contato(s). Nenhuma mensagem foi enviada.');
        }

        $sent = 0;
        $failed = 0;
        $whatsAppMediaId = null;

        if ($mediaInfo) {
            try {
                $whatsAppMediaId = $client->uploadMedia(
                    Storage::disk('public')->path($mediaInfo['path']),
                    $mediaInfo['mime'],
                );
                $campaign->update(['whatsapp_media_id' => $whatsAppMediaId]);
            } catch (Throwable $exception) {
                $campaign->update([
                    'status' => 'failed',
                    'failed_count' => $campaign->total_recipients,
                    'finished_at' => now(),
                    'metadata' => array_merge($campaign->metadata ?? [], ['media_upload_error' => Str::limit($exception->getMessage(), 1000)]),
                ]);

                $campaign->recipients()->update([
                    'status' => 'failed',
                    'error_message' => 'Mídia não subiu para a Cloud API: '.Str::limit($exception->getMessage(), 900),
                ]);

                return back()->with('error', 'Mídia não subiu para o WhatsApp. Nenhuma mensagem foi enviada: '.$exception->getMessage());
            }
        }

        foreach ($campaign->recipients as $recipient) {
            try {
                $response = $this->sendCampaignMessage(
                    $client,
                    $recipient->phone,
                    $validated,
                    $mediaInfo['type'] ?? null,
                    $whatsAppMediaId,
                );

                $recipient->update([
                    'status' => 'sent',
                    'wa_message_id' => $response['messages'][0]['id'] ?? null,
                    'payload' => ['meta_response' => $response],
                    'sent_at' => now(),
                ]);
                $sent++;
            } catch (Throwable $exception) {
                $recipient->update([
                    'status' => 'failed',
                    'error_message' => Str::limit($exception->getMessage(), 1000),
                ]);
                $failed++;
            }
        }

        $campaign->update([
            'status' => $failed > 0 ? ($sent > 0 ? 'partial' : 'failed') : 'finished',
            'sent_count' => $sent,
            'failed_count' => $failed,
            'finished_at' => now(),
        ]);

        return back()->with($failed > 0 ? 'warning' : 'success', "Disparo processado: {$sent} enviada(s), {$failed} com falha.");
    }


    private function sendCampaignMessage(
        WhatsAppCloudApiClient $client,
        string $phone,
        array $validated,
        ?string $mediaType,
        ?string $whatsAppMediaId,
    ): array {
        if (($validated['mode'] ?? 'text') === 'template') {
            return $client->sendTemplate(
                $phone,
                $validated['template_name'],
                $validated['template_language'] ?? 'pt_BR',
                $mediaType,
                $whatsAppMediaId,
            );
        }

        if ($mediaType && $whatsAppMediaId) {
            return $client->sendMedia($phone, $mediaType, $whatsAppMediaId, $validated['message_body'] ?? null);
        }

        return $client->sendText($phone, $validated['message_body']);
    }

    private function storeMediaAttachment(Request $request): ?array
    {
        if (! $request->hasFile('media_file')) {
            return null;
        }

        $file = $request->file('media_file');
        $mime = (string) $file->getMimeType();
        $type = str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : null);

        if (! $type) {
            return null;
        }

        $path = $file->store('whatsapp-campaigns', 'public');

        return [
            'type' => $type,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $mime,
        ];
    }

    private function parseRecipients(string $input): array
    {
        $recipients = [];

        foreach (preg_split('/\r\n|\r|\n/', $input) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$phonePart, $namePart] = array_pad(preg_split('/[,;|]/', $line, 2), 2, null);
            $phone = preg_replace('/\D+/', '', (string) $phonePart);

            if (str_starts_with($phone, '0')) {
                $phone = ltrim($phone, '0');
            }

            if (! str_starts_with($phone, '55') && strlen($phone) >= 10) {
                $phone = '55'.$phone;
            }

            if (! preg_match('/^55\d{10,11}$/', $phone)) {
                continue;
            }

            $recipients[$phone] = [
                'phone' => $phone,
                'name' => filled($namePart) ? trim((string) $namePart) : null,
            ];
        }

        return array_values($recipients);
    }
}
