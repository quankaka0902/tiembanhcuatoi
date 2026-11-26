<?php

namespace App\Http\Middleware;

use App\Services\Branches;
use App\Services\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'cartBranchId' => fn () => (int) $request->session()->get('cart_branch_id', 1),
            'notice' => fn () => $request->session()->get('notice'),
            'branchContext' => function () use ($request) {
                $user = $request->user();
                if (! $user || ! $request->is('quan-tri', 'quan-tri/*')) {
                    return null;
                }
                $actions = ['shared_catalog', 'branch_catalog', 'branch_settings', 'manage_branches', 'orders', 'production', 'reconcile', 'reports', 'export', 'view_orders', 'inventory'];

                return [
                    'enabled' => Branches::enabled(), 'currentId' => Branches::id(), 'role' => Branches::role($user),
                    'allowed' => Branches::enabled() ? DB::table('branches')->whereIn('id', Branches::allowedIds($user))->orderBy('id')->get(['id', 'name', 'code', 'active']) : [],
                    'capabilities' => array_combine($actions, array_map(fn ($action) => Branches::can($user, $action), $actions)),
                ];
            },
        ];
    }
}
