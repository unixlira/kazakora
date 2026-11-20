<?php

namespace App\Modules\Infoprodutos\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
    public const LARGURAS = ['celular' => 390, 'computador' => 1024];

    /** Altura de cada fatia (a tela carrega a 1ª na hora e as outras depois). */
    public const FATIA = 1400;

    /**
     * Formato dos arquivos: 2 = fatias + miniatura borrada (2026-11-20);
     * 3 = sem as faixas vazias das animações de entrada (2026-11-20). Subir o
     * número faz o comportamento:prints refazer todos os prints antigos.
     */
    public const VERSAO = 3;

    /** Teto da miniatura borrada (vai junto da página, em base64). */
    public const MINIATURA_MAX = 3000;

    public const ALTURA_MAXIMA = 16000;

    /**
     * Some com aviso de cookies/consentimento (e o fundo escuro dele) antes
     * do print (2026-11-20: o print da FerraKora saía com o modal de cookies
     * na frente e não dava para ver o comportamento). Só some com elemento
     * fixo/grudado na tela cujo id, classe ou rótulo fale de cookie,
     * consentimento ou LGPD; roda de novo a cada 250 ms porque o aviso do
     * React aparece depois do carregamento.
     */
    private const SEM_AVISO_DE_COOKIES = <<<'JS'
(()=>{const re=/cookie|consent|lgpd|gdpr|privacidad/i;
const sumir=()=>{document.querySelectorAll('body *').forEach(e=>{const cs=getComputedStyle(e);if(cs.position!=='fixed'&&cs.position!=='sticky')return;
const txt=(e.id||'')+' '+(typeof e.className==='string'?e.className:'')+' '+(e.getAttribute('aria-labelledby')||'')+' '+(e.getAttribute('aria-label')||'');
if(re.test(txt))e.style.setProperty('display','none','important')});
for(const el of [document.documentElement,document.body]){if(el&&getComputedStyle(el).overflow==='hidden')el.style.setProperty('overflow','visible','important')}};
addEventListener('DOMContentLoaded',sumir);setInterval(sumir,250);})()
JS;

    /**
     * Animação de entrada pronta antes do print (2026-11-20). As landings
     * escondem cada bloco (".rv{opacity:0}" até o IntersectionObserver pôr
     * ".in") e o print saía com faixas vazias enormes: a descida rápida
     * (600 px a cada 120 ms) não dava tempo de o observador marcar tudo, e o
     * que passava de 16.000 px nem era visitado. Aqui: animação e transição
     * com duração zero e as classes de "reveal" mais comuns já no estado
     * final. Junto vai a emulação de prefers-reduced-motion (o script da
     * landing nem esconde os blocos nesse caso).
     */
    private const SEM_ANIMACAO = <<<'JS'
(()=>{const css='*,*::before,*::after{animation-delay:0s!important;animation-duration:0s!important;animation-iteration-count:1!important;transition-duration:0s!important;transition-delay:0s!important;scroll-behavior:auto!important}'
+'.rv,.reveal,.revealed,.fade,.fade-in,.fade-up,.fadeIn,.fadeInUp,.fade-in-up,.animate,.animated,.animate__animated,.aos-init,[data-aos],.wow,.sr,.scroll-reveal,[data-reveal],[data-animate],[data-scroll],.lazyload,.lazyloading{opacity:1!important;transform:none!important;visibility:visible!important;filter:none!important;clip-path:none!important}';
const por=()=>{if(document.getElementById('kz-print-css'))return;const s=document.createElement('style');s.id='kz-print-css';s.textContent=css;(document.head||document.documentElement).appendChild(s)};
por();document.addEventListener('DOMContentLoaded',por);})()
JS;

    /**
     * Desce a página devagar (60% da tela a cada 220 ms, até ALTURA_MAXIMA)
     * para o conteúdo que monta ao rolar aparecer, tira o "lazy" das imagens e
     * espera elas carregarem (até 8 s). Depois, o que ainda estiver invisível
     * ocupando espaço na página (opacidade 0 fora de carrossel/modal) aparece,
     * e a barra fixa de baixo (Comprar) some: no print ela ficaria parada no
     * meio da página. Volta ao topo.
     */
    private const PREPARAR = <<<'JS'
