<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

/**
 * Radar de Oportunidades (pedido do Lira 2026-10-12): buscas em alta no
 * Google Brasil, pelo RSS público do Google Trends
 * (trends.google.com/trending/rss?geo=BR). Não precisa de chave. São as
 * buscas do dia em geral (muita notícia e esporte), por isso entram como
 * sinal de apoio: o Radar cruza com os produtos dos marketplaces.
 *
 * Cards por assunto (pedido do Lira 2026-10-12): além das gerais, o Radar
 * mostra música, personalidades, vídeos, notícias e produtos em cards
 * separados. O RSS não traz assunto, então esses grupos vêm da lista
 * "Tendências agora" do próprio Google Trends (a mesma chamada que a página
 * trends.google.com/trending faz, com as categorias do Google). As
 * categorias do Google não têm música, personalidade nem vídeo: o Gemini
 * separa cada busca; sem o Gemini, vale a categoria do Google e palavras das
 * buscas relacionadas. Um grupo que falhar não derruba as buscas gerais.
 */
class TendenciasGoogle
{
    public const URL = 'https://trends.google.com/trending/rss?geo=BR';

    public const LIMITE = 20;

    private const CACHE_HORAS = 3;

    private const NS = 'https://trends.google.com/trending/rss';

    /** Lista "Tendências agora" (últimas 24 h) com a categoria de cada busca. */
    public const URL_AGORA = 'https://trends.google.com/_/TrendsUi/data/batchexecute';

    /** Buscas de cada card por assunto. */
    public const LIMITE_GRUPO = 10;

    /** Quantas buscas (as de maior volume) vão para a separação por assunto. */
    private const CLASSIFICAR = 150;

    public const GRUPOS = ['musica', 'personalidades', 'videos', 'noticias', 'produtos'];

    /** Categorias do Google Trends (ids da página Tendências agora). */
    private const CATEGORIAS = [
        1 => 'Automóveis e veículos', 2 => 'Beleza e moda', 3 => 'Negócios e finanças', 4 => 'Entretenimento',
        5 => 'Comida e bebida', 6 => 'Jogos', 7 => 'Saúde', 8 => 'Hobbies e lazer', 9 => 'Empregos e educação',
        10 => 'Direito e governo', 11 => 'Outros', 13 => 'Animais de estimação', 14 => 'Política', 15 => 'Ciência',
        16 => 'Compras', 17 => 'Esportes', 18 => 'Tecnologia', 19 => 'Viagem e transporte', 20 => 'Clima',
    ];

    /** Sem o Gemini: categoria do Google que já diz o grupo. */
    private const GRUPO_DA_CATEGORIA = [
        16 => 'produtos', 2 => 'produtos', 18 => 'produtos', 1 => 'produtos', 13 => 'produtos', 5 => 'produtos',
        14 => 'noticias', 10 => 'noticias', 3 => 'noticias', 20 => 'noticias', 15 => 'noticias', 7 => 'noticias',
        19 => 'noticias', 9 => 'noticias',
    ];

    /** Sem o Gemini: palavras nas buscas relacionadas que indicam o grupo. */
    private const PALAVRAS = [
        'musica' => ['musica', 'música', 'letra', 'clipe', 'album', 'álbum', 'cantor', 'cantora', 'turne', 'turnê', 'spotify', 'feat', 'show'],
        'videos' => ['filme', 'serie', 'série', 'novela', 'episodio', 'episódio', 'capitulo', 'capítulo', 'temporada', 'trailer', 'netflix', 'youtube', 'video', 'vídeo', 'globoplay', 'prime video', 'disney'],
        'personalidades' => ['idade', 'quem é', 'quem e', 'namorada', 'namorado', 'esposa', 'marido', 'filhos', 'altura', 'instagram', 'morreu'],
    ];

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function analisar(bool $atualizar = false): array
    {
        $chave = 'oportunidades:google:trends:BR';
        $chaveGrupos = $chave.':grupos';
        if ($atualizar) {
            Cache::forget($chave);
            Cache::forget($chaveGrupos);
        }

        try {
            $gerais = Cache::remember($chave, now()->addHours(self::CACHE_HORAS), fn () => $this->coletar());
        } catch (Throwable $e) {
            report($e);

            $gerais = ['termos' => [], 'atualizado_em' => null,
                'erro' => 'Não foi possível consultar o Google Trends agora. Tente de novo em alguns minutos.'];
        }

        try {
            $grupos = ['grupos' => Cache::remember($chaveGrupos, now()->addHours(self::CACHE_HORAS), fn () => $this->agrupar()), 'erro_grupos' => null];
        } catch (Throwable $e) {
            report($e);

            $grupos = ['grupos' => array_fill_keys(self::GRUPOS, []),
                'erro_grupos' => 'Não foi possível separar as buscas do Google Trends por assunto agora. Tente de novo em alguns minutos.'];
        }

        return $gerais + $grupos;
    }

