<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Quem faz o frete da loja: Correios (contrato próprio) ou Melhor Envio
 * (pedido do Lira em 2026-11-03). Só um fica conectado por vez: conectar um
 * desconecta o outro, em Logística (/admin/logistica).
 *
 * O escolhido vale para TUDO (resposta do Lira, 03/11: "tudo"): o frete
 * embutido no preço do site, a tabela de frete por localidade (frete_tabela
 * guarda as duas, coluna provedor), as opções do checkout e a etiqueta dos
 * pedidos do site. Correios é o padrão.
 *
 * Só KazaKora: FerraKora e Beauty Kora (SiteAtual ferrakora/beautykora e
 * pedidos com site_origem) seguem nos Correios, como antes (mudança de loja
 * fica na loja). Admin, filas e comandos (sem site) usam o escolhido.
 */
class FreteProvedor
{
    private const SETTING_KEY = 'frete.provedor';

    public const CORREIOS = 'correios';

    public const MELHOR_ENVIO = 'melhor_envio';

    public const TODOS = [self::CORREIOS, self::MELHOR_ENVIO];

    public const NOMES = [self::CORREIOS => 'Correios', self::MELHOR_ENVIO => 'Melhor Envio'];

    /** O que está valendo nesta requisição (Correios fora da KazaKora). */
    public static function ativo(): string
    {
        $site = app(SiteAtual::class)->atual();

        return $site === null || $site === SiteAtual::KAZAKORA ? self::escolhido() : self::CORREIOS;
    }

    /** O conectado em Logística (vale para a KazaKora). */
    public static function escolhido(): string
    {
        $valor = Setting::get(self::SETTING_KEY, self::CORREIOS);

        return in_array($valor, self::TODOS, true) ? $valor : self::CORREIOS;
    }

    /** Provedor da etiqueta de um pedido do site: só o da KazaKora (site_origem nulo) troca. */
    public static function doPedido(?string $siteOrigem): string
    {
        return $siteOrigem === null || $siteOrigem === SiteAtual::KAZAKORA ? self::escolhido() : self::CORREIOS;
    }

    public static function melhorEnvio(): bool
    {
        return self::ativo() === self::MELHOR_ENVIO;
    }

    public static function correios(): bool
    {
        return self::ativo() === self::CORREIOS;
    }

    /**
     * Troca o provedor e zera o que guardava o frete do outro: o preço do
     * site muda na próxima página aberta.
     */
    public static function ativar(string $provedor): void
    {
        Setting::set(self::SETTING_KEY, $provedor);
        Cache::forever(self::VERSAO_CACHE, self::versao() + 1);
    }

    private const VERSAO_CACHE = 'frete.provedor:versao';

    /** Entra nas chaves de cache do frete: troca de provedor invalida tudo. */
    public static function versao(): int
    {
        return (int) Cache::get(self::VERSAO_CACHE, 0);
    }

    /** Prefixo das chaves de cache do frete (provedor + versão). */
    public static function chaveCache(): string
    {
        return self::ativo().':'.self::versao();
    }
}
