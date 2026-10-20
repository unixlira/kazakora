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
}
