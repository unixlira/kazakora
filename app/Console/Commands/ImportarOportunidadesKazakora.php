<?php

namespace App\Console\Commands;

use App\Modules\Admin\Models\KzOportunidade;
use App\Modules\Admin\Models\KzOportunidadeAnuncio;
use App\Modules\Admin\Support\TermometroOportunidade;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Importa o ofertas.json do coletor do Homer (mesmo formato do radar de PDF,
 * ferramentas/radar-pdf-mundo, rodado com termos de produto físico) para
 * Oportunidades › KazaKora. Mídias copiadas para o disco public em
 * kz-oportunidades/<oferta>/.
 *
 * Campos a mais por oferta (todos opcionais): anunciantes_distintos,
 * alcance_ue, criativos_novos_7d. Sem criativos_novos_7d, conta os anúncios
 * que começaram até 7 dias antes da coleta. Pontuação e fase saem do
 * TermometroOportunidade (a pontuação do JSON é ignorada).
 *
 * Idempotente como o de PDF: oferta pela chave, anúncio pelo id da
 * Biblioteca; anúncio que sumiu sai (os criativos nossos ficam, com a
 * referência guardada); oferta que não veio fica inativa.
 */
class ImportarOportunidadesKazakora extends Command
{
    protected $signature = 'oportunidades-kazakora:importar {arquivo : caminho do ofertas.json} {--midias= : pasta base das mídias (padrão: a pasta do JSON)}';

    protected $description = 'Importa produtos físicos escalando na Biblioteca de Anúncios (Oportunidades › KazaKora).';

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
            $pastaDestino = 'kz-oportunidades/'.Str::limit(Str::slug($chave), 120, '');

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

            $limite7d = $coletadoEm->copy()->subDays(7)->startOfDay();
            $novos7d = isset($o['criativos_novos_7d'])
                ? (int) $o['criativos_novos_7d']
                : collect($o['anuncios'] ?? [])->filter(fn ($a) => ! empty($a['iniciado_em']) && Carbon::parse($a['iniciado_em'])->gte($limite7d))->count();
            $alcance = isset($o['alcance_ue']) && is_numeric($o['alcance_ue']) ? (int) $o['alcance_ue'] : null;
            $dias = (int) ($o['dias_rodando'] ?? 0);
            $variacoes = (int) ($o['variacoes'] ?? 0);
            $anunciantes = max(1, (int) ($o['anunciantes_distintos'] ?? 1));
            $termometro = TermometroOportunidade::medir($dias, $variacoes, $alcance, $novos7d, $anunciantes);

            DB::transaction(function () use ($o, $i, $chave, $anuncios, $miniatura, $coletadoEm, $novos7d, $alcance, $dias, $variacoes, $anunciantes, $termometro) {
                $oportunidade = KzOportunidade::updateOrCreate(['chave' => $chave], [
                    'posicao' => (int) ($o['posicao'] ?? $i + 1),
                    'ativa' => true,
                    'nome' => Str::limit((string) ($o['nome'] ?? $chave), 250, ''),
                    'nicho' => $o['nicho'] ?? null,
                    'idioma' => $o['idioma'] ?? null,
                    'anunciante' => isset($o['anunciante']) ? Str::limit((string) $o['anunciante'], 250, '') : null,
                    'pagina_id' => $o['pagina_id'] ?? null,
                    'url_destino' => $o['url_destino'] ?? null,
                    'dominio' => $o['dominio'] ?? null,
                    'preco' => is_numeric($o['preco'] ?? null) ? $o['preco'] : null,
                    'moeda' => $o['moeda'] ?? null,
                    'paises' => array_values($o['paises'] ?? []),
                    'anuncios_ativos' => (int) ($o['anuncios_ativos'] ?? count($anuncios)),
                    'variacoes' => $variacoes,
                    'anunciantes_distintos' => $anunciantes,
                    'alcance_ue' => $alcance,
                    'criativos_novos_7d' => $novos7d,
                    'inicio_mais_antigo' => $o['inicio_mais_antigo'] ?? null,
                    'dias_rodando' => $dias,
                    'pontuacao' => $termometro['pontuacao'],
                    'fase' => $termometro['fase'],
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
                    KzOportunidadeAnuncio::updateOrCreate(['ad_archive_id' => $id], [
                        'kz_oportunidade_id' => $oportunidade->id,
                        'texto' => $a['texto'] ?? null,
                        'titulo' => isset($a['titulo']) ? Str::limit((string) $a['titulo'], 490, '') : null,
                        'cta' => isset($a['cta']) ? Str::limit((string) $a['cta'], 110, '') : null,
                        'url_destino' => $a['url_destino'] ?? null,
                        'iniciado_em' => $a['iniciado_em'] ?? null,
                        'variacoes' => max(1, (int) ($a['variacoes'] ?? 1)),
                        'alcance_ue' => isset($a['alcance_ue']) && is_numeric($a['alcance_ue']) ? (int) $a['alcance_ue'] : null,
                        'plataformas' => array_values(Arr::wrap($a['plataformas'] ?? [])),
                        'midias' => $midias,
                    ]);
                }
                $oportunidade->anuncios()->whereNotIn('ad_archive_id', $ids)->delete();
            });
        }

        $inativadas = KzOportunidade::query()->whereNotIn('chave', $chaves)->where('ativa', true)->update(['ativa' => false, 'posicao' => null]);

        $this->info(sprintf('%d oportunidades importadas, %d mídias copiadas, %d mídias não encontradas, %d antigas inativadas.', count($chaves), $copiadas, $faltando, $inativadas));

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