    /**
     * @return array<string, list<array{termo: string, trafego: ?string, noticia: ?string, link: string}>>
     */
    private function agrupar(): array
    {
        $buscas = array_slice($this->tendenciasAgora(), 0, self::CLASSIFICAR);
        $assuntos = $this->classificarComGemini($buscas) ?? $this->classificarSemGemini($buscas);

        $grupos = array_fill_keys(self::GRUPOS, []);
        foreach ($buscas as $i => $busca) {
            $grupo = $assuntos[$i] ?? null;
            if ($grupo === null || count($grupos[$grupo]) >= self::LIMITE_GRUPO) {
                continue;
            }
            $grupos[$grupo][] = [
                'termo' => $busca['termo'],
                'trafego' => $busca['volume'] ? $busca['volume'].'+' : null,
                'noticia' => $busca['relacionadas'][0] ?? null,
                'link' => 'https://www.google.com/search?q='.rawurlencode($busca['termo']),
            ];
        }

        return $grupos;
    }

    /**
     * Tendências agora no Brasil, últimas 24 h, da maior para a menor
     * procura. Resposta no formato do Google (linha de proteção, depois JSON
     * com o JSON da lista dentro).
     *
     * @return list<array{termo: string, volume: ?int, categorias: list<int>, relacionadas: list<string>}>
     */
    private function tendenciasAgora(): array
    {
        $corpo = Http::timeout(15)->asForm()->post(self::URL_AGORA, [
            'f.req' => json_encode([[['i0OFE', json_encode([null, null, 'BR', 0, 'pt-BR', 24, 1]), null, 'generic']]]),
        ])->throw()->body();

        $lista = null;
        foreach (preg_split('/\R/', $corpo) as $linha) {
            $pacote = json_decode($linha, true);
            if (is_array($pacote) && ($pacote[0][1] ?? null) === 'i0OFE' && is_string($pacote[0][2] ?? null)) {
                $lista = json_decode($pacote[0][2], true)[1] ?? null;
                break;
            }
        }
        if (! is_array($lista)) {
            throw new RuntimeException('Google Trends: lista de tendências agora inválida.');
        }

        $buscas = [];
        foreach ($lista as $item) {
            $termo = is_array($item) ? trim((string) ($item[0] ?? '')) : '';
            if ($termo === '') {
                continue;
            }
            $relacionadas = array_values(array_filter(
                array_map(fn ($r) => is_string($r) ? trim($r) : '', (array) ($item[9] ?? [])),
                fn ($r) => $r !== '' && mb_strtolower($r) !== mb_strtolower($termo),
            ));
            $buscas[] = [
                'termo' => $termo,
                'volume' => is_numeric($item[6] ?? null) ? (int) $item[6] : null,
                'categorias' => array_map('intval', (array) ($item[10] ?? [])),
                'relacionadas' => array_slice($relacionadas, 0, 12),
            ];
        }
        usort($buscas, fn ($a, $b) => ($b['volume'] ?? 0) <=> ($a['volume'] ?? 0));

        return $buscas;
    }

