<?php

namespace App\Services\MercadoLivre\Services;

use App\Jobs\RascunharRespostaPerguntaMercadoLivre;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Tópico "questions" do webhook: a notificação só traz o resource
 * (/questions/ID); a pergunta em si vem de um GET. Guarda a pergunta e, se
 * ainda está sem resposta, pede o rascunho da Manuela.
 */
class QuestionService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        $id = (int) preg_replace('/\D/', '', (string) ($payload['resource'] ?? ''));

        if ($id === 0) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.questions.sem_id', $payload);

            return;
        }

        $dados = $this->client->get("questions/{$id}", ['api_version' => 4]);

        $pergunta = MercadoLivrePergunta::updateOrCreate(
            ['question_id' => $id],
            [
                'item_id' => (string) ($dados['item_id'] ?? ''),
                'comprador_id' => $dados['from']['id'] ?? $dados['buyer_id'] ?? null,
                'status' => strtoupper((string) ($dados['status'] ?? '')),
                'pergunta' => $dados['text'] ?? null,
                'perguntada_em' => isset($dados['date_created']) ? Carbon::parse($dados['date_created']) : null,
                // Respondida direto no ML (ou por outro app): fecha o rascunho.
                'respondida_em' => ! empty($dados['answer']['date_created']) ? Carbon::parse($dados['answer']['date_created']) : null,
            ],
        );

        if ($pergunta->status === 'UNANSWERED' && $pergunta->rascunho_em === null && filled($pergunta->pergunta)) {
            RascunharRespostaPerguntaMercadoLivre::dispatch($pergunta->id);
        }
    }
}
