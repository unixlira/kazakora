<?php

namespace App\Modules\Infoprodutos\Jobs;

use App\Modules\Infoprodutos\Support\PrintDaPagina;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Print de UMA página sob demanda (tela Comportamento, 2026-11-19): quando o
 * Lira escolhe uma página que o comportamento:prints ainda não fotografou, a
 * tela pede o print e fica em "gerando a tela…" até ele chegar. Um por
 * página/aparelho de cada vez (ShouldBeUnique); sem nova tentativa: se o
 * Chrome falhar, a tela mostra o erro e o Lira pode pedir de novo.
 */
class GerarPrintDaPagina implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 100;

    public int $uniqueFor = 300;

    public function __construct(public string $produto, public string $pagina, public string $aparelho) {}

    public function uniqueId(): string
    {
        return md5($this->produto.'|'.$this->pagina.'|'.$this->aparelho);
    }

    public static function chaveDoErro(string $produto, string $pagina, string $aparelho): string
    {
        return 'comportamento:print-erro:'.md5($produto.'|'.$pagina.'|'.$aparelho);
    }

    public function handle(PrintDaPagina $print): void
    {
        $chave = self::chaveDoErro($this->produto, $this->pagina, $this->aparelho);
        Cache::forget($chave);
        try {
            $print->gerar($this->produto, $this->pagina, $this->aparelho);
        } catch (Throwable $e) {
            Cache::put($chave, mb_substr($e->getMessage(), 0, 200), now()->addMinutes(10));
            report($e);
        }
    }
}
