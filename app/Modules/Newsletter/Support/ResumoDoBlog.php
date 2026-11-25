<?php

namespace App\Modules\Newsletter\Support;

use App\Modules\Editorial\Models\EditorialPost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Monta o resumo do blog KazaKora para a newsletter (pedido do Lira
 * 24/11/2026). O blog publica ~3 itens por dia (cron das 7h), então sai UM
 * e-mail por período com as matérias dele, e não um por matéria.
 *
 * diario: matérias publicadas no dia; semanal: nos 7 dias que terminam no dia.
 */
class ResumoDoBlog
{
    public function __construct(
        public readonly string $frequencia,
        public readonly CarbonImmutable $inicio,
        public readonly CarbonImmutable $fim,
        /** @var Collection<int, EditorialPost> */
        public readonly Collection $posts,
    ) {
    }

    public static function do(?string $data = null, ?string $frequencia = null): self
    {
        $frequencia = $frequencia ?: (string) config('newsletter.blog_frequencia', 'diario');
        $frequencia = $frequencia === 'semanal' ? 'semanal' : 'diario';
        $dia = $data ? CarbonImmutable::parse($data, config('app.timezone')) : CarbonImmutable::now(config('app.timezone'));
        $fim = $dia->endOfDay();
        $inicio = $frequencia === 'semanal' ? $dia->subDays(6)->startOfDay() : $dia->startOfDay();
        // Nunca inclui matéria agendada para depois de agora.
        $limite = $fim->greaterThan(now()) ? now() : $fim;

        $posts = EditorialPost::query()
            ->doSite(EditorialPost::KAZAKORA)
            ->visible()
            ->whereBetween('published_at', [$inicio, $limite])
            ->orderByDesc('published_at')
            ->limit(max(1, (int) config('newsletter.blog_max_materias', 6)))
            ->get();

        return new self($frequencia, $inicio, $fim, $posts);
    }

    public function vazio(): bool
    {
        return $this->posts->isEmpty();
    }

    /** Chave única do envio: blog:diario:2026-11-24 ou blog:semanal:2026-11-28. */
    public function referencia(): string
    {
        return 'blog:'.$this->frequencia.':'.$this->fim->toDateString();
    }

    public function assunto(): string
    {
        $primeira = $this->posts->first();
        if (! $primeira) {
            return 'Novidades do blog KazaKora';
        }

        $titulo = Str::limit($primeira->title, 70, '...');
        $outras = $this->posts->count() - 1;

        if ($outras <= 0) {
            return $titulo;
        }

        return $this->frequencia === 'semanal'
            ? "Da semana no blog: {$titulo} (+{$outras})"
            : "{$titulo} e mais {$outras} ".($outras === 1 ? 'novidade' : 'novidades').' do blog';
    }

    /** @return array<int, array{titulo: string, resumo: string, imagem: ?string, link: string, categoria: string, tipo: string}> */
    public function itens(): array
    {
        return $this->posts->map(fn (EditorialPost $p) => [
            'titulo' => $p->title,
            'resumo' => Str::limit(trim((string) ($p->excerpt ?: preg_replace('/\s+/u', ' ', strip_tags((string) $p->body)))), 220, '...'),
            'imagem' => self::urlAbsoluta($p->poster_url),
            'link' => self::comUtm(url('/blog/'.$p->slug)),
            'categoria' => (string) $p->category,
            'tipo' => match ($p->type) {
                'video' => 'Vídeo',
                'recipe' => 'Receita',
                default => 'Notícia',
            },
        ])->values()->all();
    }

    public static function comUtm(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'utm_source=newsletter&utm_medium=email&utm_campaign=blog';
    }

    private static function urlAbsoluta(?string $caminho): ?string
    {
        if (! $caminho) {
            return null;
        }

        return str_starts_with($caminho, 'https://') ? $caminho : (str_starts_with($caminho, '/') ? url($caminho) : null);
    }
}
