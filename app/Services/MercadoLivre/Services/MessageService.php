<?php

namespace App\Services\MercadoLivre\Services;

use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;

class MessageService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function getMessages(string $orderId): array
    {
        return $this->client->get("messages/packs/{$orderId}/sellers");
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $orderId, string $text): array
    {
        return $this->client->post('messages', [
            'order_id' => $orderId,
            'text' => $text,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuestions(int $mlUserId): array
    {
        return $this->client->get('my/received_questions/search', ['seller_id' => $mlUserId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function answerQuestion(int $questionId, string $text): array
    {
        return $this->client->post('answers', [
            'question_id' => $questionId,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.messages', $payload);
    }
}