(async()=>{const dorme=ms=>new Promise(r=>setTimeout(r,ms));const MAX=%d;
document.querySelectorAll('img[loading="lazy"]').forEach(i=>{i.loading='eager'});
const passo=Math.max(300,Math.round(innerHeight*0.6));
for(let y=0;y<Math.min(document.documentElement.scrollHeight,MAX);y+=passo){scrollTo(0,y);await dorme(220)}
scrollTo(0,Math.min(document.documentElement.scrollHeight,MAX));await dorme(600);
const imgs=[...document.images].filter(i=>!i.complete);
await Promise.race([Promise.all(imgs.map(i=>new Promise(r=>{i.addEventListener('load',r,{once:true});i.addEventListener('error',r,{once:true})}))),dorme(8000)]);
let n=0;document.querySelectorAll('body *').forEach(e=>{const cs=getComputedStyle(e);
if(cs.position==='absolute'||cs.position==='fixed'||cs.display==='none'||e.closest('[aria-hidden="true"]'))return;
if(e.offsetHeight<20||e.offsetWidth<20)return;
if(parseFloat(cs.opacity)<0.05||cs.visibility==='hidden'){e.style.setProperty('opacity','1','important');e.style.setProperty('visibility','visible','important');e.style.setProperty('transform','none','important');n++}});
scrollTo(0,0);await dorme(800);
document.querySelectorAll('body *').forEach(e=>{if(getComputedStyle(e).position!=='fixed')return;const r=e.getBoundingClientRect();if(r.height>0&&r.top>innerHeight/2)e.style.setProperty('display','none','important')});
return n})()
JS;

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

    /**
     * Print de uma página/aparelho com a assinatura da página
     * (PaginasMedidas::assinatura, para o comportamento:prints saber quando
     * ela mudou).
     *
     * @return array<string, mixed>
     */
    public function gerar(string $produto, string $pagina, string $aparelho, ?string $hash = null): array
    {
        $url = self::url($produto, $pagina);
        if ($url === null) {
            throw new RuntimeException('Esta página não tem endereço conhecido para o print.');
        }
        $hash ??= app(PaginasMedidas::class)->assinatura($produto, $pagina);
        $destino = self::arquivo($produto, $pagina, $aparelho);
        $info = $this->tirar($url, self::LARGURAS[$aparelho] ?? self::LARGURAS['celular'], $destino) + ['hash' => $hash];
        file_put_contents($destino.'.json', json_encode($info));

        return $info;
    }

    /** Hash do HTML sem nonce/csrf (muda só quando a página muda). */
    public static function hashDoHtml(string $url): ?string
    {
        try {
            $r = Http::timeout(15)->withUserAgent('KazaKoraPrint/1.0 (bot)')->get($url);

            return $r->successful() ? md5((string) preg_replace('/nonce="[^"]*"|csrf[^>]*>/i', '', $r->body())) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Endereço público da página (o mesmo que o visitante abre). */
    public static function url(string $produto, string $pagina, string $idioma = 'es'): ?string
    {
        if (PaginasDoSite::paginaDeLoja($produto)) {
            return PaginasDoSite::url($produto);
        }
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
            $this->comando('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-reduced-motion', 'value' => 'reduce']]], $sessao);
            $this->comando('Page.enable', [], $sessao);
            $this->comando('Page.addScriptToEvaluateOnNewDocument', ['source' => self::SEM_AVISO_DE_COOKIES], $sessao);
            $this->comando('Page.addScriptToEvaluateOnNewDocument', ['source' => self::SEM_ANIMACAO], $sessao);
            $this->comando('Page.navigate', ['url' => $url], $sessao);
            $this->esperarEvento('Page.loadEventFired', 30);
            // Desce devagar, espera as imagens e mostra o que ficou escondido (PREPARAR).
            $this->avaliar(sprintf(self::PREPARAR, self::ALTURA_MAXIMA), $sessao, 90);
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

            // Fatias de FATIA px: a 1ª aparece na hora, as outras chegam em seguida.
            $fatias = [];
            for ($y = 0, $i = 0; $y < $altura; $y += self::FATIA, $i++) {
                $h = min(self::FATIA, $altura - $y);
                $pedaco = $this->comando('Page.captureScreenshot', ['format' => 'webp', 'quality' => 70, 'captureBeyondViewport' => true,
                    'clip' => ['x' => 0, 'y' => $y, 'width' => $largura, 'height' => $h, 'scale' => 1]], $sessao, 60)['data'] ?? null;
                if (! is_string($pedaco)) {
                    throw new RuntimeException('O Chrome não devolveu a fatia '.($i + 1).' do print.');
                }
                file_put_contents(self::fatia($destino, $i), base64_decode($pedaco));
                $fatias[] = $h;
            }
            foreach (glob($destino.'-f*.webp') ?: [] as $velha) {
                if ((int) substr((string) strrchr(basename($velha, '.webp'), 'f'), 1) >= count($fatias)) {
                    @unlink($velha);
                }
            }

            $info = ['altura' => $altura, 'largura' => $largura, 'secoes' => array_values($medida['s'] ?? []), 'url' => $url, 'em' => now()->toIso8601String(),
                'versao' => self::VERSAO, 'fatias' => $fatias, 'miniatura' => $this->miniatura($sessao, $largura, $altura)];
            file_put_contents($destino.'.json', json_encode($info));

            return $info;
        } finally {
            $this->fechar();
        }
    }

    public static function fatia(string $destino, int $i): string
    {
        return $destino.'-f'.$i.'.webp';
    }

    /**
     * Miniatura minúscula da página inteira (cerca de 24 px de largura) em
     * data URI: aparece borrada na hora, antes de qualquer fatia chegar.
     */
    private function miniatura(string $sessao, int $largura, int $altura): ?string
    {
        foreach ([[24, 30], [16, 20], [10, 10]] as [$px, $qualidade]) {
            $dados = $this->comando('Page.captureScreenshot', ['format' => 'webp', 'quality' => $qualidade, 'captureBeyondViewport' => true,
                'clip' => ['x' => 0, 'y' => 0, 'width' => $largura, 'height' => $altura, 'scale' => $px / $largura]], $sessao, 60)['data'] ?? null;
            if (is_string($dados) && strlen($dados) <= self::MINIATURA_MAX) {
                return 'data:image/webp;base64,'.$dados;
            }
        }

        return null;
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

    private function avaliar(string $js, string $sessao, int $segundos = 60): mixed
    {
        return $this->comando('Runtime.evaluate', ['expression' => $js, 'awaitPromise' => true, 'returnByValue' => true], $sessao, $segundos)['result']['value'] ?? null;
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
