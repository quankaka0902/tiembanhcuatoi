<?php

namespace App\Http\Middleware;

use App\Services\Store;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'shop' => fn () => Store::publicSettings(),
            'auth' => fn () => $request->user()?->only('name', 'email', 'role', 'can_reconcile'),
            'cartCount' => fn () => array_sum(array_column($request->session()->get('cart', []), 'quantity')),
            'notice' => fn () => $request->session()->get('notice'),
        ];
    }
}
