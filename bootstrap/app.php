<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePrivateStaging;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(RequirePrivateStaging::class);
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo('/dang-nhap');
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code']);
        $exceptions->report(function (Throwable $exception) {
            if (! app()->runningInConsole() && request()->is('tra-cuu', 'tra-cuu/*', 'dat-lai-mat-khau', 'dat-lai-mat-khau/*', 'quen-mat-khau',
                'dat-banh', 'dat-banh/*', 'dang-nhap', 'xac-thuc-hai-buoc', 'quan-tri/don-hang', 'quan-tri/don-hang/*')) {
                Log::error('Lỗi xử lý yêu cầu riêng tư.', [
                    'route' => request()->route()?->uri(),
                    'exception' => $exception::class,
                ]);

                return false;
            }
        });
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if (! $request->expectsJson() && (in_array($status, [403, 404, 429], true) || ($status === 500 && ! config('app.debug')))) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
