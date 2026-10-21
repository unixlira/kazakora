<?php

namespace App\Jobs;

use App\Models\User;
use App\Modules\Catalog\Support\TextoIaSemPreco;
use App\Modules\Marketplace\Models\MercadoLivrePergunta;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Notifications\PerguntaMercadoLivreSemRespostaNotification;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\MercadoLivre\Services\RespostaPerguntaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * A Manuela escreve o rascunho da resposta a partir do anúncio (título,
 * atributos, descrição). Quando a informação não está no anúncio ela marca
 * [HUMANO] em vez de chutar. Envio ao comprador só com "Manuela responde
 * sozinha" ligado em Perguntas MK Place (desligado por padrão) e se ninguém
 * assumiu a pergunta; senão o rascunho espera o atendente.
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

        if (! $pergunta || $pergunta->rascunho_em !== null || $pergunta->humano_assumiu_em !== null) {
            return;
        }

        if (! $gemini->isConfigured()) {
            $this->marcarParaLira($pergunta);

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

            $this->marcarParaLira($pergunta);

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

        if ($pergunta->precisa_humano) {
            $this->avisar($pergunta);

            return;
        }

        $this->enviarSeAutomatico($pergunta);
    }

    /** Manuela responde sozinha: só se ligado e se ninguém assumiu enquanto ela escrevia. */
    private function enviarSeAutomatico(MercadoLivrePergunta $pergunta): void
    {
        if (! MercadoLivrePergunta::manuelaRespondeSozinha()) {
            return;
        }

        $pergunta->refresh();

        if ($pergunta->humano_assumiu_em !== null || ! $pergunta->aberta() || blank($pergunta->resposta_rascunho)) {
            return;
        }

        try {
            app(RespostaPerguntaService::class)->enviar($pergunta, (string) $pergunta->resposta_rascunho, MercadoLivrePergunta::POR_MANUELA);
        } catch (Throwable) {
            // Envio recusado: fica para o atendente, com o erro na tela.
            $pergunta->update(['precisa_humano' => true]);
            $this->avisar($pergunta);
        }
    }

    /** Sem rascunho (ficha não responde, Gemini fora): fica marcada e o Lira é avisado. */
    private function marcarParaLira(MercadoLivrePergunta $pergunta): void
    {
        $pergunta->update(['precisa_humano' => true, 'rascunho_em' => now()]);
        $this->avisar($pergunta);
    }

    private function avisar(MercadoLivrePergunta $pergunta): void
    {
        Notification::send(
            User::query()->where('role', User::ROLE_ADMIN)->get(),
            new PerguntaMercadoLivreSemRespostaNotification($pergunta->question_id, $pergunta->item_id, (string) $pergunta->pergunta),
        );
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
