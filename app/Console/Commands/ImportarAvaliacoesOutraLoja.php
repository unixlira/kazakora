<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Avaliações de outra loja (pedido do Lira 2026-10-18): compradores do MESMO
 * produto em concorrentes da Shopee/ML e no fornecedor Kicool. Grava com
 * outra_loja = true, o que liga no site o selo "Avaliação feita em outra
 * loja", tira da nota/contagem/JSON-LD da KazaKora e mostra num bloco à parte.
 *
 * Regras:
 * - Itens com origem "kazakora" são ignorados (já vêm pelo reviews:sync).
 * - NÃO filtra por nota: entra de 1 a 5 (escolher só as boas seria enganoso).
 *   Por isso o --limite pega as MAIS RECENTES, não a ordem do arquivo (que
 *   vem com as nota 5 com foto primeiro).
 * - Idempotente: updateOrCreate por (channel 'outra_loja', '<canal>:<id>').
 * - Autor preservado como recebido da origem, inclusive quando já mascarado.
 * - Loja e link do anúncio só em origem_loja (oculto do público).
 * - Fotos e vídeos MP4 baixados para o disco public (avaliacoes/outra-loja);
 *   vídeo em streaming (m3u8 do ML) é ignorado. Mídia que falha no download
 *   fica de fora (não aponta para o site da outra loja).
 *
 * Formato: {produto_kazakora: id|[ids], avaliacoes: [{origem, canal, loja,
 * anuncio, id_avaliacao, autor, anonimo, nota, texto, variacao, data
 * 'Y-m-d H:i' (America/Sao_Paulo), fotos: [url], videos: [url],
 * produto_kazakora?}]}.
 */
class ImportarAvaliacoesOutraLoja extends Command
{
    protected $signature = 'reviews:importar-outra-loja
        {arquivo : JSON com as avaliações (formato no cabeçalho da classe)}
        {--produto=* : Produto de destino. Um id: todas vão para ele. Vários: só as de produto_kazakora dentro da lista}
        {--min-texto=0 : Mínimo de caracteres no texto (0 = aceita sem texto)}
        {--limite= : Máximo de avaliações (as mais recentes, sem olhar a nota)}
        {--dry-run : Só mostra o que faria, sem gravar nem baixar nada}';

    protected $description = 'Importa avaliações do mesmo produto feitas em outras lojas, com o selo "Avaliação feita em outra loja".';

    private const MAX_BYTES = 52428800; // 50 MB, mesmo teto do cadastro manual

    private const MAX_MIDIAS = 10;

