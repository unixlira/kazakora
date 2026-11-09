<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Controllers\ShippingMethodController;
use App\Modules\Checkout\Support\TabelaFreteLocalidades;
use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use App\Services\MelhorEnvio\MelhorEnvioAuthService;
use App\Support\FreteProvedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MelhorEnvioController extends Controller
{
    public function __construct(private readonly MelhorEnvioAuthService $auth) {}

    public function redirectToAuth(): RedirectResponse
    {
        return redirect()->away($this->auth->getAuthorizationUrl());
    }

    /** Endereços sem /admin (o nginx devolve 404): o painel mora em painel.kazakora.com. */
    private const HOSTS_SEM_PAINEL = ['kazakora.com', 'www.kazakora.com'];

    public function callback(Request $request, TabelaFreteLocalidades $tabela): Response
    {
        // O Melhor Envio volta com ?error= quando recusa (ex.: link cortado
        // e escopo inválido, 09/11): mostra o motivo em vez de "code obrigatório".
        if ($request->filled('error')) {
            return $this->voltar($request, 'error', 'O Melhor Envio recusou a autorização: '.(string) $request->input('error_description', $request->input('error')).' Gere um link novo e copie inteiro.');
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $token = $this->auth->handleCallback($validated['code'], $validated['state']);
        } catch (MelhorEnvioException $exception) {
            return $this->voltar($request, 'error', $exception->getMessage());
        }

        // Veio do "Conectar" de Logística (2026-11-03): conecta o Melhor
        // Envio como provedor do frete e desconecta os Correios.
        if ($request->session()->pull('frete.conectar_melhor_envio')) {
            return $this->voltar($request, 'success', ShippingMethodController::ativar(FreteProvedor::MELHOR_ENVIO, $tabela));
        }

        return $this->voltar($request, 'success', "Conta do Melhor Envio \"{$token->account_label}\" conectada com sucesso.");
    }

    /**
     * ACHADO REAL 2026-11-09 (#3978): o link do melhorenvio:link-autorizacao
     * volta em kazakora.com, onde /admin dá 404; o token era salvo mas o Lira
     * via "404 Not Found". Fora do painel, a resposta é uma página simples.
     */
    private function voltar(Request $request, string $tipo, string $mensagem): Response
    {
        if (in_array($request->getHost(), self::HOSTS_SEM_PAINEL, true)) {
            $titulo = $tipo === 'success' ? 'Melhor Envio conectado' : 'Melhor Envio não conectado';

            return response(
                '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>'.e($titulo).'</title>'
                .'<body style="font-family:sans-serif;max-width:560px;margin:60px auto;padding:0 16px">'
                .'<h1>'.e($titulo).'</h1><p>'.e($mensagem).'</p>'
                .'<p><a href="https://painel.kazakora.com/admin/logistica">Abrir Logística no painel</a></p></body></html>',
                $tipo === 'success' ? 200 : 422,
            );
        }

        return redirect('/admin/logistica')->with($tipo, $mensagem);
    }
}
