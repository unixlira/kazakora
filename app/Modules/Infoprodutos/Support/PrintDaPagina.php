<?php

namespace App\Modules\Infoprodutos\Support;

use RuntimeException;

/**
 * Print da página inteira para o fundo do mapa de cliques (2026-11-19).
 * Chrome headless pelo protocolo DevTools em PIPE (--remote-debugging-pipe,
 * fd 3 = comandos, fd 4 = respostas, JSON terminado em \0): sem Node, sem
 * websocket. Mede a página (altura e o topo de cada seção com id) e tira o
 * print em 390 px (celular) e 1280 px (computador).
 *
 * O binário vem de COMPORTAMENTO_CHROME (padrão
 * /opt/chrome-headless-shell/chrome-headless-shell). O UA "HeadlessChrome"
 * fica: a coleta e as visitas da loja descartam esse acesso como robô.
 */
class PrintDaPagina
{
    public const LARGURAS = ['celular' => 390, 'computador' => 1280];

    public const ALTURA_MAXIMA = 16000;

    /** @var resource|null */
    private $proc = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private int $id = 0;

    private string $buffer = '';

    private ?string $perfil = null;

    public static function pasta(): string
    {
        return storage_path('app/mapas');
    }

    public static function arquivo(string $produto, string $pagina, string $aparelho): string
    {
        return self::pasta().'/'.md5($produto.'|'.$pagina).'-'.$aparelho;
    }

    /** Endereço público da página (o mesmo que o visitante abre). */
    public static function url(string $produto, string $pagina, string $idioma = 'es'): ?string
    {
        if (str_starts_with($produto, 'kz/')) {
            return 'https://kazakora.com/produtos/'.substr($produto, 3);
        }
        if (str_starts_with($produto, 'fk/')) {
            return 'https://ferrakora.com/produto/'.substr($produto, 3).'/';
        }
        if ($produto === 'codigo-divino') {
            return 'https://elcodigodivinoamen.com/';
        }
        if (! array_key_exists($produto, (array) config('infoprodutos.produtos'))) {
            return null;
        }
        $prefixo = in_array($idioma, ['pt', 'en'], true) && $pagina === 'landing' ? '/'.$idioma : '';
        $sufixo = ['oferta' => 'oferta/', 'oferta-2' => 'oferta-2/'][$pagina] ?? '';

        return "https://digitalmktseller.com{$prefixo}/{$produto}/{$sufixo}";
    }

    /**
     * Tira o print e grava <arquivo>.jpg e <arquivo>.json (altura, seções,
     * hash do HTML e quando).
     *
     * @return array{altura: int, secoes: list<array{id: string, topo: int}>}
     */
    public function tirar(string $url, int $largura, string $destino): array
    {
        $this->abrir();
        try {
            $alvo = $this->comando('Target.createTarget', ['url' => 'about:blank'])['targetId'];
            $sessao = $this->comando('Target.attachToTarget', ['targetId' => $alvo, 'flatten' => true])['sessionId'];
            $celular = $largura < 768;
            $this->comando('Emulation.setDeviceMetricsOverride', ['width' => $largura, 'height' => $celular ? 844 : 900,
                'deviceScaleFactor' => 1, 'mobile' => $celular], $sessao);
            if ($celular) {
                $this->comando('Emulation.setTouchEmulationEnabled', ['enabled' => true, 'maxTouchPoints' => 5], $sessao);
            }
            $this->comando('Page.enable', [], $sessao);
            $this->comando('Page.navigate', ['url' => $url], $sessao);
            $this->esperarEvento('Page.loadEventFired', 30);
            // Desce a página devagar para carregar as imagens "lazy" e volta ao topo.
            $this->avaliar('(async()=>{for(let y=0;y<document.documentElement.scrollHeight&&y<'.self::ALTURA_MAXIMA.';y+=600){scrollTo(0,y);await new Promise(r=>setTimeout(r,120))}scrollTo(0,0);await new Promise(r=>setTimeout(r,800));return 1})()', $sessao);
            $medida = json_decode((string) $this->avaliar('JSON.stringify({h:document.documentElement.scrollHeight,s:[...document.querySelectorAll("[id]")].filter(e=>/^[A-Za-z0-9_-]{1,40}$/.test(e.id)&&e.offsetHeight>80).slice(0,60).map(e=>({id:e.id,topo:Math.round(e.getBoundingClientRect().top+scrollY)}))})', $sessao), true) ?: ['h' => 0, 's' => []];
            $altura = (int) min(max((int) $medida['h'], 1), self::ALTURA_MAXIMA);

            $imagem = $this->comando('Page.captureScreenshot', ['format' => 'webp', 'quality' => 70, 'captureBeyondViewport' => true,
                'clip' => ['x' => 0, 'y' => 0, 'width' => $largura, 'height' => $altura, 'scale' => 1]], $sessao, 90)['data'] ?? null;
            if (! is_string($imagem)) {
                throw new RuntimeException('O Chrome não devolveu o print.');
            }

            if (! is_dir(dirname($destino))) {
                mkdir(dirname($destino), 0775, true);
            }
            file_put_contents($destino.'.webp', base64_decode($imagem));
            $info = ['altura' => $altura, 'largura' => $largura, 'secoes' => array_values($medida['s'] ?? []), 'url' => $url, 'em' => now()->toIso8601String()];
            file_put_contents($destino.'.json', json_encode($info));

            return $info;
        } finally {
            $this->fechar();
        }
    }

