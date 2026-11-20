<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;

/**
 * Qual PDF de brinde combina com cada produto (pedido do Lira 20/11/2026):
 * o entregável tem que ser aderente ao produto. A primeira regra que bater no
 * nome ou na categoria decide; o admin pode editar ou apagar cada item depois.
 *
 * - brinquedo infantil: 2 histórias + 1 livro de colorir de 30 páginas;
 * - travesseiro, cinta lombar, coluna: dor nas costas;
 * - ferramentas: os guias da FerraKora (manutenção, bancada, brocas);
 * - cozinha: receitas do aparelho; o resto: guia prático do produto.
 */
class TemaDeBrinde
{
    /**
     * Regras de conteúdo infantil que vão em todo briefing de história e de
     * colorir (Lira: "sem nenhum tipo de palavra ou comportamento errado,
     * história legal e familiar").
     */
    public const REGRAS_INFANTIL = 'Público: crianças de 3 a 8 anos lendo com a família. Linguagem simples, carinhosa e correta, '
        .'sem nenhuma palavra feia, xingamento, gíria ruim, violência, susto, medo, morte, vilão assustador, mentira premiada, '
        .'desobediência sem consequência, preconceito ou marca de terceiros. Valores: família, amizade, gentileza, cuidado, '
        .'dividir, pedir desculpas, coragem tranquila, rotina (hora do banho, dormir, guardar os brinquedos). Final feliz e '
        .'aconchegante, com uma pergunta para conversar com a criança no fim. Personagens originais (nada de personagem famoso).';

    /** Regras de copy de todos os PDFs. */
    public const REGRAS_COPY = 'Capa no molde das revistas da loja (título grande, selo "GUIA/LIVRO EM PDF", 3 chamadas no pé), '
        .'elegante e atraente. Sem preço, sem promessa de cura ou de resultado garantido, sem marca de terceiros. Português do '
        .'Brasil correto. Última página: convite para voltar à loja, sem preço.';

    /**
     * @return list<array{tipo: string, titulo: string, briefing: string, paginas: int}>
     */
    public static function sugerir(Product $produto): array
    {
        $produto->loadMissing('category');
        $nome = mb_strtolower($produto->name);
        $categoria = mb_strtolower((string) $produto->category?->name);
        $tem = fn (string $padrao) => (bool) preg_match('/'.$padrao.'/u', $nome);

        $itens = match (true) {
            $tem('travesseiro|lombar|coluna|postura|cervical') => self::costas(),
            $tem('video ?game|console|joystick|controle|xbox|play & charge') => [
                self::guia('Guia do jogador: diversão na medida certa', 'Para pais e jogadores: como montar um cantinho de jogo, tempo de tela saudável, jogos para jogar em família, cuidado com o aparelho e as baterias.', 16),
            ],
            str_contains($categoria, 'brinquedo') => self::infantil($produto),
            str_contains($categoria, 'ferramenta') || $tem('parafusadeira|furadeira|soquete|alicate|trena|nível laser') => self::ferramentas(),
            $tem('airfryer|fritadeira') => [self::receitas('Receitas rápidas na airfryer', 'Receitas do dia a dia na airfryer: carnes, legumes, lanches e doces, com tempo e temperatura de cada uma.', $produto)],
            $tem('waffle|panqueca|crepe|omelete|mini bolo|pipoca|algodão doce|espremedor|frigideira|coador') => [self::receitas('Receitas para o seu '.self::apelido($produto), 'Receitas simples e gostosas para fazer com o aparelho, com lista de ingredientes, passo a passo e dicas de limpeza.', $produto)],
            $tem('câmera|camera|baba eletronica') => [self::guia('Casa segura: guia da câmera de segurança', 'Onde instalar, como configurar no celular, alertas, visão noturna, cuidados com a privacidade da família e dos vizinhos.', 16)],
            $tem('ring light|microfone|lapela|tripé|webcam|criador de conteudo') => [self::guia('Grave vídeos bonitos com o celular', 'Iluminação, enquadramento, som limpo e roteiro curto para gravar vídeos para as redes, do zero.', 16)],
            $tem('organizador|sapateira|guarda roupa|arara|cesto|caixa organizadora|pasta sanfonada') => [self::guia('Casa organizada em 7 dias', 'Plano de 7 dias para organizar a casa por cômodo, com checklist para imprimir e hábitos para manter.', 16)],
            str_contains($categoria, 'beleza') => [self::guia('Rotina de cuidados em casa', 'Rotina simples de cuidados com '.self::apelido($produto).': passo a passo, frequência, higiene do aparelho e erros comuns.', 14)],
            str_contains($categoria, 'saúde') || str_contains($categoria, 'fitness') => [self::guia('Treino em casa para iniciantes', 'Rotina leve e segura para começar a se exercitar em casa, com aquecimento, alongamento e um plano de 4 semanas. Sem promessa de resultado.', 18)],
            default => [self::guia('Guia prático: '.self::apelido($produto), 'Como tirar o melhor do produto: primeiros passos, usos do dia a dia, cuidados, limpeza e perguntas frequentes.', 12)],
        };

        return $itens;
    }

