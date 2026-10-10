<?php

namespace App\Modules\Contato\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Contato\Mail\MensagemDoSite;
use App\Modules\Contato\Models\MensagemContato;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Fale conosco > Mandar mensagem" (pedido 2026-10-10). Proteção contra robô
 * sem captcha: campo escondido que só robô preenche, tempo mínimo entre abrir
 * a tela e enviar (vem cifrado, não dá pra forjar), limite de envios por IP
 * (na rota) e mensagem cheia de links recusada.
 */
class ContatoController extends Controller
{
    /** Menos que isso entre abrir e enviar, foi robô. */
    public const SEGUNDOS_MINIMOS = 3;

    public function index(): Response
    {
        return Inertia::render('Contato/Mensagem', [
            'formToken' => Crypt::encryptString((string) now()->timestamp),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sucesso = 'Mensagem enviada! Respondemos no seu e-mail o quanto antes.';

        // Campo escondido preenchido = robô. Finge que deu certo e não manda nada.
        if (filled($request->input('site'))) {
            return redirect()->route('contato')->with('success', $sucesso);
        }

        try {
            $abertoEm = (int) Crypt::decryptString((string) $request->input('form_token'));
        } catch (DecryptException) {
            $abertoEm = 0;
        }

        $segundos = now()->timestamp - $abertoEm;
        if ($abertoEm === 0 || $segundos > 6 * 3600) {
            throw ValidationException::withMessages(['mensagem' => 'A página ficou aberta muito tempo. Atualize e tente de novo.']);
        }
        if ($segundos < self::SEGUNDOS_MINIMOS) {
            throw ValidationException::withMessages(['mensagem' => 'Calma! Espere alguns segundos e envie de novo.']);
        }

        $dados = $request->validate([
            'nome' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'telefone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9()+\-\s]*$/'],
            'assunto' => ['required', 'string', 'min:3', 'max:120'],
            'mensagem' => ['required', 'string', 'min:10', 'max:3000'],
        ], [], [
            'nome' => 'nome',
            'email' => 'e-mail',
            'telefone' => 'telefone',
            'assunto' => 'assunto',
            'mensagem' => 'mensagem',
        ]);

        if (preg_match_all('#https?://|www\.#i', $dados['mensagem']) > 2) {
            throw ValidationException::withMessages(['mensagem' => 'Mande no máximo 2 links na mensagem.']);
        }

        $mensagem = MensagemContato::create([
            ...array_map(fn ($valor) => is_string($valor) ? strip_tags(trim($valor)) : $valor, $dados),
            'ip' => $request->ip(),
        ]);

        try {
            Mail::to(config('loja.email_contato'))->send(new MensagemDoSite($mensagem));
            $mensagem->forceFill(['enviado_em' => now()])->save();
        } catch (\Throwable $erro) {
            // A mensagem fica guardada na tabela; o cliente não precisa saber do erro.
            Log::error('Falha ao enviar mensagem do site por e-mail', ['mensagem_id' => $mensagem->id, 'erro' => $erro->getMessage()]);
        }

        return redirect()->route('contato')->with('success', $sucesso);
    }
}