    public function handle(): int
    {
        $arquivo = (string) $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $json = json_decode((string) file_get_contents($arquivo), true);
        if (! is_array($json) || ! is_array($json['avaliacoes'] ?? null)) {
            $this->error('JSON inválido: falta a lista "avaliacoes".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $minTexto = max(0, (int) $this->option('min-texto'));
        $limite = $this->option('limite') !== null ? max(0, (int) $this->option('limite')) : null;
        $produtosOpcao = collect((array) $this->option('produto'))
            ->flatMap(fn ($v) => explode(',', (string) $v))
            ->map(fn ($v) => (int) trim($v))->filter()->unique()->values();
        $produtosTopo = collect((array) ($json['produto_kazakora'] ?? []))->map(fn ($v) => (int) $v)->filter()->values();

        $resumo = ['no_arquivo' => count($json['avaliacoes']), 'origem_kazakora' => 0, 'duplicadas_no_arquivo' => 0, 'sem_produto' => 0, 'texto_curto' => 0, 'invalidas' => 0, 'fora_do_limite' => 0];
        $vistos = [];
        $candidatas = collect();

        foreach ($json['avaliacoes'] as $item) {
            if (! is_array($item)) {
                $resumo['invalidas']++;

                continue;
            }
            if (mb_strtolower(trim((string) ($item['origem'] ?? ''))) === 'kazakora') {
                $resumo['origem_kazakora']++;

                continue;
            }

            $canal = trim((string) ($item['canal'] ?? ''));
            $id = trim((string) ($item['id_avaliacao'] ?? ''));
            $nota = (int) ($item['nota'] ?? 0);
            if ($canal === '' || $id === '' || $nota < 1 || $nota > 5) {
                $resumo['invalidas']++;

                continue;
            }

            $externalId = mb_substr("{$canal}:{$id}", 0, 255);
            if (isset($vistos[$externalId])) {
                $resumo['duplicadas_no_arquivo']++;

                continue;
            }
            $vistos[$externalId] = true;

            $produtoId = $this->destino($item, $produtosOpcao, $produtosTopo);
            if ($produtoId === null) {
                $resumo['sem_produto']++;

                continue;
            }

            $texto = trim((string) ($item['texto'] ?? ''));
            if (mb_strlen($texto) < $minTexto) {
                $resumo['texto_curto']++;

                continue;
            }

            try {
                $data = filled($item['data'] ?? null) ? Carbon::parse($item['data'], 'America/Sao_Paulo') : null;
            } catch (Throwable) {
                $data = null;
            }

            $candidatas->push([
                'external_id' => $externalId,
                'product_id' => $produtoId,
                'rating' => $nota,
                'comment' => $texto !== '' ? $texto : null,
                'reviewer_name' => filled($item['autor'] ?? null) ? $item['autor'] : Review::NOME_OUTRA_LOJA,
                'origem_loja' => mb_substr(trim(($item['loja'] ?? '').' '.($item['anuncio'] ?? '')), 0, 500) ?: null,
                'data' => $data,
                'fotos' => array_values(array_filter((array) ($item['fotos'] ?? []), 'is_string')),
                'videos' => array_values(array_filter((array) ($item['videos'] ?? []), fn ($u) => is_string($u) && $this->videoBaixavel($u))),
            ]);
        }

        // Produto de destino tem que existir.
        $existentes = Product::query()->whereIn('id', $candidatas->pluck('product_id')->unique())->pluck('id')->all();
        $candidatas = $candidatas->filter(function ($c) use ($existentes, &$resumo) {
            if (in_array($c['product_id'], $existentes, true)) {
                return true;
            }
            $resumo['sem_produto']++;

            return false;
        })->values();

        if ($limite !== null && $candidatas->count() > $limite) {
            $resumo['fora_do_limite'] = $candidatas->count() - $limite;
            $candidatas = $candidatas->sortByDesc(fn ($c) => $c['data']?->timestamp ?? 0)->take($limite)->values();
        }

        $jaExistem = Review::query()->where('channel', Review::CHANNEL_OUTRA_LOJA)
            ->whereIn('external_id', $candidatas->pluck('external_id'))->pluck('external_id')->flip();

        $this->line(sprintf(
            'No arquivo: %d | origem KazaKora (ignoradas): %d | repetidas no arquivo: %d | sem produto: %d | texto curto: %d | inválidas: %d | fora do limite: %d',
            $resumo['no_arquivo'], $resumo['origem_kazakora'], $resumo['duplicadas_no_arquivo'], $resumo['sem_produto'], $resumo['texto_curto'], $resumo['invalidas'], $resumo['fora_do_limite'],
        ));
        $this->table(['Produto', 'Avaliações', 'Novas', 'Já existiam', 'Média', '5★', '4★', '3★', '2★', '1★', 'Fotos', 'Vídeos MP4'],
            $candidatas->groupBy('product_id')->sortKeys()->map(fn (Collection $lista, $produto) => [
                $produto,
                $lista->count(),
                $lista->reject(fn ($c) => $jaExistem->has($c['external_id']))->count(),
                $lista->filter(fn ($c) => $jaExistem->has($c['external_id']))->count(),
                number_format($lista->avg('rating'), 2, ',', ''),
                ...array_map(fn ($n) => $lista->where('rating', $n)->count(), [5, 4, 3, 2, 1]),
                $lista->sum(fn ($c) => count($c['fotos'])),
                $lista->sum(fn ($c) => count($c['videos'])),
            ])->values()->all());

        if ($dryRun) {
            $this->info('Dry-run: nada foi gravado nem baixado.');

            return self::SUCCESS;
        }

        $criadas = 0;
        $atualizadas = 0;
        $midias = 0;
        $midiasFalharam = 0;

        foreach ($candidatas as $c) {
            $review = Review::query()->firstOrNew(['channel' => Review::CHANNEL_OUTRA_LOJA, 'external_id' => $c['external_id']]);
            $nova = ! $review->exists;

            $review->fill([
                'product_id' => $c['product_id'],
                'rating' => $c['rating'],
                'comment' => $c['comment'],
                'reviewer_name' => $c['reviewer_name'],
                'outra_loja' => true,
                'origem_loja' => $c['origem_loja'],
            ]);
            if ($c['data']) {
                $review->created_at = $c['data'];
            }

            DB::transaction(function () use ($review, $c, &$midias, &$midiasFalharam) {
                $review->save();

                // Mídia só na primeira vez (ou se ainda não tem nenhuma): rodar
                // de novo não baixa nem duplica.
                if ($review->images()->exists()) {
                    return;
                }

                $posicao = 0;
                $lista = array_merge(
                    array_map(fn ($u) => [$u, 'image'], $c['fotos']),
                    array_map(fn ($u) => [$u, 'video'], $c['videos']),
                );
                foreach (array_slice($lista, 0, self::MAX_MIDIAS) as [$url, $tipo]) {
                    $caminho = $this->baixar($url, $tipo);
                    if ($caminho === null) {
                        $midiasFalharam++;

                        continue;
                    }
                    $review->images()->create([
                        'image_url' => Storage::disk('public')->url($caminho),
                        'media_type' => $tipo,
                        'position' => $posicao++,
                    ]);
                    $midias++;
                }
            });

            $nova ? $criadas++ : $atualizadas++;
        }

        $this->info('Gravadas com o selo "'.Review::SELO_OUTRA_LOJA."\": {$criadas} novas, {$atualizadas} atualizadas. Mídias baixadas: {$midias}".($midiasFalharam ? " ({$midiasFalharam} falharam e ficaram de fora)" : '').'.');

        return self::SUCCESS;
    }

    /**
     * Um --produto: todas vão para ele. Vários: só as de produto_kazakora
     * (do item, ou o único do topo) dentro da lista. Sem --produto: o do
     * item, senão o do topo se houver um só.
     */
    private function destino(array $item, Collection $opcao, Collection $topo): ?int
    {
        if ($opcao->count() === 1) {
            return $opcao->first();
        }

        $doItem = isset($item['produto_kazakora']) && ! is_array($item['produto_kazakora']) ? (int) $item['produto_kazakora'] : null;
        $doItem = $doItem ?: ($topo->count() === 1 ? $topo->first() : null);

        if ($doItem === null) {
            return null;
        }

        return $opcao->isEmpty() || $opcao->contains($doItem) ? $doItem : null;
    }

    private function videoBaixavel(string $url): bool
    {
        return (bool) preg_match('/\.(mp4|webm|mov)(\?|$)/i', parse_url($url, PHP_URL_PATH) ?? '');
    }

    private function baixar(string $url, string $tipo): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $resposta = Http::timeout($tipo === 'video' ? 90 : 30)->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get($url);
        } catch (Throwable) {
            return null;
        }

        $corpo = $resposta->body();
        $mime = strtolower(trim(explode(';', (string) $resposta->header('Content-Type'))[0]));
        if (! $resposta->successful() || $corpo === '' || strlen($corpo) > self::MAX_BYTES) {
            return null;
        }

        $extensao = match (true) {
            $mime === 'image/jpeg', $mime === 'image/jpg' => 'jpg',
            $mime === 'image/png' => 'png',
            $mime === 'image/webp' => 'webp',
            $mime === 'video/mp4' => 'mp4',
            $mime === 'video/webm' => 'webm',
            $mime === 'video/quicktime' => 'mov',
            default => null,
        };
        // Alguns CDNs mandam octet-stream: decide pelo próprio arquivo.
        if ($extensao === null) {
            $extensao = match (true) {
                str_starts_with($corpo, "\xFF\xD8\xFF") => 'jpg',
                str_starts_with($corpo, "\x89PNG") => 'png',
                substr($corpo, 0, 4) === 'RIFF' && substr($corpo, 8, 4) === 'WEBP' => 'webp',
                substr($corpo, 4, 4) === 'ftyp' => 'mp4',
                default => null,
            };
        }
        if ($extensao === null || ($tipo === 'video') !== in_array($extensao, ['mp4', 'webm', 'mov'], true)) {
            return null;
        }

        $caminho = 'avaliacoes/outra-loja/'.sha1($url).'.'.$extensao;
        Storage::disk('public')->put($caminho, $corpo);

        return $caminho;
    }
}
