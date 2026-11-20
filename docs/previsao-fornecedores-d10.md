# Previsão fornecedores D+10

Autorizado por Lira em 20/11/2026. Comando `php artisan financeiro:fornecedores-d10`.
Prévia **sem envio, lock ou gravação**: `php artisan financeiro:fornecedores-d10 --data=2026-11-23 --dry-run`.
Envio somente produção, data atual, dias úteis desde 23/11/2026, 06:00 America/Sao_Paulo em routes/console.php. Não altera rotina de resultado existente (atualmente 06:30, legado 23:50 não mais vigente).

## Critérios e lacunas reais
- Shopee, Mercado Livre e TikTok; status paid/shipped/completed; retornos fiscais e estornados excluídos. Devoluções/disputas do marketplace vão à pendência sem subtrair valor de venda de custo de aquisição.
- Aprovação autêntica disponível em `mercado_pago_pagamentos.data_aprovacao`, status approved, ligação order_id; primeira aprovação registrada. Nos demais casos `orders.created_at` é **referência de pedido/importação**, NÃO pagamento comprovado. Não consulta APIs de marketplace nem dispara sync.
- Base +10 dias corridos; rola fim de semana/feriado. Seleção exata do vencimento rolado; não recupera todo backlog, nem mesmo no primeiro dia. Em 23/11 entram bases de 10 a 13/11 porque 20/11 é feriado e 21–22 fim de semana.
- Não há snapshot de custo no item além de manual_cost_price (total da linha). Manual tem precedência; catálogo atual × quantidade rotulado, sem usar preço de venda. NULL e zero não comprovado são pendências; nunca zero presumido.
- **Schema ativo NÃO tem ligação produto/venda→fornecedor.** suppliers e purchase_orders existem, porém compras de reposição não provam fornecedor da venda. Não inferir por marca, última compra ou nome. Nesta primeira versão os itens reais permanecem em pendências sem fornecedor; agrupador está pronto/testado para dados explícitos, mas cadastro/mapeamento financeiro verificado continua necessário. Não afirmar entrega de lista real por fornecedor enquanto esse vínculo não existir.
- Sem ledger fornecedor→item de venda: não exclui supostamente quitados. E-mail e CSV avisam conferir planilha; estado sent_at é só envio, NÃO quitação. Transporte aceito não prova recebimento.

## Calendário determinístico, sem API em runtime
Sede conferida somente cidade/UF na tabela companies em 20/11: São Paulo/SP. Nacionais fixos: 01/01,21/04,01/05,07/09,12/10,02/11,15/11,20/11,25/12. Estadual SP 09/07; municipal São Paulo 25/01, Sexta-feira Santa e Corpus Christi, calculados por Páscoa gregoriana (`easter_days`, extensão calendar). Sábado/domingo não úteis; Carnaval e emendas/pontos facultativos não são presumidos feriados privados. Se sede mudar, atualizar calendário e testes antes de usar.
Fontes consultadas: BrasilAPI `/api/feriados/v1/2026` (resposta obtida; também inclui Carnaval/Páscoa como national, classificação NÃO copiada cegamente); ALESP Lei 9.497/1997 https://www.al.sp.gov.br/repositorio/legislacao/lei/1997/lei-9497-05.03.1997.html (texto obtido); legislação municipal https://legislacao.prefeitura.sp.gov.br/leis/lei-14485-de-19-de-julho-de-2007 (página obtida). Não depende destas URLs durante execução.

## Operação e deploy
Cache lock por data + scheduler onOneServer/withoutOverlapping. Estado privado por data em `storage/app/private/automation/fornecedores-d10/YYYY-MM-DD.json` escrito somente após Mail::send; JSON inválido falha visivelmente. Falhas não são convertidas em sucesso/silêncio pelo comando; sem Telegram rotineiro. Existe janela inevitável de crash entre transporte e estado: conferir caixa antes de retry após erro de persistência. Não existe garantia SMTP exatamente uma vez.

Git: ramo isolado; nunca publicar main inteiro em homolog pois bases divergem. Preparar ramo de homolog a partir origin/homolog e aplicar commit exato, validar CI/homolog sem disparar e-mail (environment guard); depois promover o mesmo patch para main por PR, seguindo deploy/producao/LEIA-ME.md e GitHub Actions deploy-producao.yml. Não rsync manual, não cron externo. Autorização de deploy existe, mas proteção/CI/review não devem ser burlados. Reverter via PR revert do commit, preservando estado já enviado.

Sem novas variáveis .env. Destino autorizado fixo joserobertolira@gmail.com, Mail Laravel configurado. Nenhum e-mail de rotina em 20/11. Próxima execução lógica 23/11/2026 06:00 BRT = 09:00 UTC, **somente após deploy efetivo**.
