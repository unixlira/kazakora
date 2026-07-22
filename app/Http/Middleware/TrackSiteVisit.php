<?php

namespace App\Http\Middleware;

use App\Modules\Analytics\Models\SiteVisit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackSiteVisit
{
    private const VISITOR_COOKIE = 'kazakora_visitor';

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp/i';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->shouldTrack($request, $response)) {
            $visitorId = $request->cookie(self::VISITOR_COOKIE) ?? (string) Str::uuid();

            if (! $request->cookie(self::VISITOR_COOKIE)) {
                Cookie::queue(self::VISITOR_COOKIE, $visitorId, 60 * 24 * 365);
            }

            SiteVisit::create([
                'visitor_id' => $visitorId,
                'user_id' => $request->user()?->id,
                'path' => '/'.ltrim($request->path(), '/'),
                'referer' => $request->headers->get('referer'),
                'user_agent' => $request->userAgent(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('get') || $response->getStatusCode() !== 200) {
            return false;
        }

        if ($request->is('admin*') || $request->is('build/*') || $request->is('storage/*')) {
            return false;
        }

        if ($request->headers->has('X-Inertia-Partial-Component')) {
            return false;
        }

        $userAgent = (string) $request->userAgent();

        if ($userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return false;
        }

        return true;
    }
}
