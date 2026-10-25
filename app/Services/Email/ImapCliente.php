<?php

namespace App\Services\Email;

use RuntimeException;

/**
 * Cliente IMAP mínimo (login, select, UID SEARCH, UID FETCH do e-mail cru),
 * sem a extensão imap do PHP, que não existe na VPS. Usado para ler a caixa
 * das mensagens de comprador da Amazon (AmazonMensagensService).
 */
class ImapCliente
{
    /** @var resource|null */
    private $socket = null;

    private int $tag = 0;

    public function conectar(string $host, int $porta = 993, bool $ssl = true, int $timeout = 20): void
    {
        $alvo = ($ssl ? 'ssl://' : 'tcp://').$host.':'.$porta;
        $socket = @stream_socket_client($alvo, $errno, $erro, $timeout);
        if (! $socket) {
            throw new RuntimeException("IMAP: não conectou em {$host}:{$porta} ({$erro}).");
        }
        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;

        $saudacao = $this->linha();
        if (! str_starts_with($saudacao, '* OK')) {
            throw new RuntimeException('IMAP: saudação inesperada do servidor.');
        }
    }

    public function login(string $usuario, string $senha): void
    {
        $this->comando('LOGIN '.$this->aspas($usuario).' '.$this->aspas($senha), 'LOGIN');
    }

    /** @return int UIDVALIDITY da pasta (muda = UIDs antigos não valem mais). */
    public function selecionar(string $pasta = 'INBOX'): int
    {
        [$linhas] = $this->comando('SELECT '.$this->aspas($pasta), 'SELECT');
        foreach ($linhas as $l) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $l, $m)) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    /** @return list<int> */
    public function buscarUids(string $criterio): array
    {
        [$linhas] = $this->comando('UID SEARCH '.$criterio, 'SEARCH');
        $uids = [];
        foreach ($linhas as $l) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', trim($l), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $n) {
                    if ($n !== '' && ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        sort($uids);

        return $uids;
    }

    /** E-mail cru (cabeçalho + corpo), sem marcar como lido. */
    public function baixar(int $uid): ?string
    {
        [, $literais] = $this->comando("UID FETCH {$uid} BODY.PEEK[]", 'FETCH');

        return $literais[0] ?? null;
    }

    public function sair(): void
    {
        if ($this->socket) {
            try {
                $this->comando('LOGOUT', 'LOGOUT');
            } catch (\Throwable) {
                // servidor pode fechar antes do OK
            }
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Envia o comando e lê até a linha marcada. Devolve as linhas e os
     * literais ({n} bytes) recebidos.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function comando(string $comando, string $nome): array
    {
        if (! $this->socket) {
            throw new RuntimeException('IMAP: sem conexão.');
        }
        $tag = 'K'.(++$this->tag);
        fwrite($this->socket, "{$tag} {$comando}\r\n");

        $linhas = [];
        $literais = [];
        while (true) {
            $linha = $this->linha();
            if (preg_match('/\{(\d+)\}$/', rtrim($linha, "\r\n"), $m)) {
                $literais[] = $this->ler((int) $m[1]);
                $linhas[] = $linha;

                continue;
            }
            if (str_starts_with($linha, $tag.' ')) {
                if (! preg_match('/^'.$tag.' OK/i', $linha)) {
                    // Nunca repete o comando no erro (LOGIN leva a senha).
                    throw new RuntimeException("IMAP: {$nome} recusado (".trim(substr($linha, strlen($tag) + 1)).').');
                }

                return [$linhas, $literais];
            }
            $linhas[] = $linha;
        }
    }

    private function linha(): string
    {
        $linha = fgets($this->socket);
        if ($linha === false) {
            $meta = stream_get_meta_data($this->socket);
            throw new RuntimeException($meta['timed_out'] ?? false ? 'IMAP: tempo esgotado.' : 'IMAP: conexão fechada.');
        }

        return $linha;
    }

    private function ler(int $bytes): string
    {
        $dados = '';
        while (strlen($dados) < $bytes) {
            $parte = fread($this->socket, $bytes - strlen($dados));
            if ($parte === false || $parte === '') {
                throw new RuntimeException('IMAP: conexão fechada no meio do e-mail.');
            }
            $dados .= $parte;
        }

        return $dados;
    }

    private function aspas(string $valor): string
    {
        return '"'.addcslashes($valor, '"\\').'"';
    }
}
