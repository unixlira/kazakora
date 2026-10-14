# Indicadores e alertas (Gestão › Indicadores)

Tela `/admin/indicadores` e comando `indicadores:alertas [--dias=7|14|30] [--json]`.
Código: `app/Modules/Admin/Support/Indicadores/PainelDeAlertas.php` (limites nas
constantes do topo). Pedido do Lira em 2026-10-14.

Compara os últimos N dias **completos** (até ontem) com os N dias anteriores.
Hoje fica de fora para não acusar queda falsa por dia pela metade.

Nada de conta nova: margem é `ContributionMargin::porCanal()`, ADS é
`channel_ad_spends` (o mesmo do Financeiro), visitante é a regra do painel
(`SiteVisit::visitantesPorDia` + `soDeFora`), campanhas da Shopee vêm da
`EstrategiaShopeeAds`.

## Métricas e quando viram alerta

| Grupo | Métrica | Conta | Atenção | Crítico |
|---|---|---|---|---|
| Vendas | Faturamento | receita oficial do canal | queda ≥ 20% (anterior ≥ R$ 300) | queda ≥ 40% |
| Vendas | Pedidos | pedidos válidos | só mostra | |
| Vendas | Ticket médio | receita ÷ pedidos | queda ≥ 15% (≥ 10 pedidos) | |
| Lucro | Margem de contribuição | receita − custo − taxas − frete − ADS + bônus Flex | abaixo de 10% da venda | negativa |
| Operação | Cancelamento | cancelados ÷ todos os pedidos | ≥ 8% (≥ 10 pedidos) | ≥ 15% |
| Operação | Devolução/reclamação | aberturas no período ÷ pedidos | ≥ 3% | ≥ 6% |
| ADS | ROAS | venda atribuída ÷ gasto | queda ≥ 20% | abaixo do empate do canal, ou gasto sem pedido |
| ADS | ROAS de empate | receita ÷ margem antes do ADS | referência | |
| ADS | ACOS | gasto ÷ venda do ADS (= 1 ÷ ROAS) | só mostra | |
| ADS | TACOS | gasto ÷ faturamento total do canal | ≥ 12% | ≥ 20% |
| ADS | CTR | cliques ÷ impressões | Shopee < 2%, ML < 0,6% ou queda ≥ 20% (≥ 1.000 impressões) | |
| ADS | CPC | gasto ÷ cliques | alta ≥ 25% (≥ 100 cliques) | |
| ADS | CPA | gasto ÷ pedidos do ADS | alta ≥ 30% (≥ 5 pedidos) | maior que o lucro médio por pedido antes do ADS |
| ADS | Conversão do ADS | pedidos ÷ cliques | Shopee < 1,65% (≥ 100 cliques) | |
| ADS | Campanhas Shopee | Estratégia Shopee | | gasto sem venda ≥ R$ 20; campanhas com prejuízo ≥ R$ 20 |
| ADS | Sincronização | último dia em `channel_ad_spends` | parada há mais de 2 dias | |
| Site | Visitantes únicos | regra do painel (sem equipe e robô) | queda ≥ 25% (anterior ≥ 50) | |
| Site | Conversão do site | pedidos da loja ÷ visitantes | < 0,5% (≥ 200 visitantes) | |
| Outros sites | Visitantes FerraKora/BeautyKora | `site_visits.site` | queda ≥ 25% | |
| Operação | Envio atrasado | pago há mais de 48 h (loja, Shopee, ML) | 1 a 4 pedidos | 5 ou mais |
| Estoque | Ruptura | ativo, vendeu ≥ 3 em 30 dias, estoque 0 | | qualquer |
| Estoque | Cobertura | estoque ÷ venda diária de 30 dias | < 7 dias | |
| Lucro | Produto sem custo | vendido em 30 dias sem custo | qualquer | |

Mínimos de volume (pedidos, gasto de R$ 50 no ADS, cliques, impressões) existem
para não alarmar por amostra pequena.

## Limites conhecidos

- TikTok Shop e Amazon chegam pelo Bling e ficam com status "pago": por isso não
  entram em "envio atrasado".
- Cancelamento inclui desistência do comprador e falta de pagamento (o pedido não
  guarda o motivo).
- Mercado Ads hoje não traz a venda atribuída (`attributed_gmv` = 0): ROAS e ACOS
  do ML aparecem como "—"; TACOS, CTR, CPC e CPA funcionam.
- Sem dado ainda: ADS do TikTok, da Amazon e da Meta (só montagem de campanha),
  adição ao carrinho, abandono de checkout, taxa de rejeição, recompra/LTV e CAC.
