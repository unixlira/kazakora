<?php

namespace App\Modules\Financeiro\Support;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Falha na coleta financeira da Amazon ou do TikTok vira WhatsApp da Manuela
 * para o Lira na hora, com o motivo (pedido 2026-10-16). O mesmo motivo do
 * mesmo canal só repete depois do intervalo, para não virar enxurrada.
 *
 * Mensagem livre só vale dentro da janela de 24 h desde a última mensagem do
 * Lira; fora dela vai pelo template aprovado na Meta (corpo com {{1}} = canal
 * e {{2}} = motivo). Sem template, fica gravada no histórico como
 * "fora_da_janela" e o sino do admin continua avisando.
 */
class AlertaColetaFinanceira
{
    public function __construct(
        private readonly WhatsAppOutbox $outbox,
        private readonly WhatsAppCloudApiClient $client,
        private readonly WhatsAppSettings $settings,
    ) {}

    /**
     * @param  string|null  $chave  agrupa motivos que mudam de texto (ex.: contagem de pedidos)
     * @return string desligado|repetido|sem_credencial|sent|template|fora_da_janela|failed
     */
    public function avisar(string $canal, string $motivo, ?string $chave = null, ?int $intervaloMinutos = null): string
    {
        $config = config('services.whatsapp.alerta_coleta');
        $para = preg_replace('/\D/', '', (string) ($config['para'] ?? ''));

        if (! ($config['ativo'] ?? false) || $para === '') {
            return 'desligado';
        }

        $motivo = trim(Str::limit(trim($motivo), 400));
        $trava = 'alerta_coleta.'.md5(mb_strtolower($canal).'|'.($chave ?? $motivo));
        $minutos = $intervaloMinutos ?? (int) ($config['intervalo_minutos'] ?? 60);

        if (! Cache::add($trava, true, now()->addMinutes(max(1, $minutos)))) {
            return 'repetido';
        }

        Log::warning('coleta_financeira_falhou', ['canal' => $canal, 'motivo' => $motivo]);

        if (! $this->settings->isReadyToSend()) {
            Cache::forget($trava);

            return 'sem_credencial';
        }

        $conversa = WhatsAppConversation::query()->firstOrCreate(
            ['wa_id' => $para],
            ['phone' => $para, 'profile_name' => 'Lira', 'last_message_at' => now(), 'unread_count' => 0],
        );

        $texto = "⚠️ Falha na coleta financeira {$canal}\n\n{$motivo}\n\n".now()->format('d/m H:i').'. Precisa da sua ação.';
        $extra = ['origem' => 'alerta-coleta-financeira', 'canal' => $canal];

        $dentroDaJanela = $conversa->last_customer_message_at?->gt(now()->subHours(24)) ?? false;

        if ($dentroDaJanela) {
            $status = $this->outbox->sendText($conversa, $texto, 'manuela', null, $extra)->status;
        } elseif (filled($config['template'] ?? null)) {
            $status = $this->porTemplate($conversa, $canal, $motivo, $texto, (string) $config['template'], (string) ($config['template_idioma'] ?? 'pt_BR'), $extra);
        } else {
            $this->gravar($conversa, $texto, 'fora_da_janela', $extra);
            $status = 'fora_da_janela';
        }

        // Não saiu: libera a trava para a próxima falha tentar de novo.
        if (! in_array($status, ['sent', 'template'], true)) {
            Cache::forget($trava);
        }

        return $status;
    }

    private function porTemplate(WhatsAppConversation $conversa, string $canal, string $motivo, string $texto, string $template, string $idioma, array $extra): string
    {
        // Parâmetro de template na Meta não aceita quebra de linha nem 4+ espaços seguidos.
        $motivoTemplate = Str::squish($motivo);

        try {
            $resposta = $this->client->sendTemplate($conversa->wa_id, $template, $idioma, bodyParams: [$canal, $motivoTemplate]);
        } catch (Throwable $e) {
            Log::warning('alerta_coleta_template_falhou', ['error' => $e->getMessage()]);
            $this->gravar($conversa, $texto, 'failed', $extra + ['template' => $template, 'error' => $e->getMessage()]);

            return 'failed';
        }

        $this->gravar($conversa, $texto, 'sent', $extra + ['template' => $template, 'meta_response' => $resposta], 'template', $resposta['messages'][0]['id'] ?? null);

        return 'template';
    }

    private function gravar(WhatsAppConversation $conversa, string $texto, string $status, array $payload, string $tipo = 'text', ?string $waMessageId = null): void
    {
        $mensagem = WhatsAppMessage::query()->create([
            'conversation_id' => $conversa->id,
            'wa_message_id' => $waMessageId,
            'direction' => 'outbound',
            'type' => $tipo,
            'body' => $texto,
            'status' => $status,
            'sent_by' => 'manuela',
            'payload' => $payload,
            'sent_at' => now(),
        ]);
        $conversa->registerMessage($mensagem);
    }
}
