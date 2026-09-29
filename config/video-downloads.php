<?php

return [
    'binary' => env('YT_DLP_BINARY', '/usr/local/bin/yt-dlp'),
    'ffmpeg_binary' => env('FFMPEG_BINARY', '/usr/bin/ffmpeg'),
    // Comma-separated yt-dlp JS runtimes. Hostinger's alt Node 20 is currently unsupported by yt-dlp_ejs;
    // prefer the user-installed Node 24 when present, then fall back silently.
    'js_runtimes' => env('VIDEO_DOWNLOAD_JS_RUNTIMES', 'node:/home/u902517809/.nvm/versions/node/v24.16.0/bin/node,node:/opt/alt/alt-nodejs20/root/usr/bin/node'),
    // Ver VideoDownloadService::downloadCommand() — só pro YouTube.
    'cookies_path' => env('VIDEO_DOWNLOAD_COOKIES_PATH'),
    'youtube_pot_provider_home' => env('VIDEO_DOWNLOAD_YOUTUBE_POT_PROVIDER_HOME'),
    'timeout_seconds' => (int) env('VIDEO_DOWNLOAD_TIMEOUT', 180),
    'max_filesize' => env('VIDEO_DOWNLOAD_MAX_FILESIZE', '300M'),
    'cleanup_ttl_minutes' => (int) env('VIDEO_DOWNLOAD_CLEANUP_TTL_MINUTES', 120),
    'base_path' => storage_path('app/private/video-downloads'),

    'allowed_hosts' => [
        'youtube.com',
        'youtu.be',
        'tiktok.com',
        'vm.tiktok.com',
        'vt.tiktok.com',
        'facebook.com',
        'fb.watch',
        'instagram.com',
        'instagr.am',
    ],
];
