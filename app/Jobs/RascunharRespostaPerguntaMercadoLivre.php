<?php

namespace App\Jobs;

use App\Modules\Catalog\Support\TextoIaSemPreco;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A Manuela escreve o rascunho da resposta a partir do anúncio (título,
 * atributos, descrição). Só rascunho: falar com cliente exige OK do Lira, e
 * quando a informação não está no anúncio ela marca [HUMANO] em vez de chutar.
 */
class RascunharRespostaPerguntaMercadoLivre implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $perguntaId)
    {
        $this->onQueue(config('mercadolivre.queue'));
    }

    public function handle(GeminiClient $gemini, MercadoLivreClient $ml): void
    {
        $pergunta = MercadoLivrePergunta::find($this->perguntaId);

        if (! $pergunta || $pergunta->rascunho_em !== null || ! $gemini->isConfigured()) {
            return;
        }

        $item = $ml->get("items/{$pergunta->item_id}");
        $descricao = '';

        try {
            $descricao = (string) ($ml->get("items/{$pergunta->item_id}/description")['plain_text'] ?? '');
        } catch (Throwable) {
            // anúncio sem descrição: segue só com título e atributos
        }

        $atributos = collect($item['attributes'] ?? [])
            ->map(fn ($a) => ($a['name'] ?? '').': '.($a['value_name'] ?? ''))
            ->filter(fn ($l) => trim($l, ': ') !== '')
            ->implode("\n");

        $ficha = 'Título: '.($item['title'] ?? '')."\nAtributos:\n".$atributos."\nDescrição:\n".mb_substr($descricao, 0, 4000);

        try {
            $texto = $gemini->generate(
                (string) config('services.gemini.chat_model'),
                [['role' => 'user', 'parts' => [['text' => "FICHA DO ANÚNCIO:\n{$ficha}\n\nPERGUNTA DO COMPRADOR:\n".mb_substr((string) $pergunta->pergunta, 0, 1000)]]]],
                $this->instrucoes(),
                4096,
                GeminiUsageLog::PURPOSE_REPLY,
            );
        } catch (Throwable $e) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.questions.gemini_falhou', ['pergunta' => $pergunta->question_id, 'erro' => mb_substr($e->getMessage(), 0, 180)]);

            return;
        }

        $humano = str_contains($texto, '[HUMANO]');
        $texto = trim(str_replace('[HUMANO]', '', $texto));
        $texto = trim(TextoIaSemPreco::semPreco($texto));

        $pergunta->update([
            'resposta_rascunho' => $humano || $texto === '' ? null : mb_substr($texto, 0, 2000),
            'precisa_humano' => $humano || $texto === '',
            'rascunho_em' => now(),
        ]);
    }

    private function instrucoes(): string
    {
        return <<<'TXT'
Você é a Manuela, atendente da KazaKora, respondendo uma pergunta pública num anúncio do Mercado Livre. Escreva como uma pessoa: simpática, direta, português do Brasil, sem markdown, sem travessões, até 3 frases, começando com um cumprimento curto e terminando com "Qualquer dúvida, estamos à disposição!".
Responda SÓ com o que está na ficha do anúncio. Se a resposta não estiver na ficha, não chute: responda apenas [HUMANO].
Regras do Mercado Livre: proibido passar telefone, e-mail, WhatsApp, links ou endereço, e chamar o comprador para negociar fora do Mercado Livre. Não prometa o que a ficha não diz.
Garantia, troca e devolução: pode tranquilizar com o direito do consumidor (Código de Defesa do Consumidor: 7 dias para devolver compra feita pela internet) e com a Compra Garantida do Mercado Livre, sem inventar prazos ou condições.
TXT.' '.TextoIaSemPreco::regra();
    }
}
