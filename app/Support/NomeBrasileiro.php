<?php

namespace App\Support;

/**
 * Nome + sobrenome brasileiro sorteado de listas de nomes comuns no Brasil
 * (pedido 2026-10-20): substitui "Comprador de outra loja" na exibição das
 * avaliações importadas sem autor.
 *
 * O sorteio usa uma semente (o id da avaliação): a mesma avaliação mostra
 * sempre o mesmo nome, sem trocar a cada recarga da página nem divergir entre
 * cache, site e app. Sem semente, sorteia de verdade.
 */
final class NomeBrasileiro
{
    public const NOMES = [
        'Ana', 'Maria', 'Juliana', 'Fernanda', 'Patrícia', 'Aline', 'Camila', 'Amanda', 'Bruna', 'Jéssica',
        'Letícia', 'Larissa', 'Mariana', 'Gabriela', 'Beatriz', 'Vanessa', 'Carla', 'Débora', 'Renata', 'Daniela',
        'Luciana', 'Adriana', 'Cristiane', 'Simone', 'Sandra', 'Márcia', 'Tatiane', 'Priscila', 'Natália', 'Raquel',
        'Isabela', 'Paula', 'Bianca', 'Thaís', 'Kelly', 'Elaine', 'Rafaela', 'Viviane', 'Cláudia', 'Rosana',
        'José', 'João', 'Antônio', 'Francisco', 'Carlos', 'Paulo', 'Pedro', 'Lucas', 'Luiz', 'Marcos',
        'Luís', 'Gabriel', 'Rafael', 'Daniel', 'Marcelo', 'Bruno', 'Eduardo', 'Felipe', 'Rodrigo', 'Fernando',
        'Gustavo', 'Thiago', 'Leonardo', 'André', 'Fábio', 'Ricardo', 'Diego', 'Mateus', 'Vinícius', 'Leandro',
        'Alexandre', 'Sérgio', 'Roberto', 'Márcio', 'Júlio', 'Henrique', 'Guilherme', 'Caio', 'Renato', 'Igor',
    ];

    public const SOBRENOMES = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes',
        'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa',
        'Rocha', 'Dias', 'Nascimento', 'Andrade', 'Moreira', 'Nunes', 'Marques', 'Machado', 'Mendes', 'Freitas',
        'Cardoso', 'Ramos', 'Gonçalves', 'Santana', 'Teixeira', 'Araújo', 'Pinto', 'Correia', 'Cavalcanti', 'Monteiro',
        'Moura', 'Batista', 'Campos', 'Rezende', 'Farias', 'Castro', 'Duarte', 'Borges', 'Medeiros', 'Azevedo',
    ];

    public static function gerar(int|string|null $semente = null): string
    {
        if ($semente === null || $semente === '') {
            return self::NOMES[random_int(0, count(self::NOMES) - 1)].' '
                .self::SOBRENOMES[random_int(0, count(self::SOBRENOMES) - 1)];
        }

        // Dois trechos diferentes do hash: nome e sobrenome independentes.
        $hash = sha1('nome-brasileiro:'.$semente);

        return self::NOMES[hexdec(substr($hash, 0, 8)) % count(self::NOMES)].' '
            .self::SOBRENOMES[hexdec(substr($hash, 8, 8)) % count(self::SOBRENOMES)];
    }

    /** Femininos que não terminam em "a" (os 40 primeiros de NOMES já são femininos). */
    private const FEMININOS_SEM_A = [
        'aline', 'simone', 'cristiane', 'tatiane', 'raquel', 'thais', 'kelly', 'elaine', 'viviane', 'beatriz',
        'isabel', 'ester', 'ingrid', 'carmen', 'rute', 'ruth', 'lourdes', 'michele', 'michelle', 'gisele',
        'jaqueline', 'caroline', 'daniele', 'danielle', 'miriam', 'mirian', 'iris', 'liz', 'solange', 'ivone',
        'cleide', 'rosiane', 'rosilene', 'luciene', 'eliane', 'josiane', 'adriane', 'denise', 'alice', 'beth',
        'yasmin', 'isis', 'ines', 'nicole', 'evelyn', 'karen', 'sueli', 'neide', 'ivete', 'joice', 'suelen', 'rayane', 'rafaele',
    ];

    /**
     * Palpite de gênero pelo primeiro nome, para a foto do carrossel de
     * avaliações do checkout combinar com o nome (pedido 2026-10-24).
     * Feminino: está na metade feminina de NOMES, na lista acima ou termina em "a".
     */
    public static function feminino(string $nome): bool
    {
        $primeiro = mb_strtolower(trim((string) strtok(trim($nome), ' ')));
        $primeiro = strtr($primeiro, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        $femininos = array_map(
            fn (string $n) => strtr(mb_strtolower($n), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']),
            array_slice(self::NOMES, 0, 40),
        );

        return in_array($primeiro, $femininos, true)
            || in_array($primeiro, self::FEMININOS_SEM_A, true)
            || str_ends_with($primeiro, 'a');
    }
}
