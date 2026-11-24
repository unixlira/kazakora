<?php

namespace App\Console\Commands;

use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Catalog\Mail\LivroDigitalComProblema;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Checkout\Mail\OrderConfirmation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Conferência diária dos livros de brinde da Promoção PDFs (Lira, 24/11/2026:
 * o e-mail de um pedido de teste chegou com menos livros do que a página
 * prometia). Olha os livros ativos e os ganhos em pedidos dos últimos 30
 * dias: o PDF existe no disco, não está vazio, e quais produtos ativos
 * prometem na página um livro sem PDF. Achou problema novo: e-mail para
 * ALERTA_COLETA_EMAIL e alerta no sino, uma vez até resolver (o estado fica
 * no cache, como o loja:vigiar-coletas).
 */
class ConferirLivrosDigitais extends Command
{
    protected $signature = 'livros:conferir {--seco : Só mostra o que acharia, sem mandar e-mail nem gravar estado}';

    protected $description = 'Confere se os PDFs dos livros digitais (Promoção PDFs) existem e avisa o Lira quando algum falta';

    public const CACHE_ESTADO = 'livros:conferir:avisados';

    public function handle(): int
    {
        $seco = (bool) $this->option('seco');

        $recentes = DB::table(LivroDigital::TABELA_PEDIDO)
            ->where('created_at', '>=', now()->subDays(30))
            ->distinct()
            ->pluck('livro_digital_id');

        $livros = LivroDigital::query()
            ->withCount('produtos')
            ->where(fn ($q) => $q->where('ativo', true)->orWhereIn('id', $recentes))
            ->orderBy('id')
            ->get();

        $problemas = [];
        $linhas = [];
        foreach ($livros as $livro) {
            $existe = $livro->arquivoExiste();
            $tamanho = $livro->tamanhoNoDisco();
            $notas = [];

            if (! $existe) {
                $problemas[$livro->id] = ['id' => $livro->id, 'titulo' => $livro->titulo, 'problema' => "PDF não encontrado no servidor ({$livro->arquivo})."];
            } elseif ($tamanho === 0) {
                $problemas[$livro->id] = ['id' => $livro->id, 'titulo' => $livro->titulo, 'problema' => "PDF vazio (0 bytes) no servidor ({$livro->arquivo})."];
            } else {
                if ($tamanho > OrderConfirmation::LIMITE_ANEXOS_BYTES) {
                    $notas[] = 'maior que 15 MB: vai só com o link, nunca anexado';
                }
                if ($livro->tamanho && $livro->tamanho !== $tamanho) {
                    $notas[] = 'tamanho no cadastro ('.self::mb($livro->tamanho).') diferente do disco';
                }
            }

            if ($livro->ativo && ! $livro->todos_produtos && (int) $livro->produtos_count === 0) {
                $notas[] = 'ativo mas sem produto ligado: não aparece em página nenhuma';
            }

            $linhas[] = [
                $livro->id,
                $livro->titulo,
                $livro->ativo ? 'sim' : 'não',
                $livro->todos_produtos ? 'todos' : (int) $livro->produtos_count.' produto(s)',
                $existe ? self::mb($tamanho) : 'AUSENTE',
                implode('; ', $notas),
            ];
        }

        $this->table(['id', 'título', 'ativo', 'vale para', 'PDF', 'observação'], $linhas);

        // Produtos ativos cuja página promete livro sem PDF (mesma regra e
        // cache da página, do checkout e do pedido).
        $comLivro = 0;
        Product::query()
            ->whereNull('parent_product_id')
            ->where('is_active', true)
            ->chunkById(200, function ($produtos) use (&$problemas, &$comLivro) {
                foreach ($produtos as $produto) {
                    $ids = array_column(rescue(fn () => PromocoesDoProduto::paraPagina($produto)['livrosDigitais'], [], false), 'id');
                    $comLivro += $ids === [] ? 0 : 1;
                    foreach ($ids as $id) {
                        if (isset($problemas[$id])) {
                            $problemas[$id]['produtos'][] = "{$produto->name} (#{$produto->id})";
                        }
                    }
                }
            });

        $this->line("Produtos ativos com quadro de livros na página: {$comLivro}.");

        if ($problemas === []) {
            $this->info('Todos os PDFs conferidos estão no servidor.');
        } else {
            foreach ($problemas as $p) {
                $this->error("#{$p['id']} {$p['titulo']}: {$p['problema']}".(empty($p['produtos']) ? '' : ' Prometido em '.count($p['produtos']).' produto(s) ativo(s).'));
            }
        }

        if ($seco) {
            return $problemas === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->atualizarSino($livros, $problemas);
        $this->avisarPorEmail($problemas);

        return $problemas === [] ? self::SUCCESS : self::FAILURE;
    }

    /** Abre o alerta dos livros com problema e fecha o dos que voltaram (ou foram apagados). */
    private function atualizarSino($livros, array $problemas): void
    {
        $alertas = app(AlertasAdmin::class);
        $link = rescue(fn () => route('admin.entregaveis.livros.listar'), null, false);

        foreach ($problemas as $p) {
            rescue(fn () => $alertas->disparar(
                AlertasAdmin::LIVROS_DIGITAIS,
                "livro:{$p['id']}",
                "Livro digital sem PDF: {$p['titulo']}",
                $p['problema'].(empty($p['produtos']) ? '' : ' Prometido na página de '.count($p['produtos']).' produto(s) ativo(s).'),
                $link,
            ));
        }

        $abertos = AlertaAdmin::query()->abertos()->where('tipo', AlertasAdmin::LIVROS_DIGITAIS)->pluck('chave');
        foreach ($abertos as $chave) {
            $id = (int) str_replace('livro:', '', (string) $chave);
            $livro = $livros->firstWhere('id', $id) ?? LivroDigital::query()->find($id);
            if (! isset($problemas[$id]) && ($livro === null || $livro->arquivoExiste())) {
                $alertas->resolver(AlertasAdmin::LIVROS_DIGITAIS, (string) $chave);
            }
        }
    }

    /** E-mail só quando aparece problema que ainda não foi avisado. */
    private function avisarPorEmail(array $problemas): void
    {
        $chaves = array_map(fn ($p) => $p['id'].':'.$p['problema'], array_values($problemas));
        sort($chaves);
        $avisados = (array) Cache::get(self::CACHE_ESTADO, []);
        $novos = array_diff($chaves, $avisados);

        if ($novos === []) {
            // Nada novo: guarda só os que continuam (o resolvido sai e, se voltar, avisa de novo).
            Cache::forever(self::CACHE_ESTADO, array_values(array_intersect($avisados, $chaves)));

            return;
        }

        $cfg = config('services.alerta_coleta_email');
        if (! ($cfg['ativo'] ?? false) || ! ($cfg['para'] ?? null)) {
            $this->warn('E-mail de alerta desligado (ALERTA_COLETA_EMAIL_ATIVO): nada enviado.');

            return;
        }

        try {
            Mail::to($cfg['para'])->send(new LivroDigitalComProblema(
                array_values($problemas),
                'Conferência dos livros digitais (livros:conferir) em '.now()->format('d/m/Y H:i').'. Enquanto o PDF não volta, o cliente que ganha o livro recebe só o link, que não baixa.',
            ));
        } catch (Throwable $e) {
            // Sem gravar o estado: tenta de novo na próxima conferência.
            report($e);
            $this->error("E-mail falhou: {$e->getMessage()}");

            return;
        }

        Cache::forever(self::CACHE_ESTADO, $chaves);
        $this->warn("E-mail de alerta enviado para {$cfg['para']}.");
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 2, ',', '.').' MB';
    }
}
