<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Modelos aprovados na WABA do número da loja, para mandar fora da janela de
 * 24h direto da conversa (pedido do Lira 11/11/2026). Lê da Graph API
 * (/{waba}/message_templates) e guarda 10 minutos.
 *
 * Cada modelo sai com o texto do corpo, as variáveis que precisam ser
 * preenchidas, o cabeçalho e os botões. Modelo que a tela não sabe montar
 * (cabeçalho de texto com variável, carrossel, catálogo...) vem com
 * "suportado" = false e o motivo.
 */
class WhatsAppModelos
{
    private const CACHE_MINUTOS = 10;

    private const MAX_PAGINAS = 5;

    /** @return list<array<string, mixed>> */
    public function aprovados(string $loja): array
    {
        $loja = WhatsAppNumeros::normalizar($loja);

        return Cache::remember("whatsapp_modelos_{$loja}", now()->addMinutes(self::CACHE_MINUTOS), fn () => $this->buscar($loja));
    }

    /** @return array<string, mixed>|null */
    public function encontrar(string $loja, string $nome, string $idioma): ?array
    {
        foreach ($this->aprovados($loja) as $modelo) {
            if ($modelo['nome'] === $nome && $modelo['idioma'] === $idioma) {
                return $modelo;
            }
        }

        return null;
    }

    public function esquecer(string $loja): void
    {
        Cache::forget('whatsapp_modelos_'.WhatsAppNumeros::normalizar($loja));
    }

    /** @return list<array<string, mixed>> */
    private function buscar(string $loja): array
    {
        $waba = WhatsAppNumeros::businessAccountId($loja);
        $token = WhatsAppNumeros::accessToken($loja);

        if ($waba === null || $token === null) {
            throw new RuntimeException('O WhatsApp da '.WhatsAppNumeros::nome($loja).' não tem a conta do WhatsApp Business (WABA) configurada no servidor.');
        }

        $url = rtrim((string) config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/')."/{$waba}/message_templates";
        $query = ['fields' => 'name,language,status,category,parameter_format,components', 'status' => 'APPROVED', 'limit' => 100];
        $modelos = [];

        for ($pagina = 0; $pagina < self::MAX_PAGINAS && $url; $pagina++) {
            $resposta = Http::withToken($token)->acceptJson()->timeout(20)->get($url, $query);

            if ($resposta->failed()) {
                throw new RuntimeException('A Meta não devolveu os modelos: '.($resposta->json('error.message') ?? $resposta->body()));
            }

            foreach ((array) $resposta->json('data', []) as $bruto) {
                if (($bruto['status'] ?? 'APPROVED') === 'APPROVED') {
                    $modelos[] = self::montar($bruto);
                }
            }

            // A próxima página já vem com a query inteira na URL.
            $url = $resposta->json('paging.next');
            $query = [];
        }

        usort($modelos, fn (array $a, array $b) => [$a['nome'], $a['idioma']] <=> [$b['nome'], $b['idioma']]);

        return $modelos;
    }

    /**
     * Modelo da Graph API no formato da tela.
     *
     * @param  array<string, mixed>  $bruto
     * @return array<string, mixed>
     */
    public static function montar(array $bruto): array
    {
        $nomeadas = strtoupper((string) ($bruto['parameter_format'] ?? 'POSITIONAL')) === 'NAMED';
        $modelo = [
            'nome' => (string) ($bruto['name'] ?? ''),
            'idioma' => (string) ($bruto['language'] ?? 'pt_BR'),
            'categoria' => (string) ($bruto['category'] ?? ''),
            'nomeadas' => $nomeadas,
            'cabecalho' => null,
            'corpo' => '',
            'variaveis' => [],
            'rodape' => null,
            'botoes' => [],
            'suportado' => true,
            'motivo' => null,
        ];

        foreach ((array) ($bruto['components'] ?? []) as $componente) {
            $tipo = strtoupper((string) ($componente['type'] ?? ''));

            if ($tipo === 'HEADER') {
                $formato = strtoupper((string) ($componente['format'] ?? 'TEXT'));
                $texto = (string) ($componente['text'] ?? '');
                $modelo['cabecalho'] = ['formato' => $formato, 'texto' => $texto ?: null];

                if ($formato === 'TEXT' && self::variaveisDe($texto) !== []) {
                    $modelo['suportado'] = false;
                    $modelo['motivo'] = 'Cabeçalho com variável: mande pela tela de Disparos.';
                } elseif (! in_array($formato, ['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'], true)) {
                    $modelo['suportado'] = false;
                    $modelo['motivo'] = 'Cabeçalho do tipo '.strtolower($formato).' não dá para mandar por aqui.';
                }
            } elseif ($tipo === 'BODY') {
                $modelo['corpo'] = (string) ($componente['text'] ?? '');
                $modelo['variaveis'] = self::variaveisDe($modelo['corpo']);
            } elseif ($tipo === 'FOOTER') {
                $modelo['rodape'] = (string) ($componente['text'] ?? '') ?: null;
            } elseif ($tipo === 'BUTTONS') {
                foreach (array_values((array) ($componente['buttons'] ?? [])) as $indice => $botao) {
                    $tipoBotao = strtoupper((string) ($botao['type'] ?? ''));
                    $url = (string) ($botao['url'] ?? '');
                    $modelo['botoes'][] = [
                        'indice' => $indice,
                        'tipo' => $tipoBotao,
                        'texto' => (string) ($botao['text'] ?? ''),
                        'url' => $url ?: null,
                        // Botão de link com {{1}} no fim: o sufixo é preenchido no envio.
                        'variavel' => $tipoBotao === 'URL' && str_contains($url, '{{'),
                    ];

                    if (! in_array($tipoBotao, ['URL', 'QUICK_REPLY', 'PHONE_NUMBER'], true)) {
                        $modelo['suportado'] = false;
                        $modelo['motivo'] = 'Botão do tipo '.strtolower($tipoBotao).' não dá para mandar por aqui.';
                    }
                }
            } elseif ($tipo !== '') {
                $modelo['suportado'] = false;
                $modelo['motivo'] = 'Modelo com '.strtolower($tipo).' não dá para mandar por aqui.';
            }
        }

        return $modelo;
    }

    /**
     * Texto que fica no histórico da conversa: corpo com as variáveis
     * preenchidas e os botões entre colchetes (igual aos avisos de pedido).
     *
     * @param  array<string, mixed>  $modelo
     * @param  array<string, string>  $valores  variável => valor
     */
    public static function texto(array $modelo, array $valores): string
    {
        $corpo = preg_replace_callback('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', fn ($m) => (string) ($valores[$m[1]] ?? $m[0]), (string) $modelo['corpo']);
        $partes = array_filter([
            ($modelo['cabecalho']['formato'] ?? null) === 'TEXT' ? $modelo['cabecalho']['texto'] : null,
            $corpo,
            $modelo['rodape'] ?? null,
        ]);
        $botoes = array_map(fn (array $botao) => '['.$botao['texto'].']', $modelo['botoes'] ?? []);

        return trim(implode("\n\n", $partes).($botoes ? "\n".implode(' ', $botoes) : ''));
    }

    /** @return list<string> "1", "2"... ou nomes, na ordem em que aparecem, sem repetir */
    private static function variaveisDe(string $texto): array
    {
        preg_match_all('/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/', $texto, $m);

        return array_values(array_unique($m[1]));
    }
}
