<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Catalog\Mail\LivroDigitalComProblema;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Livro de brinde ganho no pedido cujo PDF não está no disco (24/11/2026):
 * antes ele sumia calado do e-mail de confirmação. Agora fica no e-mail com o
 * link (que passa a baixar quando o PDF for reposto), vai para o log de erro,
 * para o sino do admin (Sistema › Alertas) e para o e-mail do Lira
 * (ALERTA_COLETA_EMAIL), no máximo uma vez por livro por dia. Nunca derruba
 * o e-mail do cliente.
 */
class AvisoLivroDigital
{
    public const CACHE_EMAIL = 'livros-digitais:aviso-arquivo:';

    public static function arquivoAusente(LivroDigital $livro, ?Order $order = null, string $onde = 'e-mail de confirmação'): void
    {
        $pedido = $order ? " (pedido {$order->numero}, #{$order->id})" : '';

        Log::error('Livro digital sem PDF no disco', [
            'livro_id' => $livro->id,
            'titulo' => $livro->titulo,
            'arquivo' => $livro->arquivo,
            'order_id' => $order?->id,
            'onde' => $onde,
        ]);

        rescue(fn () => app(AlertasAdmin::class)->disparar(
            AlertasAdmin::LIVROS_DIGITAIS,
            "livro:{$livro->id}",
            "Livro digital sem PDF: {$livro->titulo}",
            "O PDF do livro #{$livro->id} ({$livro->arquivo}) não está no servidor. Apareceu no {$onde}{$pedido}: o cliente recebe só o link, que não baixa até o PDF ser reposto.",
            rescue(fn () => route('admin.entregaveis.livros.listar'), null, false),
        ));

        $cfg = config('services.alerta_coleta_email');
        if (! ($cfg['ativo'] ?? false) || ! ($cfg['para'] ?? null)) {
            return;
        }

        $chave = self::CACHE_EMAIL.$livro->id.':'.now()->format('Y-m-d');
        if (! Cache::add($chave, true, now()->addDays(2))) {
            return; // já avisou hoje deste livro
        }

        try {
            Mail::to($cfg['para'])->send(new LivroDigitalComProblema(
                [['id' => $livro->id, 'titulo' => $livro->titulo, 'problema' => "PDF não encontrado no servidor ({$livro->arquivo})."]],
                "Achado no {$onde}{$pedido}. O cliente recebeu o livro só com o link de download.",
            ));
        } catch (\Throwable $e) {
            Cache::forget($chave); // tenta de novo no próximo pedido
            report($e);
        }
    }
}
