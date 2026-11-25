<?php

namespace App\Modules\Newsletter\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Newsletter\Mail\ConfirmarInscricao;
use App\Modules\Newsletter\Models\NewsletterInscrito;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Newsletter da loja (pedido do Lira 24/11/2026). Bloco antes do rodapé
 * (BlocoNewsletter.vue) manda o e-mail por JSON, sem recarregar a página.
 *
 * Contra robô: campo escondido (honeypot) que só robô preenche, limite por IP
 * na rota (throttle) e reenvio da confirmação no máximo a cada 10 minutos.
 * Duplo opt-in: só vira "ativo" depois do clique no e-mail. A resposta é a
 * mesma para e-mail novo, pendente ou já inscrito, para não revelar quem
 * está na lista.
 */
class NewsletterController extends Controller
{
    public const SUCESSO = 'Quase lá! Enviamos um e-mail para você confirmar a inscrição.';

    public function inscrever(Request $request): JsonResponse
    {
        if (filled($request->input('website'))) {
            return response()->json(['mensagem' => self::SUCESSO]);
        }

        $dados = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:160'],
            'nome' => ['nullable', 'string', 'max:120'],
            'consentimento' => ['accepted'],
            'origem' => ['nullable', 'string', 'max:255'],
        ], [
            'email.required' => 'Digite seu e-mail.',
            'email.email' => 'Esse e-mail não parece válido.',
            'consentimento.accepted' => 'Marque a caixinha para aceitar receber nossos e-mails.',
        ]);

        $email = NewsletterInscrito::normalizarEmail($dados['email']);
        $nome = filled($dados['nome'] ?? null) ? Str::limit(strip_tags(trim($dados['nome'])), 120, '') : null;
        $inscrito = NewsletterInscrito::query()->firstWhere('email', $email);

        if ($inscrito?->ativo()) {
            return response()->json(['mensagem' => self::SUCESSO]);
        }

        if ($inscrito?->confirmacao_enviada_em?->gt(now()->subMinutes(10))) {
            return response()->json(['mensagem' => self::SUCESSO]);
        }

        $inscrito ??= new NewsletterInscrito(['email' => $email]);
        $inscrito->fill([
            'nome' => $nome ?? $inscrito->nome,
            'status' => NewsletterInscrito::PENDENTE,
            'origem' => isset($dados['origem']) ? Str::limit(strip_tags($dados['origem']), 255, '') : null,
            'consentimento_versao' => config('newsletter.consentimento_versao'),
            'consentimento_texto' => config('newsletter.consentimento_texto'),
            'consentimento_ip' => $request->ip(),
            'consentimento_navegador' => Str::limit((string) $request->userAgent(), 255, ''),
            'consentimento_em' => now(),
            'confirmacao_enviada_em' => now(),
        ])->save();

        Mail::to($inscrito->email)->queue(new ConfirmarInscricao($inscrito));

        return response()->json(['mensagem' => self::SUCESSO]);
    }

    public function confirmar(string $token): Response
    {
        $inscrito = NewsletterInscrito::query()->where('token', $token)->first();
        $inscrito?->confirmar();

        return Inertia::render('Newsletter/Resultado', [
            'situacao' => $inscrito ? 'confirmado' : 'invalido',
        ]);
    }

    /** Sair com 1 clique pelo link do e-mail (GET), sem login nem confirmação. */
    public function sair(string $token): Response
    {
        $inscrito = NewsletterInscrito::query()->where('token', $token)->first();
        $inscrito?->descadastrar();

        return Inertia::render('Newsletter/Resultado', [
            'situacao' => $inscrito ? 'descadastrado' : 'invalido',
            'token' => $inscrito?->token,
        ]);
    }

    /** RFC 8058: botão "Cancelar inscrição" do Gmail/Yahoo manda POST sem CSRF. */
    public function sairUmClique(string $token): HttpResponse
    {
        NewsletterInscrito::query()->where('token', $token)->first()?->descadastrar();

        return response('', 204);
    }
}
