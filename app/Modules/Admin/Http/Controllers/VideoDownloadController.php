<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\VideoDownloadRequest;
use App\Modules\Admin\Services\VideoDownloadService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class VideoDownloadController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/VideoDownloads/Index', [
            'platforms' => ['YouTube', 'TikTok', 'Facebook', 'Instagram'],
            'maxFileSize' => config('video-downloads.max_filesize'),
            'timeoutSeconds' => config('video-downloads.timeout_seconds'),
            'csrfToken' => csrf_token(),
            'downloadUrl' => route('admin.download-videos.store'),
        ]);
    }

    public function store(VideoDownloadRequest $request, VideoDownloadService $service): BinaryFileResponse|JsonResponse
    {
        try {
            $download = $service->download((string) $request->validated('url'), $request->user());

            app()->terminating(fn () => is_dir($download->directory) ? app('files')->deleteDirectory($download->directory) : null);

            return response()->download($download->path, $download->filename, [
                'Content-Type' => $download->mimeType ?: 'video/mp4',
            ])->deleteFileAfterSend(true);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable) {
            return response()->json([
                'message' => 'Falha temporária no servidor. Tente novamente em alguns minutos.',
            ], 500);
        }
    }
}
