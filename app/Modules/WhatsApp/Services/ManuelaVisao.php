<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * A Manuela enxerga a foto do cliente (2026-10-11). Achado real: cliente
 * mandou a foto de um kit de tarraxas (R$ 90) com "ainda tem em estoque
 * esse item?" e ela respondeu com o preço da bike de que falavam antes
 * (R$ 562,85) — a foto chegava para ela só como "[cliente enviou Foto]".
 *
 * O Gemini compara a foto com o catálogo REAL (id + nome dos produtos
 * ativos) e só vale identificação com confiança alta e id existente.
 */
class ManuelaVisao
{
    public const CONFIANCA_MINIMA = 0.8;

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    /**
     * @return array{descricao: string, produto_id: ?int, nome: ?string, confianca: float}
     */
    public function identificar(string $imagem, string $mime, string $legenda = ''): array
    {
        $vazio = ['descricao' => '', 'produto_id' => null, 'nome' => null, 'confianca' => 0.0];

        $catalogo = Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Product $p) => "{$p->id} | {$p->name}")->implode("\n");

        $pedido = "Catálogo da loja (id | nome):\n{$catalogo}\n\n"
            .'Legenda que o cliente mandou com a foto: '.(trim($legenda) !== '' ? $legenda : '(nenhuma)');

        try {
            $bruto = $this->gemini->generate(
                (string) config('services.gemini.chat_model'),
                [['role' => 'user', 'parts' => [
                    ['inline_data' => ['mime_type' => Str::before($mime, ';') ?: 'image/jpeg', 'data' => base64_encode($imagem)]],
                    ['text' => $pedido],
                ]]],
                <<<'TXT'
Você identifica o produto de uma foto mandada por um cliente no WhatsApp de uma loja.
1. Descreva em poucas palavras o que aparece na foto (ex.: "kit de machos e tarraxas em estojo azul").
2. Diga qual produto do catálogo é EXATAMENTE o da foto. Só aponte um id se tiver certeza de que é o mesmo produto (mesmo tipo de objeto, mesmo formato); produto parecido de outro tipo NÃO serve. Na dúvida, produto_id null.
Responda SOMENTE com JSON: {"descricao": "...", "produto_id": numero ou null, "confianca": 0 a 1}
TXT,
                4096,
                GeminiUsageLog::PURPOSE_REPLY,
            );
        } catch (Throwable $e) {
            Log::warning('whatsapp.visao_falhou', ['erro' => mb_substr($e->getMessage(), 0, 200)]);

            return $vazio;
        }

        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($bruto))), true);

        if (! is_array($json)) {
            return $vazio;
        }

        $confianca = (float) ($json['confianca'] ?? 0);
        $produto = isset($json['produto_id']) && $confianca >= self::CONFIANCA_MINIMA
            ? Product::query()->where('is_active', true)->find((int) $json['produto_id'])
            : null;

        return [
            'descricao' => mb_substr(trim((string) ($json['descricao'] ?? '')), 0, 200),
            'produto_id' => $produto?->id,
            'nome' => $produto?->name,
            'confianca' => $confianca,
        ];
    }
}
