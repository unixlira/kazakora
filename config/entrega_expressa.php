<?php

/*
| Entrega expressa na Grande São Paulo (pedido 2026-10-09).
|
| Pedido feito até o horário de corte (horário de Brasília) e CEP dentro de
| uma das faixas abaixo: "Receba hoje até as 21h". Depois do corte: "Receba
| amanhã", ou o dia ("Receba SEGUNDA") quando amanhã é domingo ou feriado.
| Fora das faixas: prazo normal (Correios / transportadora).
|
| As faixas são inclusivas e usam o CEP só com números (8 dígitos). Guarulhos,
| Santo André e Diadema são atendidos só em parte — entram aqui quando as
| faixas exatas dos bairros atendidos forem confirmadas.
*/

return [
    'ativa' => env('ENTREGA_EXPRESSA_ATIVA', true),

    'horario_corte' => '13:00',

    'horario_entrega' => '21h',

    /*
    | Dias sem entrega (pedido do Lira 2026-10-24), além do domingo (Full) e do
    | fim de semana (Correios): feriados nacionais fixos em "mm-dd" e os móveis
    | (Carnaval, Sexta-feira Santa, Corpus Christi) em "aaaa-mm-dd". Ver
    | App\Modules\Checkout\Support\DiaDeEntrega.
    */
    'feriados' => [
        '01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25',
        '2027-02-08', '2027-02-09', '2027-03-26', '2027-05-27',
        '2028-02-28', '2028-02-29', '2028-04-14', '2028-06-15',
    ],

    /*
    | Turbo de motoboy na página do produto (pedido 2026-10-24): CEP nas
    | faixas abaixo e compra em horário comercial (seg a sex no horário de
    | "semana", sábado até o fim do "sabado") = "Receba em até 3 horas" pelo
    | preço fixo, junto com o Full e sem o Flex. Fora da área ou do horário:
    | Flex "Receba de 1 a 7 dias" e Turbo dos Correios (SEDEX pelo excedente).
    |
    | "faixas_extra": CEPs da Grande SP com motoboy mas fora do Full (Mauá,
    | pedido 2026-10-24). Lá o Flex continua como opção grátis.
    */
    'motoboy' => [
        'preco' => (float) env('ENTREGA_MOTOBOY_PRECO', 39.00),
        'mensagem' => 'Receba em até 3 horas',
        'semana' => ['08:00', '18:00'],
        'sabado' => ['08:00', '13:00'],
        // Teste do fim de semana (2026-10-24) encerrado no mesmo dia: true volta a
        // mostrar o motoboy a qualquer hora.
        'ignorar_horario' => (bool) env('ENTREGA_MOTOBOY_IGNORAR_HORARIO', false),
        'faixas_extra' => [
            ['inicio' => '09300000', 'fim' => '09399999', 'local' => 'Mauá'],
        ],
    ],

    'faixas' => [
        ['inicio' => '01000000', 'fim' => '05999999', 'local' => 'São Paulo'],
        ['inicio' => '08000000', 'fim' => '08499999', 'local' => 'São Paulo'],
        ['inicio' => '06000000', 'fim' => '06299999', 'local' => 'Osasco'],
        ['inicio' => '06400000', 'fim' => '06479999', 'local' => 'Barueri'],
        ['inicio' => '06750000', 'fim' => '06799999', 'local' => 'Taboão da Serra'],
        ['inicio' => '09500000', 'fim' => '09599999', 'local' => 'São Caetano do Sul'],
    ],
];
