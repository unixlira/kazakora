<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dados do vídeo do produto para o Google (pedido 2026-10-15: Search Console
 * avisou "Nenhum URL de miniatura enviado"). A página de produto (VideoObject
 * no HTML inicial) e o sitemap.xml (extensão de vídeo) usam o mesmo cálculo.
 * Miniatura = primeira foto do produto; sem foto não manda vídeo nenhum,
 * porque vídeo sem miniatura o Google não indexa.
 */
class VideoParaGoogle
{
    /**
     * @return array{nome: string, descricao: string, miniatura: string, conteudo: string, enviado_em: string, duracao: ?int}|null
     */
    public static function dados(?string $videoPath, ?int $duracao, string $nome, ?string $descricao, ?string $miniatura, mixed $criadoEm = null): ?array
    {
        if (blank($videoPath) || blank($miniatura)) {
            return null;
        }

        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $descricao))));

        return [
            'nome' => 'Vídeo: '.$nome,
            'descricao' => Str::limit($texto !== '' ? $texto : $nome, 2000),
            'miniatura' => $miniatura,
            'conteudo' => asset('storage/'.ltrim($videoPath, '/')),
            'enviado_em' => self::enviadoEm($videoPath, $criadoEm),
            'duracao' => $duracao > 0 ? $duracao : null,
        ];
    }

    /** VideoObject do schema.org, pronto para json_encode. */
    public static function schema(array $video): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $video['nome'],
            'description' => $video['descricao'],
            'thumbnailUrl' => [$video['miniatura']],
            'contentUrl' => $video['conteudo'],
            'uploadDate' => $video['enviado_em'],
            'duration' => $video['duracao'] ? 'PT'.$video['duracao'].'S' : null,
        ]);
    }

    /** Data do arquivo do vídeo; se não der para ler, a criação do produto. */
    private static function enviadoEm(string $videoPath, mixed $criadoEm): string
    {
        $timestamp = rescue(fn () => Storage::disk('public')->lastModified($videoPath), null, false);
        $data = $timestamp ? Carbon::createFromTimestamp($timestamp) : rescue(fn () => Carbon::parse($criadoEm), null, false);

        return ($data ?? now())->timezone('America/Sao_Paulo')->toAtomString();
    }
}
