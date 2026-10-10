<?php

namespace App\Modules\Checkout\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Completa no servidor o que faltou no endereço do checkout (pedido
 * 2026-10-10: "bairro é obrigatório" travando a compra). Com o CEP, busca
 * rua, bairro, cidade e UF no ViaCEP só para os campos vazios — o que o
 * cliente digitou nunca é trocado. Cidade de CEP único (sem bairro no
 * ViaCEP) fica com bairro "Centro".
 */
class CompletarEnderecoPeloCep
{
    /** @param array<string, mixed> $endereco */
    public function completar(array $endereco): array
    {
        $faltando = collect(['street', 'neighborhood', 'city', 'state'])->filter(fn ($campo) => blank($endereco[$campo] ?? null));
        $cep = preg_replace('/\D/', '', (string) ($endereco['zip'] ?? ''));

        if ($faltando->isEmpty() || strlen($cep) !== 8) {
            return $endereco;
        }

        $dados = Cache::remember("viacep.{$cep}", now()->addDays(30), function () use ($cep) {
            try {
                $resposta = Http::timeout(5)->get("https://viacep.com.br/ws/{$cep}/json/");
            } catch (\Throwable) {
                return null;
            }

            return $resposta->successful() && ! ($resposta->json('erro') ?? false) ? $resposta->json() : null;
        });

        if (! $dados) {
            return $endereco;
        }

        $doCep = [
            'street' => trim((string) ($dados['logradouro'] ?? '')),
            'neighborhood' => trim((string) ($dados['bairro'] ?? '')) ?: 'Centro',
            'city' => trim((string) ($dados['localidade'] ?? '')),
            'state' => strtoupper(trim((string) ($dados['uf'] ?? ''))),
        ];

        foreach ($faltando as $campo) {
            if ($doCep[$campo] !== '') {
                $endereco[$campo] = $doCep[$campo];
            }
        }

        return $endereco;
    }
}