    private function abrir(): void
    {
        $chrome = (string) config('infoprodutos.chrome');
        if (! is_executable($chrome)) {
            throw new RuntimeException("Chrome headless não encontrado em {$chrome} (COMPORTAMENTO_CHROME).");
        }
        $this->perfil = sys_get_temp_dir().'/kz-print-'.bin2hex(random_bytes(6));
        $cmd = [$chrome, '--headless', '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--mute-audio',
            '--remote-debugging-pipe', '--user-data-dir='.$this->perfil, '--no-first-run', 'about:blank'];
        $this->proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
            3 => ['pipe', 'r'], 4 => ['pipe', 'w']], $this->pipes);
        if (! is_resource($this->proc)) {
            throw new RuntimeException('Não foi possível abrir o Chrome headless.');
        }
        stream_set_blocking($this->pipes[4], false);
    }

    private function fechar(): void
    {
        if (is_resource($this->proc)) {
            try {
                $this->enviar('Browser.close', []);
            } catch (\Throwable) {
            }
            foreach ($this->pipes as $p) {
                if (is_resource($p)) {
                    fclose($p);
                }
            }
            proc_terminate($this->proc);
            proc_close($this->proc);
        }
        $this->proc = null;
        if ($this->perfil && is_dir($this->perfil)) {
            exec('rm -rf '.escapeshellarg($this->perfil));
        }
    }

    private function avaliar(string $js, string $sessao): mixed
    {
        return $this->comando('Runtime.evaluate', ['expression' => $js, 'awaitPromise' => true, 'returnByValue' => true], $sessao, 60)['result']['value'] ?? null;
    }

    private function enviar(string $metodo, array $params, ?string $sessao = null): int
    {
        $id = ++$this->id;
        $msg = ['id' => $id, 'method' => $metodo, 'params' => (object) $params];
        if ($sessao !== null) {
            $msg['sessionId'] = $sessao;
        }
        fwrite($this->pipes[3], json_encode($msg)."\0");
        fflush($this->pipes[3]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function comando(string $metodo, array $params, ?string $sessao = null, int $segundos = 30): array
    {
        $id = $this->enviar($metodo, $params, $sessao);
        $msg = $this->esperar(fn ($m) => ($m['id'] ?? null) === $id, $segundos, $metodo);
        if (isset($msg['error'])) {
            throw new RuntimeException("Chrome: {$metodo} falhou: ".($msg['error']['message'] ?? '?'));
        }

        return $msg['result'] ?? [];
    }

    private function esperarEvento(string $evento, int $segundos): void
    {
        $this->esperar(fn ($m) => ($m['method'] ?? null) === $evento, $segundos, $evento);
    }

    /** @return array<string, mixed> */
    private function esperar(callable $condicao, int $segundos, string $oQue): array
    {
        $limite = microtime(true) + $segundos;
        while (microtime(true) < $limite) {
            while (($fim = strpos($this->buffer, "\0")) !== false) {
                $msg = json_decode(substr($this->buffer, 0, $fim), true);
                $this->buffer = substr($this->buffer, $fim + 1);
                if (is_array($msg) && $condicao($msg)) {
                    return $msg;
                }
            }
            $ler = [$this->pipes[4]];
            $nada = null;
            if (@stream_select($ler, $nada, $nada, 0, 200_000)) {
                $pedaco = fread($this->pipes[4], 1 << 20);
                if ($pedaco === '' || $pedaco === false) {
                    if (feof($this->pipes[4])) {
                        throw new RuntimeException('O Chrome fechou antes de responder.');
                    }
                } else {
                    $this->buffer .= $pedaco;
                }
            }
        }

        throw new RuntimeException("Chrome não respondeu a tempo ({$oQue}).");
    }
}
