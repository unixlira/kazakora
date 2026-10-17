<?php

namespace App\Modules\Marketplace\Mail;

use Illuminate\Mail\Mailable;

/** Resumo da Oferta Relâmpago diária da Shopee (shopee:relampago-diaria, 2026-10-17). */
class RelampagoShopeeResumo extends Mailable
{
    /** @param list<array> $resultados um por dia programado */
    public function __construct(public readonly array $resultados)
    {
    }

    public function build(): self
    {
        $problema = collect($this->resultados)->contains(fn ($r) => $r['erro'] || $r['recusados']);
        $ativos = collect($this->resultados)->map(fn ($r) => (int) $r['ativos_na_shopee'])->implode(' / ');

        return $this
            ->subject(($problema ? '⚠️' : '⚡').' Relâmpago Shopee: '.$ativos.' produto(s) ativos'.($problema ? ' — conferir recusas' : ''))
            ->view('emails.relampago-shopee');
    }
}
