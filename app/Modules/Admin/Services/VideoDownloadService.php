<?php

namespace App\Modules\Admin\Services;

use App\Models\User;
use App\Modules\Admin\Data\DownloadedVideo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class VideoDownloadService
{
    public function download(string $url, ?User $user = null): DownloadedVideo
    {
        $directory = $this->createTemporaryDirectory();
        $command = $this->downloadCommand($url, $directory);

        try {
            $process = new Process($command);
            $process->setTimeout((int) config('video-downloads.timeout_seconds', 180));
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->friendlyProcessError($process->getErrorOutput() ?: $process->getOutput()));
            }

            $path = $this->resolveDownloadedPath($directory, $process->getOutput());
            $this->ensureAllowedFileSize($path);

            Log::info('Video downloaded from admin tool.', [
                'user_id' => $user?->id,
                'host' => parse_url($url, PHP_URL_HOST),
                'filename' => basename($path),
                'size' => File::size($path),
            ]);

            return new DownloadedVideo(
                path: $path,
                filename: $this->safeFilename($path),
                directory: $directory,
                mimeType: File::mimeType($path) ?: 'video/mp4',
            );
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    /**
     * Prévia antes de baixar: título, autor, duração e a miniatura já
     * embutida (data URI). A miniatura vem do servidor porque as CDNs do
     * TikTok e do Instagram recusam imagem carregada de outro site.
     *
     * @return array{title: string, uploader: ?string, duration: ?int, platform: string, thumbnail: ?string, semMarcaDagua: bool}
     */
    public function preview(string $url): array
    {
        $process = new Process([
            ...$this->baseCommand($url),
            '--skip-download',
            '--dump-single-json',
            $url,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->friendlyProcessError($process->getErrorOutput() ?: $process->getOutput()));
        }

        $info = json_decode($process->getOutput(), true);

        if (! is_array($info)) {
            throw new RuntimeException('Não foi possível ler as informações deste vídeo.');
        }

        $tiktok = $this->isTikTok($url) || Str::lower((string) ($info['extractor_key'] ?? '')) === 'tiktok';

        if ($tiktok && ! $this->hasFormatWithoutWatermark($info)) {
            throw new RuntimeException('O TikTok só entregou a versão com marca d\'água deste vídeo. Tente de novo em alguns minutos.');
        }

        return [
            'title' => Str::limit(trim((string) ($info['title'] ?? $info['description'] ?? 'Vídeo')), 140) ?: 'Vídeo',
            'uploader' => ($info['uploader'] ?? $info['channel'] ?? null) ?: null,
            'duration' => isset($info['duration']) ? (int) round((float) $info['duration']) : null,
            'platform' => (string) ($info['extractor_key'] ?? ''),
            'thumbnail' => $this->thumbnailDataUri($this->bestThumbnail($info)),
            'semMarcaDagua' => $tiktok,
        ];
    }

    private function downloadCommand(string $url, string $directory): array
    {
        return [
            ...$this->baseCommand($url),
            '--merge-output-format',
            'mp4',
            // h264 abre em qualquer player e editor do Windows; h265/AV1 não.
            '-S',
            'vcodec:h264,res,acodec:m4a',
            '-f',
            // TikTok: o formato "download" é o que leva a marca d'água; o
            // play_addr (os demais) sai limpo. Sem versão limpa, falha em
            // vez de entregar com marca.
            $this->isTikTok($url)
                ? 'b[format_id!=download][format_note!*=?atermark]/bv*[format_id!=download][format_note!*=?atermark]+ba'
                : 'bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/bv*+ba/best',
            '--max-filesize',
            (string) config('video-downloads.max_filesize', '300M'),
            '--print',
            'after_move:filepath',
            '-o',
            $directory.'/%(title).100s-%(id)s.%(ext)s',
            $url,
        ];
    }

    private function baseCommand(string $url): array
    {
        $command = [
            ...$this->binaryCommand(),
            '--ignore-config',
            '--no-playlist',
            '--no-cache-dir',
            '--restrict-filenames',
            '--windows-filenames',
        ];

        if ($ffmpeg = $this->configuredExecutable('video-downloads.ffmpeg_binary')) {
            $command = [...$command, '--ffmpeg-location', $ffmpeg];
        }

        foreach ($this->configuredJsRuntimes() as $jsRuntime) {
            $command = [...$command, '--js-runtimes', $jsRuntime];
        }

        // YouTube barra download anônimo de servidor ("confirme que não é
        // um robô"): cookies de uma conta logada + o provedor de PO token
        // (bgutil) instalados na hospedagem. Os dois são opcionais — sem
        // eles, TikTok/Instagram/Facebook continuam funcionando. Cookies do
        // YouTube mandados ao TikTok fazem ele responder 403 (2026-10-17).
        $cookies = config('video-downloads.cookies_path');

        if ($this->isYouTube($url) && is_string($cookies) && $cookies !== '' && is_readable($cookies)) {
            $command = [...$command, '--cookies', $cookies];
        }

        $potHome = config('video-downloads.youtube_pot_provider_home');

        if (is_string($potHome) && $potHome !== '' && is_dir($potHome)) {
            $command = [...$command, '--extractor-args', 'youtubepot-bgutilscript:server_home='.$potHome];
        }

        return [
            ...$command,
            '--force-ipv4',
            '--socket-timeout',
            '20',
            '--retries',
            '2',
            '--extractor-retries',
            '2',
            '--fragment-retries',
            '2',
        ];
    }

    private function isYouTube(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['youtube.com', 'youtu.be'], true)
            || str_ends_with($host, '.youtube.com')
            || str_ends_with($host, '.youtu.be');
    }

    private function isTikTok(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $host === 'tiktok.com' || str_ends_with($host, '.tiktok.com');
    }

    private function hasFormatWithoutWatermark(array $info): bool
    {
        foreach ((array) ($info['formats'] ?? []) as $format) {
            $video = ($format['vcodec'] ?? null) !== 'none';
            $watermarked = ($format['format_id'] ?? '') === 'download'
                || str_contains(Str::lower((string) ($format['format_note'] ?? '')), 'watermark');

            if ($video && ! $watermarked) {
                return true;
            }
        }

        return false;
    }

    private function bestThumbnail(array $info): ?string
    {
        // yt-dlp já põe em "thumbnail" a de maior preferência.
        $thumbnails = (array) ($info['thumbnails'] ?? []);
        $url = $info['thumbnail'] ?? (end($thumbnails)['url'] ?? null);

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    private function thumbnailDataUri(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'])
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        $type = Str::before((string) $response->header('Content-Type'), ';');
        $body = $response->body();

        if (! $response->successful() || ! str_starts_with($type, 'image/') || $body === '' || strlen($body) > 3 * 1024 * 1024) {
            return null;
        }

        return 'data:'.$type.';base64,'.base64_encode($body);
    }

    private function binaryCommand(): array
    {
        $configured = config('video-downloads.binary');

        if (is_string($configured) && $configured !== '') {
            if (is_executable($configured)) {
                return [$configured];
            }

            Log::warning('Configured yt-dlp binary is not executable; falling back to PATH lookup.', [
                'binary' => $configured,
            ]);
        }

        $finder = new ExecutableFinder();

        if ($ytDlp = $finder->find('yt-dlp')) {
            return [$ytDlp];
        }

        if ($youtubeDl = $finder->find('youtube-dl')) {
            return [$youtubeDl];
        }

        if ($python = $finder->find('python3')) {
            $probe = new Process([$python, '-m', 'yt_dlp', '--version']);
            $probe->setTimeout(10);
            $probe->run();

            if ($probe->isSuccessful()) {
                return [$python, '-m', 'yt_dlp'];
            }
        }

        throw new RuntimeException('O recurso de download ainda não está configurado no servidor. Instale yt-dlp e ffmpeg.');
    }

    private function configuredExecutable(string $configKey): ?string
    {
        $path = config($configKey);

        if (! is_string($path) || $path === '') {
            return null;
        }

        if (is_executable($path)) {
            return $path;
        }

        Log::warning('Configured video download executable is not available.', [
            'config_key' => $configKey,
            'path' => $path,
        ]);

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function configuredJsRuntimes(): array
    {
        $configured = config('video-downloads.js_runtimes');

        if (! is_string($configured) || trim($configured) === '') {
            return [];
        }

        $runtimes = [];

        foreach (array_filter(array_map('trim', explode(',', $configured))) as $runtime) {
            [$runtimeName, $runtimePath] = array_pad(explode(':', $runtime, 2), 2, null);

            if ($runtimeName && $runtimePath && is_executable($runtimePath)) {
                $runtimes[] = $runtime;
                continue;
            }

            Log::warning('Configured yt-dlp JavaScript runtime is not executable; running without this runtime.', [
                'runtime' => $runtime,
            ]);
        }

        return $runtimes;
    }

    private function createTemporaryDirectory(): string
    {
        $directory = rtrim((string) config('video-downloads.base_path'), '/').'/'.(string) Str::uuid();

        File::ensureDirectoryExists($directory, 0750, true);

        return $directory;
    }

    private function resolveDownloadedPath(string $directory, string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
        $candidates = [...$lines, ...File::files($directory)];
        $directoryRealPath = realpath($directory);

        foreach ($candidates as $candidate) {
            $path = is_object($candidate) && method_exists($candidate, 'getPathname') ? $candidate->getPathname() : (string) $candidate;

            if (! is_file($path)) {
                continue;
            }

            $realPath = realpath($path);

            if ($realPath && $directoryRealPath && str_starts_with($realPath, $directoryRealPath.DIRECTORY_SEPARATOR)) {
                return $realPath;
            }
        }

        throw new RuntimeException('O vídeo foi processado, mas o arquivo final não foi encontrado.');
    }

    private function safeFilename(string $path): string
    {
        $filename = basename($path);
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $filename) ?: 'kazakora-video.mp4';

        return trim($filename, '.- ') ?: 'kazakora-video.mp4';
    }

    private function ensureAllowedFileSize(string $path): void
    {
        $limit = $this->sizeToBytes((string) config('video-downloads.max_filesize', '300M'));

        if ($limit > 0 && File::size($path) > $limit) {
            throw new RuntimeException('O vídeo excede o tamanho máximo permitido.');
        }
    }

    private function sizeToBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^(\d+)([KMG])?B?$/i', $value, $matches) !== 1) {
            return 0;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

    private function friendlyProcessError(string $output): string
    {
        $normalized = Str::lower($output);

        return match (true) {
            str_contains($normalized, 'http error 429') || str_contains($normalized, 'too many requests') => 'A plataforma recusou temporariamente o servidor por excesso de requisições. Tente novamente mais tarde ou com outro link.',
            str_contains($normalized, 'confirm you') || str_contains($normalized, 'not a bot') => 'A plataforma exigiu verificação antirobô e recusou o download no servidor. Tente novamente mais tarde ou use outro link público.',
            str_contains($normalized, 'file is larger than max-filesize') => 'O vídeo excede o tamanho máximo permitido.',
            str_contains($normalized, 'private') || str_contains($normalized, 'login') || str_contains($normalized, 'sign in') => 'Não foi possível baixar este vídeo. Ele pode estar privado ou exigir login.',
            str_contains($normalized, 'impersonat') && str_contains($normalized, 'unexpected response') => 'O servidor ainda não tem o componente que o TikTok exige (curl_cffi). Avise o suporte técnico.',
            str_contains($normalized, 'requested format is not available') => 'Não achei uma versão deste vídeo sem marca d\'água para baixar.',
            str_contains($normalized, 'unsupported url') => 'Este link não foi reconhecido pela ferramenta de download.',
            str_contains($normalized, 'timed out') || str_contains($normalized, 'timeout') => 'O vídeo demorou demais para ser preparado. Tente novamente com um vídeo menor.',
            default => 'Não foi possível baixar este vídeo. Ele pode estar privado, removido ou bloqueado pela plataforma.',
        };
    }
}
