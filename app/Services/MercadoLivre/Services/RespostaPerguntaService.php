<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Publica a resposta de uma pergunta no anúncio (POST /answers). Resposta do
 * ML não pode ser editada nem apagada depois: sem nova tentativa automática
 * (postOnce) e só pergunta ainda aberta.
 */
class RespostaPerguntaService
{
    public const LIMITE = 2000;

    public function __construct(private readonly MercadoLivreClient $client) {}

    public function enviar(MercadoLivrePergunta $pergunta, string $texto, string $por, ?int $userId = null): void
    {
        $texto = trim($texto);

        if ($texto === '' || mb_strlen($texto) > self::LIMITE) {
            throw new RuntimeException('A resposta precisa ter de 1 a '.self::LIMITE.' caracteres.');
        }

        if (! $pergunta->aberta()) {
            throw new RuntimeException('Essa pergunta já foi respondida.');
        }

        try {
            $this->client->postOnce('answers', ['question_id' => (int) $pergunta->question_id, 'text' => $texto]);
        } catch (Throwable $e) {
            $pergunta->update(['erro_envio' => mb_substr($e->getMessage(), 0, 500)]);
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.questions.envio_falhou', ['pergunta' => $pergunta->question_id, 'erro' => mb_substr($e->getMessage(), 0, 180)]);

            throw new RuntimeException('O Mercado Livre recusou a resposta: '.mb_substr($e->getMessage(), 0, 200), 0, $e);
        }

        $pergunta->update([
            'status' => 'ANSWERED',
            'resposta_enviada' => $texto,
            'respondida_em' => now(),
            'respondida_por' => $por,
            'respondida_por_user_id' => $userId,
            'erro_envio' => null,
        ]);
    }
}
