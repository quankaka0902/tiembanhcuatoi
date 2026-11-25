<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;
use Inertia\Ssr\Response;
use Throwable;

class PublicRenderingGateway implements Gateway
{
    public function __construct(private readonly PublicRendering $rendering) {}

    public function dispatch(array $page): ?Response
    {
        // Private pages must never issue an SSR request, even with SSR enabled.
        if (! config('public-rendering.ssr_enabled', false) || ! ($public = $this->rendering->ssrPage($page))) {
            return null;
        }
        $url = (string) config('public-rendering.ssr_url');
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'http'
            || ! in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]', '::1'], true)
            || ! empty($parts['user']) || ! empty($parts['pass']) || ! empty($parts['query']) || ! empty($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        try {
            $http = Http::withoutRedirecting()->connectTimeout(0.15)->timeout(min(0.5, max(0.05, (float) config('public-rendering.ssr_timeout', 0.5))))
                ->post(rtrim($url, '/').'/render', $public);
            if (! $http->successful()) {
                return null;
            }
            $response = $http->json();
            $body = $response['body'] ?? null;
            if (! is_string($body) || strlen($body) > 2097152 || ! str_contains($body, '<div id="app" data-page="')) {
                return null;
            }
            $body = preg_replace('/<div id="app"(?= data-page=")/', '<div id="app" data-react-ssr="true" data-rendering="react-ssr"', $body, 1);

            return new Response('', $body);
        } catch (Throwable) {
            // The PHP projection remains the complete public HTML fallback.
            return null;
        }
    }
}