    /**
     * O Gemini diz o assunto de cada busca. Null quando não está configurado
     * ou falha (aí vale a separação sem ele).
     *
     * @return array<int, ?string>|null
     */
    private function classificarComGemini(array $buscas): ?array
    {
        if ($buscas === [] || ! $this->gemini->isConfigured()) {
            return null;
        }

        $linhas = [];
        foreach ($buscas as $i => $b) {
            $categorias = implode(', ', array_filter(array_map(fn ($c) => self::CATEGORIAS[$c] ?? null, $b['categorias'])));
            $linhas[] = "{$i} | {$b['termo']} | {$categorias} | ".implode('; ', array_slice($b['relacionadas'], 0, 4));
        }

        try {
            $texto = $this->gemini->generate(
                (string) config('services.gemini.chat_model'),
                [['role' => 'user', 'parts' => [['text' => implode("\n", $linhas)]]]],
                'Você separa buscas em alta no Google Brasil por assunto. Cada linha é: número | busca | categoria do Google | buscas relacionadas. '
                    .'Responda só um objeto JSON {"número": "assunto"} com todas as linhas, e o assunto é um destes: '
                    .'"musica" (cantor, banda, música, álbum, show, clipe), '
                    .'"personalidades" (pessoa famosa: artista, influenciador, atleta, apresentador, político citado pelo nome; cargo sem nome, como "ministro", é notícia), '
                    .'"videos" (filme, série, novela, programa de TV, reality, vídeo, canal, trailer, streaming), '
                    .'"noticias" (acontecimento, política, economia, clima, crime, data comemorativa), '
                    .'"produtos" (produto, marca, modelo, lançamento, loja, promoção, jogo de videogame, carro, celular, algo que se compra), '
                    .'"outro" (jogo ou placar de esporte e o que não couber). Música vem antes de personalidades quando a busca é de um cantor.',
                8192,
                GeminiUsageLog::PURPOSE_RADAR,
            );
        } catch (Throwable $e) {
            Log::warning('Radar: Gemini não separou as buscas do Google', ['erro' => mb_substr($e->getMessage(), 0, 180)]);

            return null;
        }

        $json = json_decode(trim(preg_replace('/^```(?:json)?|```$/m', '', $texto)), true);
        if (! is_array($json)) {
            Log::warning('Radar: Gemini devolveu assuntos fora do formato');

            return null;
        }

        $assuntos = [];
        foreach ($buscas as $i => $b) {
            $assunto = $json[(string) $i] ?? null;
            $assuntos[$i] = in_array($assunto, self::GRUPOS, true) ? $assunto : null;
        }

        return $assuntos;
    }

    /**
     * Sem o Gemini: palavras das buscas relacionadas (música, vídeo,
     * personalidade) e depois a categoria do Google (produtos, notícias).
     *
     * @return array<int, ?string>
     */
    private function classificarSemGemini(array $buscas): array
    {
        $assuntos = [];
        foreach ($buscas as $i => $b) {
            $assuntos[$i] = null;
            if (in_array(17, $b['categorias'], true)) {
                continue; // Esporte: placar e jogo ficam só nas gerais.
            }
            $texto = ' '.mb_strtolower($b['termo'].' '.implode(' ', $b['relacionadas'])).' ';
            foreach (self::PALAVRAS as $grupo => $palavras) {
                foreach ($palavras as $palavra) {
                    if (preg_match('/\b'.preg_quote($palavra, '/').'\b/u', $texto)) {
                        $assuntos[$i] = $grupo;
                        continue 3;
                    }
                }
            }
            foreach ($b['categorias'] as $categoria) {
                if (isset(self::GRUPO_DA_CATEGORIA[$categoria])) {
                    $assuntos[$i] = self::GRUPO_DA_CATEGORIA[$categoria];
                    break;
                }
            }
        }

        return $assuntos;
    }

    private function coletar(): array
    {
        $xml = Http::timeout(15)->get(self::URL)->throw()->body();
        $rss = @simplexml_load_string($xml);
        if (! $rss instanceof SimpleXMLElement) {
            throw new RuntimeException('Google Trends: RSS inválido.');
        }

        $termos = [];
        foreach ($rss->channel->item ?? [] as $item) {
            if (count($termos) >= self::LIMITE) {
                break;
            }
            $ht = $item->children(self::NS);
            $noticia = $ht->news_item[0] ?? null;
            $termo = trim((string) $item->title);
            if ($termo === '') {
                continue;
            }
            $termos[] = [
                'termo' => $termo,
                'trafego' => trim((string) $ht->approx_traffic) ?: null,
                'noticia' => $noticia ? trim((string) $noticia->news_item_title) ?: null : null,
                'link' => 'https://www.google.com/search?q='.rawurlencode($termo),
            ];
        }

        return ['termos' => $termos, 'atualizado_em' => now()->toIso8601String(), 'erro' => null];
    }
}