    /** @return list<array{tipo: string, titulo: string, briefing: string, paginas: int}> */
    private static function infantil(Product $produto): array
    {
        $tema = self::apelido($produto);

        return [
            [
                'tipo' => 'historia',
                'titulo' => 'História: a primeira aventura com o '.$tema,
                'briefing' => 'História ilustrada em que uma criança e a família brincam juntas com o '.$tema.' e aprendem algo bonito (dividir, ajudar em casa, cuidar do brinquedo). '.self::REGRAS_INFANTIL,
                'paginas' => 16,
            ],
            [
                'tipo' => 'historia',
                'titulo' => 'História: um dia especial em família',
                'briefing' => 'Segunda história com os mesmos personagens, ligada ao tema do '.$tema.': um dia em família com amigos ou avós, terminando na hora de dormir. '.self::REGRAS_INFANTIL,
                'paginas' => 16,
            ],
            [
                'tipo' => 'colorir',
                'titulo' => 'Livro de colorir: '.$tema,
                'briefing' => '30 desenhos para colorir, traço preto grosso e limpo em fundo branco, um por página, com os personagens das histórias e o tema do '.$tema.'. Uma frase curta e carinhosa em cada página. '.self::REGRAS_INFANTIL,
                'paginas' => 30,
            ],
        ];
    }

    /** @return list<array{tipo: string, titulo: string, briefing: string, paginas: int}> */
    private static function costas(): array
    {
        return [
            self::guia('Dor nas costas de quem trabalha sentado', 'O guia que já existe na loja (Livros Digitais): postura, pausas e alongamentos. Reaproveitar o PDF atual.', 16),
            self::guia('Lombar travada: o que fazer nas primeiras 48 horas', 'O guia que já existe na loja: cuidados nas primeiras 48 horas e quando procurar um médico. Reaproveitar o PDF atual.', 14),
            self::guia('Acordei com dor nas costas', 'O guia que já existe na loja: travesseiro, colchão e posição para dormir. Reaproveitar o PDF atual.', 14),
        ];
    }

    /** @return list<array{tipo: string, titulo: string, briefing: string, paginas: int}> */
    private static function ferramentas(): array
    {
        return [
            self::guia('Ferramenta que dura: manutenção de ferramentas elétricas', 'O guia 01 da FerraKora (Força Total, Mão na Massa): bateria de íon-lítio, troca do carvão, checklist de 12 meses. Reaproveitar o PDF da FerraKora.', 16),
            self::guia('Organização da bancada', 'O guia 02 da FerraKora: bancada organizada, quadro de ferramentas e caixas. Reaproveitar o PDF da FerraKora.', 14),
            self::guia('Tabela de brocas e buchas', 'O guia 03 da FerraKora: qual broca e qual bucha para cada parede e peso. Reaproveitar o PDF da FerraKora.', 12),
        ];
    }

    /** @return array{tipo: string, titulo: string, briefing: string, paginas: int} */
    private static function guia(string $titulo, string $briefing, int $paginas): array
    {
        return ['tipo' => 'guia', 'titulo' => $titulo, 'briefing' => $briefing.' '.self::REGRAS_COPY, 'paginas' => $paginas];
    }

    /** @return array{tipo: string, titulo: string, briefing: string, paginas: int} */
    private static function receitas(string $titulo, string $briefing, Product $produto): array
    {
        return ['tipo' => 'receitas', 'titulo' => $titulo, 'briefing' => $briefing.' Aparelho: '.$produto->name.'. '.self::REGRAS_COPY, 'paginas' => 20];
    }

    /** Nome curto do produto para o título ("Caminhão Dinossauro T-Rex Maleta Com 2 Carrinhos" -> "caminhão dinossauro t-rex"). */
    public static function apelido(Product $produto): string
    {
        $palavras = preg_split('/\s+/u', trim(preg_replace('/[|,(].*$/u', '', $produto->name)));

        return mb_strtolower(implode(' ', array_slice($palavras, 0, 3)));
    }
}
