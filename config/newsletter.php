<?php

/*
| Newsletter da loja (pedido do Lira 24/11/2026, aula de e-commerce): bloco de
| inscrição antes do rodapé e o resumo do blog por e-mail.
|
| COMO LIGAR O E-MAIL AUTOMÁTICO DO BLOG (vem desligado):
|   1. No .env do servidor: NEWSLETTER_BLOG_AGENDADO=true
|      (opcional) NEWSLETTER_BLOG_FREQUENCIA=diario|semanal e NEWSLETTER_BLOG_HORA=10:00
|   2. php artisan config:cache (o deploy já faz).
|   3. Conferir com: php artisan schedule:list | grep newsletter
| Antes, rode à mão sem enviar: php artisan newsletter:blog --dry-run
*/

return [
    // Liga o agendamento do resumo do blog no scheduler (routes/console.php).
    'blog_agendado' => (bool) env('NEWSLETTER_BLOG_AGENDADO', false),

    // diario: matérias publicadas hoje; semanal: dos últimos 7 dias (sai na sexta).
    'blog_frequencia' => env('NEWSLETTER_BLOG_FREQUENCIA', 'diario'),

    // Hora do envio (horário de Brasília). O blog publica às 7h.
    'blog_hora' => env('NEWSLETTER_BLOG_HORA', '10:00'),

    // Máximo de matérias no resumo.
    'blog_max_materias' => (int) env('NEWSLETTER_BLOG_MAX_MATERIAS', 6),

    // Envio em lotes para não estourar o limite do provedor de e-mail:
    // cada lote é um job na fila, com este intervalo entre um e outro.
    'lote_tamanho' => (int) env('NEWSLETTER_LOTE_TAMANHO', 50),
    'segundos_entre_lotes' => (int) env('NEWSLETTER_SEGUNDOS_ENTRE_LOTES', 60),

    // Remetente do e-mail da newsletter. Vazio = MAIL_FROM_ADDRESS / MAIL_FROM_NAME.
    'remetente_email' => env('NEWSLETTER_REMETENTE_EMAIL'),
    'remetente_nome' => env('NEWSLETTER_REMETENTE_NOME', 'KazaKora'),

    // Inscrição não confirmada é apagada depois de tantos dias (LGPD: só o necessário).
    'dias_para_apagar_pendente' => 30,

    // Texto do consentimento mostrado no bloco. Mudou o texto? Troque a versão.
    'consentimento_versao' => '2026-11-24',
    'consentimento_texto' => 'Quero receber e-mails da KazaKora com novidades, ofertas e as matérias do blog. Posso sair quando quiser, com 1 clique.',
];
