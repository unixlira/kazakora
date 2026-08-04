<?php

namespace App\Modules\Content\Services;

use App\Modules\Content\Models\DailyText;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Texto diário das Testemunhas de Jeová — não existe API pública oficial
 * (só projetos de terceiros não oficiais, ver pesquisa 2026-08-04), então
 * isso raspa a Watchtower Online Library (wol.jw.org), fonte oficial real
 * usada pelo site/apps deles. Roda a cada 12h via comando agendado
 * (App\Console\Commands\FetchDailyText) — o texto só muda 1x por dia, mas
 * rodar 2x é inofensivo (upsert por data) e cobre o caso de a primeira
 * tentativa do dia falhar por instabilidade de rede.
 *
 * Estrutura HTML confirmada ao vivo 2026-08-04 (ver classes usadas abaixo):
 * a página /pt/wol/dt/r5/lp-t/{ano}/{mes}/{dia} tem dois blocos
 * ".todayItem" — o primeiro (classe "pub-es") é o texto diário em si
 * ("Examine as Escrituras Diariamente"), o segundo (classe "pub-mwb") é a
 * apostila de reunião da semana, não relacionado — por isso o XPath abaixo
 * filtra explicitamente por "pub-es", não pega só o primeiro item.
 */
class DailyTextFetcherService
{
    private const BASE_URL = 'https://wol.jw.org/pt/wol/dt/r5/lp-t';

    /**
     * User-Agent de navegador real — wol.jw.org (como outros sites
     * institucionais já vistos neste projeto) responde de forma diferente
     * ou bloqueia o User-Agent padrão do Guzzle.
     */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    public function fetchToday(): DailyText
    {
        // Data calculada localmente (America/Sao_Paulo) e embutida direto
        // na URL, em vez de confiar no redirect de "/lp-t" pra hoje — evita
        // qualquer ambiguidade de fuso horário entre o servidor da JW e o
        // Brasil (o redirect deles pode não usar o mesmo fuso).
        $today = Carbon::now('America/Sao_Paulo');
        $url = self::BASE_URL.'/'.$today->format('Y/n/j');

        $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
            ->timeout(15)
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Falha ao buscar o texto diário: HTTP {$response->status()}");
        }

        $data = $this->parse($response->body());

        return DailyText::query()->updateOrCreate(
            ['date' => $today->toDateString()],
            [...$data, 'fetched_at' => now()],
        );
    }

    /**
     * @return array{weekday_label: string, scripture_quote: string, scripture_reference: string, commentary: string, source_doc_id: ?string}
     */
    private function parse(string $html): array
    {
        $dom = new DOMDocument();
        // @ silencia warnings de HTML5 mal-formado do ponto de vista do
        // parser HTML4 do libxml — comportamento documentado e esperado,
        // não um erro real sendo escondido.
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);

        $item = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " todayItem ") and contains(concat(" ", normalize-space(@class), " "), " pub-es ")]')->item(0);

        if (! $item) {
            throw new RuntimeException('Não encontrei o bloco do texto diário na página (estrutura da wol.jw.org pode ter mudado).');
        }

        $weekdayNode = $xpath->query('.//header/h2', $item)->item(0);
        $quoteNode = $xpath->query('.//p[contains(concat(" ", normalize-space(@class), " "), " themeScrp ")]', $item)->item(0);
        $bodyNode = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " bodyTxt ")]', $item)->item(0);

        if (! $weekdayNode || ! $quoteNode || ! $bodyNode) {
            throw new RuntimeException('Estrutura do texto diário incompleta na página (estrutura da wol.jw.org pode ter mudado).');
        }

        $referenceNode = $xpath->query('.//a', $quoteNode)->item(0);
        $reference = $referenceNode ? $this->cleanText($referenceNode->textContent) : '';

        // O texto vem sempre no formato "<citação> — <referência>." num
        // único nó — separa aqui pra scripture_quote guardar só a citação
        // (a referência já tem coluna própria, não devia vir duplicada
        // dentro dela). O formato "— referência" é constante em qualquer
        // texto diário da JW (confirmado ao vivo 2026-08-04), então corta
        // tudo a partir do travessão em vez de tentar remover a referência
        // por substring (mais frágil se o texto da referência aparecer em
        // outro lugar da citação por coincidência).
        $fullQuoteText = $this->cleanText($quoteNode->textContent);
        $dashPosition = mb_strpos($fullQuoteText, '—');
        $quoteOnly = $dashPosition !== false
            ? trim(mb_substr($fullQuoteText, 0, $dashPosition))
            : $fullQuoteText;

        $sourceDocId = null;
        if (preg_match('/docId-(\d+)/', $item->getAttribute('class'), $matches)) {
            $sourceDocId = $matches[1];
        }

        return [
            'weekday_label' => $this->cleanText($weekdayNode->textContent),
            'scripture_quote' => $quoteOnly,
            'scripture_reference' => $reference,
            'commentary' => $this->cleanText($bodyNode->textContent),
            'source_doc_id' => $sourceDocId,
        ];
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
