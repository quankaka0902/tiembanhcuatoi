<?php

namespace App\Http\Middleware;

use App\Services\PrivateStaging;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicResponsePolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $image = $request->is('anh/*') && $response->isSuccessful()
            && str_starts_with((string) $response->headers->get('Content-Type'), 'image/');

        // HTML and Inertia JSON contain session-dependent navigation, even on public URLs.
        // Only public image bytes may use shared caches; staging overrides those too.
        if (! $image || PrivateStaging::enabled()) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->setVary('Cookie', false);
        }

        if (! $image) {
            $public = $request->isMethod('GET') || $request->isMethod('HEAD');
            $public = $public && preg_match('~^(?:/|/thuc-don(?:/[^/]+)?|/banh/[^/]+|/gioi-thieu|/lien-he|/cau-hoi-thuong-gap|/cua-hang|/chinh-sach-dat-banh)$~D', '/'.trim($request->path(), '/'));
            $indexable = $public && $response->isSuccessful() && $request->attributes->get('public_seo_indexable', false);
            $response->headers->set('X-Robots-Tag', $indexable ? 'index, follow' : ($public && ! PrivateStaging::enabled() ? 'noindex, follow' : 'noindex, nofollow'));
        }

        return $response;
    }
}
