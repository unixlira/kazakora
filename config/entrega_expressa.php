<?php

/*
| Entrega expressa na Grande São Paulo (pedido 2026-10-09).
|
| Pedido feito até o horário de corte (horário de Brasília) e CEP dentro de
| uma das faixas abaixo: "Receba hoje até as 21h". Depois do corte: "Receba
| amanhã". Fora das faixas: prazo normal (Correios / transportadora).
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
    | Turbo de motoboy na página do produto (pedido 2026-10-24): CEP nas
    | faixas abaixo e compra em horário comercial (seg a sex no horário de
    | "semana", sábado até o fim do "sabado") = "Receba em até 3 horas" pelo
    | preço fixo. Sábado depois do fim e domingo, a página mostra também o
    | Flex "Receba de 1 a 7 dias" abaixo do Full.
    */
    'motoboy' => [
        'preco' => (float) env('ENTREGA_MOTOBOY_PRECO', 39.00),
        'mensagem' => 'Receba em até 3 horas',
        'semana' => ['08:00', '18:00'],
        'sabado' => ['08:00', '13:00'],
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
