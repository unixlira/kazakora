# E-mail "Resultado do dia" (faturamento diário)

Regra de negócio do e-mail diário de faturamento e lucro da KazaKora.
Pedido de 2026-10-10. Em 2026-10-12 o Lira pediu 23:55, depois do
processamento e das retomadas; em 2026-10-18 confirmou o envio às 23:50, por
isso as retomadas foram antecipadas para antes dele.

## Quando e como sai

- **Todo dia às 23:50 (America/Sao_Paulo)**, pelo agendador do Laravel:
  `loja:resultado-diario` (`app/Console/Commands/EnviarResultadoDiario.php`).
- Destinatários: `LOJA_EMAIL_RELATORIOS` (`config('loja.email_relatorios')`,
  vários separados por vírgula).
- Uma vez por dia: o envio fica registrado em
  `storage/app/private/automation/kazakora_margin_daily_email_state.json`
  (`sent_at` e `lucro`). Rodar de novo no mesmo dia não reenvia.
- Reenvio ou conferência à mão (não mexe no controle):
  `php artisan loja:resultado-diario --data=AAAA-MM-DD --para=email@...`.
  `--forcar` reenvia para a lista oficial e regrava o controle.
- Os números são os do momento do envio. Taxa ou estorno que chegar depois
  muda o painel, não o e-mail já mandado (ex.: 11/10 saiu R$ 266,44 e,
  recalculado no dia seguinte, deu R$ 169,10).

## O que o e-mail tem que conter

Assunto: `Resultado de DD/MM: lucro líquido R$ X`.

1. **Lucro líquido do dia**, com número de pedidos, receita e margem (%).
2. **Lucro líquido do mês** (dia 1 até hoje), com pedidos, receita e margem.
3. **Impostos do mês a pagar**: DAS do Simples Nacional pela alíquota
   (`LOJA_SIMPLES_ALIQUOTA`, padrão 4,5%) e vencimento no dia 20 do mês
   seguinte.
4. **Como fechou o dia**: receita (produtos − descontos), custo dos produtos,
   taxas dos canais, anúncios, frete pago pela loja (Correios/Flex), imposto,
   bônus do Flex (quando houver) e o lucro líquido.
5. **Aviso de taxa estimada** (`*`): quanto das taxas do dia ainda é estimativa
   (TikTok antes do extrato), até a taxa real chegar.
6. **Mês dia a dia**: para cada dia, pedidos, receita, lucro (com barra) e
   lucro acumulado.
7. Rodapé explicando que é a mesma conta do painel financeiro e que o valor
   oficial do imposto é o do DAS.

## A conta

Mesma conta oficial do painel, do KoraSync e do Financeiro:
`ContributionMargin::periodo()` (`docs/financeiro-mapa-de-custos.md`), mais o
imposto. Cálculo em `app/Modules/Financeiro/Support/ResultadoDiario.php`.

```
lucro líquido = receita − custo dos produtos − taxas reais dos canais
                − frete pago pela loja (Correios/Flex) − anúncios
                + bônus do Flex − imposto (Simples)
```

- Pedidos: `Order::vendaEfetiva()` (sem devolução, sem nota avulsa, sem
  estorno) nos status de receita, pela data de criação do pedido (dia de SP).
- Receita: TikTok = vendas líquidas do extrato; Amazon = subtotal + frete;
  demais = subtotal (site: subtotal − desconto).
- Imposto: alíquota sobre o valor da NF-e de venda autorizada; pedido ainda
  sem nota usa a receita. Só a partir de `LOJA_SIMPLES_DESDE` (2026-09-01).
- Cupom da plataforma (Shopee) não entra; só cupom da loja.

## Ordem da noite (não inverter)

O e-mail só presta se as taxas reais do dia já tiverem entrado. Por isso:

| Hora (SP) | Onde | O quê |
|---|---|---|
| 23:10 | Cora Box (DESKTOP-HPRAKVB), tarefa "Agente Financeiro - hora em hora" | Coleta de hora em hora do TikTok (`financeiro-hora.mjs`; a Amazon foi desligada lá em 17/10). |
| 23:15 | VPS, `financeiro:vigiar-coletas` | Vigia: avisa se a coleta do TikTok parou ou se a da Amazon falhou. |
| 23:30 | Cora Box, tarefa "Agente TikTok - 23h30" | Demonstrativo do TikTok Seller do dia, enviado para `POST /api/tiktok/reports/import`; a importação e a taxa real são gravadas na hora, no próprio envio. |
| 23:35 | VPS, `amazon:taxas-reais --dias=30` (de hora em hora aos 35) | Taxa real da Amazon pela SP-API (Finances). Leva poucos segundos. |
| 23:45 | VPS, `amazon:taxas-reais --dias=30` (retomada) | Roda de novo **só se** a das 23:35 falhou ou não terminou depois das 23:30. |
| 23:50 | VPS, `loja:resultado-diario` | E-mail do resultado do dia. |

Taxa do TikTok que ainda não veio no extrato fica estimada
(`orders:estimar-taxas-tiktok`, de 10 em 10 min) e aparece com `*` no e-mail.

## Pendências conhecidas

- O TikTok não tem retomada entre 23:30 e 23:50: se a coleta das 23:30 falhar,
  a próxima é a das 00:10, e o e-mail sai com a taxa estimada. Uma segunda
  tarefa também às 23:30 não adianta: o `agente.mjs` tem trava (uma execução
  por vez) e a segunda sai na hora. A retomada tem que começar depois que a
  primeira termina (normalmente 1 a 3 min; até 5 min na coleta completa),
  ex.: 23:40, e terminar antes das 23:50.
