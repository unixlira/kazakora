<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBlingOrderWebhook;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Bling\BlingOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Bling — pedidos do TikTok Shop em tempo real (pedido explícito
 * 2026-09-02: "temos que ter o webhook, o polling é consumo de máquina
 * desnecessário"). Substitui o poll de 2 em 2 minutos como caminho
 * principal; a varredura horária continua como rede de segurança, porque o
 * Bling DESLIGA a configuração do webhook depois de 3 dias falhando
 * entrega e ela só volta com reativação manual na tela do aplicativo.
 *
 * Regras que o Bling impõe e que moldam este controller:
 *
 * - **2xx em até 5 segundos**, senão entra em retentativa (por até 3 dias,
 *   com intervalo crescente). Por isso aqui só valida, registra e
 *   despacha — o import de verdade roda em job (ProcessBlingOrderWebhook).
 * - **Entrega duplicada é esperada** — o mesmo evento pode chegar 2x, e as
 *   duas têm que responder 2xx. Deduplicado por `eventId` (único por
 *   evento) via cache, não por conteúdo.
 * - **Sem garantia de ordem** — um `updated` pode chegar antes do
 *   `created` do mesmo pedido. Não é problema aqui: os dois caminhos caem
 *   no mesmo import idempotente, que já resolve criação x atualização
 *   sozinho (OrderImportService::importNormalized()).
 * - **Assinatura**: HMAC-SHA256 hexadecimal do corpo BRUTO com o client
 *   secret do aplicativo, no header `X-Bling-Signature-256`, prefixado com
 *   "sha256=". Conferido antes de qualquer parse/normalização de JSON.
 *
 * O payload já traz `data.loja` e `data.situacao`, então o filtro da loja
 * do TikTok Shop sai direto do corpo, sem gastar uma chamada de API (o
 * teto do Bling é 3 req/s pra CONTA inteira — a mesma fila da emissão de
 * nota e da busca de etiqueta).
 */
class BlingWebhookController extends Controller
{
    /** O Bling reentrega o mesmo evento por até 3 dias — a janela de dedupe cobre isso inteiro. */
    private const DEDUPE_TTL_DAYS = 3;

    public function webhook(Request $request, BlingOrderService $blingOrders): JsonResponse
    {
        $rawBody = $request->getContent();
        $signatureValid = $this->hasValidSignature($rawBody, (string) $request->header('X-Bling-Signature-256', ''));

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];

        $eventId = isset($payload['eventId']) ? (string) $payload['eventId'] : null;
        $event = isset($payload['event']) ? (string) $payload['event'] : null;

        if (! $signatureValid) {
            ChannelWebhookLog::create([
                // Canal do NEGÓCIO, não do cano: o Bling é só por onde o
                // pedido do TikTok Shop chega (ver TikTokShopDriver), e é
                // em "TikTok Shop" que alguém vai procurar esse log.
                'channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
                'event_type' => $event,
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'signature_valid' => false,
                'status' => ChannelWebhookLog::STATUS_REJECTED,
                'error_message' => 'Assinatura X-Bling-Signature-256 inválida.',
            ]);

            Log::warning('bling.webhook.invalid_signature', ['event' => $event, 'event_id' => $eventId]);

            // 401 de propósito (ao contrário da Shopee, que exige 200 pra
            // validar a URL no painel): se o segredo estiver errado no
            // .env, o Bling retenta e depois desliga o webhook — falha
            // barulhenta é melhor que engolir tudo com 200 e descobrir
            // semanas depois que nenhum pedido chegava.
            return response()->json(['status' => 'invalid signature'], 401);
        }

        // Dedupe por eventId (o Bling avisa que entrega repetida é
        // esperada). Cache::add é atômico: devolve false se a chave já
        // existe, então duas entregas simultâneas não passam as duas.
        if ($eventId !== null && ! Cache::add("bling.webhook.event.{$eventId}", true, now()->addDays(self::DEDUPE_TTL_DAYS))) {
            Log::info('bling.webhook.duplicate_ignored', ['event' => $event, 'event_id' => $eventId]);

            return response()->json(['status' => 'duplicate']);
        }

        // "order.updated" -> recurso "order". O servidor de webhook do
        // Bling é um só pra vários recursos (hoje `order` e `invoice`
        // estão ativos pro mesmo endpoint), então o recurso precisa ser
        // olhado antes de qualquer coisa: um evento de NOTA não tem
        // numeroLoja e não é pedido nenhum.
        $resource = $event !== null ? strtok($event, '.') : null;

        if ($resource !== 'order') {
            ChannelWebhookLog::create([
                'channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
                'event_type' => $event,
                'payload' => $payload,
                'headers' => $request->headers->all(),
                'signature_valid' => true,
                'status' => ChannelWebhookLog::STATUS_IGNORED,
                // `invoice` foi ativado junto com `order` de propósito
                // (elimina polling de situação de nota quando a emissão
                // pela API do Bling entrar — fase seguinte). Até lá, quem
                // emite NF-e aqui somos nós, então não há o que fazer com
                // o evento além de registrar que ele chegou.
                'error_message' => $resource === 'invoice'
                    ? 'Evento de nota fiscal do Bling — registrado; a emissão hoje é nossa, nada a sincronizar ainda.'
                    : "Recurso \"{$resource}\" não tratado por este endpoint.",
            ]);

            return response()->json(['status' => 'ignored']);
        }

        $lojaId = $payload['data']['loja']['id'] ?? null;
        $tiktokLojaId = $blingOrders->tiktokLojaId();
        $isTiktokStore = $tiktokLojaId !== null && (int) $lojaId === (int) $tiktokLojaId;

        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
            'event_type' => $event,
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'signature_valid' => true,
            'status' => $isTiktokStore ? ChannelWebhookLog::STATUS_RECEIVED : ChannelWebhookLog::STATUS_IGNORED,
            'error_message' => $isTiktokStore ? null : 'Evento de outra loja do Bling — não é do TikTok Shop.',
        ]);

        // O webhook dispara pra TODO pedido de venda da conta Bling, não só
        // do TikTok Shop. Pedido de outra loja é descartado aqui mesmo (com
        // 2xx, senão o Bling retenta por 3 dias um evento que nunca vamos
        // querer).
        if (! $isTiktokStore) {
            return response()->json(['status' => 'ignored']);
        }

        ProcessBlingOrderWebhook::dispatch($payload, $log->id);

        return response()->json(['status' => 'received']);
    }

    private function hasValidSignature(string $rawBody, string $header): bool
    {
        $secret = config('services.bling.client_secret');

        if (! $secret || $header === '') {
            return false;
        }

        // Header vem como "sha256=<hex>" — comparar o hex puro dos dois
        // lados evita depender de o Bling manter exatamente esse prefixo.
        $received = str_starts_with($header, 'sha256=') ? substr($header, 7) : $header;
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $received);
    }
}
