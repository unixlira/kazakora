# Kalodata (dados do TikTok Shop)

Integração com a API aberta do Kalodata: ranking e detalhe de produto, loja,
criador, vídeo, live e categoria do TikTok Shop, em 15 mercados (BR incluso).
A conta é paga por **crédito** e o saldo é pequeno, então o código foi feito
para gastar o mínimo.

## Configuração (`.env`)

| Variável | Padrão | Para quê |
|---|---|---|
| `KALODATA_API_KEY` | (vazio) | Chave da open center do kalodata.com. Só no `.env`, nunca no git. |
| `KALODATA_BASE_URL` | `https://www.kalodata.com/openapi/v1` | URL base. |
| `KALODATA_REGIAO` | `BR` | Região padrão das consultas. |
| `KALODATA_CACHE_HORAS` | `24` | Validade do cache das respostas. `0` desliga o cache. |
| `KALODATA_TETO_DIARIO` | `20` | Máximo de chamadas reais por dia (fuso de São Paulo). `0` bloqueia tudo. |
| `KALODATA_TIMEOUT` | `30` | Timeout da requisição em segundos. |
| `KALODATA_TENTATIVAS` | `3` | Tentativas quando o Kalodata responde code 2000. |
| `KALODATA_RADAR_PAGINAS` | `10` | Páginas de 100 produtos que o Radar de Oportunidades busca por dia (1 chamada cada). |

## Paginação do `/rank`

Conferido em 2026-10-12 (BR, `last7Day`, produtos): `page_size` vai até
100 e a API devolveu 100 itens nas páginas 1, 2, 5 e 6. A resposta é uma
lista pura, sem total. Para "pegar tudo" é preciso ir página a página até
uma vir incompleta; o freio real é o teto diário de chamadas e o crédito.
O Radar (`MaisVendidosTikTok`) faz isso até `KALODATA_RADAR_PAGINAS`.

Lidas em `config/services.php`, chave `kalodata`.

## Como funciona

`App\Services\Kalodata\KalodataService`:

- Autenticação pelo cabeçalho `secret-key`; corpo JSON
  (`Content-Type: application/json;charset=UTF-8`).
- Resposta sempre no envelope `{success, data, message, code}`. O serviço
  devolve o `data`. Erros viram `KalodataException` com `$tipo`:
  - `auth`: HTTP 401/403 ou code `501` ("The key is not allowed");
  - `limite`: code `2016` por plano, quota ou crédito;
  - `parametro`: code `2016` com mensagem de parâmetro, ou validação local
    (região, módulo, período, página 1 ou mais, itens por página 5 a 100);
  - `teto`: teto diário atingido; `config`: falta a chave; `rede`: sem
    resposta ou code `2000` em todas as tentativas; `api`: o resto.
- **Repetição só no code `2000`** (timeout do lado deles). Nenhum outro erro
  é repetido, para não gastar crédito à toa.
- **Cache**: resposta de sucesso fica no cache do Laravel (`CACHE_STORE` do
  servidor) por `KALODATA_CACHE_HORAS`, com chave pelo método + endpoint +
  corpo (ordem dos campos não importa). Erro não vai para o cache. O saldo
  (`/credit/usage`) nunca usa cache.
- **Registro de uso**: cada chamada real (inclusive cada repetição) vira
  uma linha em `kalodata_chamadas` (endpoint, corpo resumido, sucesso, HTTP,
  code, mensagem, duração). A chave nunca é gravada.
- **Teto diário**: antes de cada chamada real o serviço conta as linhas de
  hoje; se chegou em `KALODATA_TETO_DIARIO`, bloqueia, grava um aviso no
  log (`Kalodata: teto diário de chamadas atingido`) e lança `teto`. O que
  já está no cache continua respondendo.

Métodos: `creditos()`, `buscarCategorias($palavra)`,
`rank($modulo, $filtros, $periodo, $pagina, $porPagina, $ordem)`,
`detalhe($modulo, $id, $filtros, $periodo)`, `criadorPorHandle($handle)`,
`imagensCriadores($ids)` e o genérico `chamar($endpoint, $corpo)`.
Módulos: `product`, `shop`, `creator`, `video`, `livestream`, `category`.
Os filtros vão com os nomes da API (`category_ids`, `shop_id`, `creator_id`,
`keyword`, `unit_price_range`, `revenue_range`, `is_affiliate`, ...).
Períodos: `last7Day`, `last30Day`, `AAAA-MM`, `AAAA-MM-DD~AAAA-MM-DD`
(detalhe aceita também `last90Day`, `last180Day`, `last365Day`).

## Comandos

```
php artisan kalodata:testar                         # saldo + busca "power bank" (até 2 chamadas)
php artisan kalodata:testar --palavra="fone"        # outra palavra
php artisan kalodata:produtos 910728                # ranking pela categoria (1 chamada)
php artisan kalodata:produtos "power bank" --regiao=BR --periodo=last30Day --limite=10
```

`kalodata:produtos` com palavra busca a categoria primeiro (1 chamada a
mais, ou nenhuma se a busca estiver no cache) e usa a primeira encontrada.

## Custo

Sem tabela oficial de custo por endpoint. Referência de terceiros: consulta
simples ~0,2 a 0,4 crédito. A conta do Lira devolve `[]` em `/credit/usage`
mesmo com as consultas funcionando, então o saldo tem que ser conferido no
painel do Kalodata. Ver as chamadas do dia:

```
php artisan tinker --execute="App\Models\KalodataChamada::latest()->take(20)->get(['endpoint','sucesso','codigo','created_at'])"
```

## Testes

`tests/Feature/Kalodata/KalodataServiceTest.php` (tudo com `Http::fake`,
nenhuma chamada real): chave recusada, sucesso, cache, teto, repetição no
2000, 2016, validação e os dois comandos.
