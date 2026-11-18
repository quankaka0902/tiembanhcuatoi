<?php

namespace App\Http\Middleware;

use App\Services\PrivateStaging;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePrivateStaging
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PrivateStaging::enabled()) {
            return $next($request);
        }

        $password = (string) config('bakery.staging.password');
        if (! PrivateStaging::accessConfigured() || ! PrivateStaging::isolatedDatabase() || config('app.debug')) {
            $response = response('Staging chưa được cấu hình.', 503);
        } elseif (! $request->isSecure() || $request->getHost() !== PrivateStaging::host()) {
            $response = response('Dùng địa chỉ HTTPS riêng của staging.', 403);
        } elseif ($request->getUser() !== 'staging' || ! hash_equals($password, (string) $request->getPassword())) {
            $response = response('Cần quyền truy cập staging.', 401, ['WWW-Authenticate' => 'Basic realm="Bakery staging", charset="UTF-8"']);
        } else {
            $response = $next($request);
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
