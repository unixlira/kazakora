<?php

namespace App\Console\Commands;

use App\Modules\Admin\Models\PdfOferta;
use App\Modules\Admin\Models\PdfOfertaAnuncio;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importa o ofertas.json do coletor do Homer (ferramentas/radar-pdf-mundo)
 * para a tela Oportunidades em PDF. As mídias (caminhos relativos à pasta do
 * JSON, ou a --midias) são copiadas para o disco public em
 * pdf-oportunidades/<oferta>/, servidas por /storage.
 *
 * Idempotente: oferta pela chave, anúncio pelo id da Biblioteca. Reimportar
 * atualiza; anúncio que sumiu da oferta sai; oferta que não veio no arquivo
 * fica inativa (some da tela, mas não é apagada). Mídia já copiada com o
 * mesmo tamanho não é copiada de novo.
 */
class ImportarPdfOportunidades extends Command
{
    protected $signature = 'pdf-oportunidades:importar {arquivo : caminho do ofertas.json} {--midias= : pasta base das mídias (padrão: a pasta do JSON)}';

    protected $description = 'Importa as ofertas de PDF coletadas da Biblioteca de Anúncios da Meta (ofertas.json + mídias).';

    public function handle(): int
    {
        $arquivo = (string) $this->argument('arquivo');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $dados = json_decode((string) file_get_contents($arquivo), true);
        if (! is_array($dados) || ! is_array($dados['ofertas'] ?? null)) {
            $this->error('JSON inválido: falta a lista "ofertas".');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('midias') ?: dirname(realpath($arquivo))), '/');
        $coletadoEm = isset($dados['gerado_em']) ? Carbon::parse($dados['gerado_em']) : now();
        $disco = Storage::disk('public');
        $chaves = [];
        $copiadas = 0;
        $faltando = 0;

        foreach ($dados['ofertas'] as $i => $o) {
            $chave = (string) ($o['chave'] ?? '');
            if ($chave === '') {
                continue;
            }
            $chaves[] = $chave;
            $pastaDestino = 'pdf-oportunidades/'.Str::limit(Str::slug($chave), 120, '');

            $anuncios = [];
            foreach ($o['anuncios'] ?? [] as $a) {
                $midias = [];
                foreach ($a['midias'] ?? [] as $m) {
                    $caminho = $this->copiar($disco, $base, $m['arquivo'] ?? null, $pastaDestino, $copiadas, $faltando);
                    if ($caminho === null) {
                        continue;
                    }
                    $midias[] = [
                        'tipo' => ($m['tipo'] ?? '') === 'video' ? 'video' : 'imagem',
                        'caminho' => $caminho,
                        'poster' => $this->copiar($disco, $base, $m['poster'] ?? null, $pastaDestino, $copiadas, $faltando),
                    ];
                }
                $anuncios[] = [$a, $midias];
            }

            $miniatura = null;
            foreach ($anuncios as [, $midias]) {
                foreach ($midias as $m) {
                    $miniatura ??= $m['tipo'] === 'imagem' ? $m['caminho'] : $m['poster'];
                }
            }

            DB::transaction(function () use ($o, $i, $chave, $anuncios, $miniatura, $coletadoEm) {
                $oferta = PdfOferta::updateOrCreate(['chave' => $chave], [
                    'posicao' => (int) ($o['posicao'] ?? $i + 1),
                    'ativa' => true,
                    'nome' => Str::limit((string) ($o['nome'] ?? $chave), 250, ''),
                    'nicho' => $o['nicho'] ?? null,
                    'idioma' => $o['idioma'] ?? null,
                    'anunciante' => isset($o['anunciante']) ? Str::limit((string) $o['anunciante'], 250, '') : null,
                    'pagina_id' => $o['pagina_id'] ?? null,
                    'url_destino' => $o['url_destino'] ?? null,
                    'dominio' => $o['dominio'] ?? null,
                    'plataforma' => $o['plataforma'] ?? null,
                    'preco' => is_numeric($o['preco'] ?? null) ? $o['preco'] : null,
                    'moeda' => $o['moeda'] ?? null,
                    'paises' => array_values($o['paises'] ?? []),
                    'anuncios_ativos' => (int) ($o['anuncios_ativos'] ?? count($anuncios)),
                    'variacoes' => (int) ($o['variacoes'] ?? 0),
                    'inicio_mais_antigo' => $o['inicio_mais_antigo'] ?? null,
                    'dias_rodando' => (int) ($o['dias_rodando'] ?? 0),
                    'pontuacao' => (float) ($o['pontuacao'] ?? 0),
                    'miniatura' => $miniatura,
                    'observacao' => $o['observacao'] ?? null,
                    'coletado_em' => $coletadoEm,
                ]);

                $ids = [];
                foreach ($anuncios as [$a, $midias]) {
                    $id = (string) ($a['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    $ids[] = $id;
                    PdfOfertaAnuncio::updateOrCreate(['ad_archive_id' => $id], [
                        'pdf_oferta_id' => $oferta->id,
                        'texto' => $a['texto'] ?? null,
                        'titulo' => isset($a['titulo']) ? Str::limit((string) $a['titulo'], 490, '') : null,
                        'cta' => isset($a['cta']) ? Str::limit((string) $a['cta'], 110, '') : null,
                        'url_destino' => $a['url_destino'] ?? null,
                        'iniciado_em' => $a['iniciado_em'] ?? null,
                        'variacoes' => max(1, (int) ($a['variacoes'] ?? 1)),
                        'plataformas' => array_values(Arr::wrap($a['plataformas'] ?? [])),
                        'midias' => $midias,
                    ]);
                }
                $oferta->anuncios()->whereNotIn('ad_archive_id', $ids)->delete();
            });
        }

        $inativadas = PdfOferta::query()->whereNotIn('chave', $chaves)->where('ativa', true)->update(['ativa' => false, 'posicao' => null]);

        $this->info(sprintf('%d ofertas importadas, %d mídias copiadas, %d mídias não encontradas, %d ofertas antigas inativadas.', count($chaves), $copiadas, $faltando, $inativadas));

        return self::SUCCESS;
    }

    private function copiar($disco, string $base, ?string $relativo, string $pastaDestino, int &$copiadas, int &$faltando): ?string
    {
        if ($relativo === null || $relativo === '' || str_contains($relativo, '..')) {
            return null;
        }
        $origem = $base.'/'.ltrim($relativo, '/');
        $destino = $pastaDestino.'/'.basename($relativo);
        if (! is_file($origem)) {
            if ($disco->exists($destino)) {
                return $destino;
            }
            $faltando++;

            return null;
        }
        if (! $disco->exists($destino) || $disco->size($destino) !== filesize($origem)) {
            $stream = fopen($origem, 'rb');
            $disco->put($destino, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $copiadas++;
        }

        return $destino;
    }
}
