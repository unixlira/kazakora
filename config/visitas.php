<?php

/*
 * Contagem de visitas sem robôs e sem o dono/equipe (pedido do Lira em
 * 26/11/2026). Regras em App\Modules\Analytics\Support\VisitaIgnorada.
 */
return [
    /*
     * IPs que nunca contam como visita, separados por vírgula; aceita faixa
     * (ex.: 2804:1b3:af40:de1d::/64 ou 189.1.2.0/24). Os IPs de casa do dono
     * mudam sozinhos (a operadora troca o prefixo a cada poucos dias): a
     * regra automática "mesma rede de um navegador da equipe" já cobre;
     * aqui entra o que for fixo.
     */
    'ips_ignorados' => array_values(array_filter(array_map('trim', explode(',', (string) env('VISITAS_IPS_IGNORADOS', ''))))),

    /*
     * Nossos próprios robôs: a VPS da Hostinger (verificações automáticas,
     * auditoria do Pixel, prints).
     */
    'ips_proprios' => ['187.127.60.51', '2a02:4780:6e:1825::/64'],

    /*
     * Dias em que a rede (o /64 do IPv6 ou o IPv4 exato) de um navegador da
     * equipe continua valendo como "casa da equipe".
     */
    /*
     * IP de fora do Brasil não conta (a loja só vende no Brasil; quem abre de
     * fora são servidores no exterior). false desliga.
     */
    'so_brasil' => (bool) env('VISITAS_SO_BRASIL', true),

    'dias_casa' => (int) env('VISITAS_DIAS_CASA', 3),
];
