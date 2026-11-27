<?php

namespace App\Http\Middleware;

use App\Services\Branches;
use App\Services\Orders;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranch
{
    public function selectAdmin(Request $request): Response
    {
        $fields = $request->validate(['branch_id' => 'required|integer|min:1', 'order_id' => 'nullable|integer|min:1']);
        $user = $request->user()?->fresh();
        abort_unless($user?->active && $user->isStaff(), 403);
        abort_unless(in_array((int) $fields['branch_id'], Branches::allowedIds($user), true), 403);
        Branches::use((int) $fields['branch_id']);
        $target = Branches::can($user, 'driver') && ! Branches::can($user, 'dispatch') ? '/quan-tri/giao-nhan'
            : (Branches::can($user, 'view_orders') ? '/quan-tri' : '/quan-tri/lich-lam-banh');
        if (! empty($fields['order_id'])) {
            Branches::authorize($user, 'view_orders');
            abort_unless(Orders::query()->where('id', (int) $fields['order_id'])->exists(), 404);
            $target = '/quan-tri/don-hang/'.(int) $fields['order_id'];
        }
        $request->session()->put('admin_branch_id', (int) $fields['branch_id']);

        return redirect($target)
            ->with('notice', 'Đã đổi cơ sở đang quản trị.');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! Branches::enabled()) {
            Branches::use(1);

            return $next($request);
        }

        if ($request->is('quan-tri', 'quan-tri/*') && ($user = $request->user()?->fresh()) && $user->isStaff()) {
            $proof = $user->staffProof();
            if ($request->session()->has('staff_mfa_verified') && (! $proof || ! hash_equals($proof, (string) $request->session()->get('staff_mfa_verified')))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect('/dang-nhap')->with('notice', 'Quyền tài khoản đã thay đổi. Vui lòng đăng nhập và xác thực lại.');
            }
            $allowed = Branches::allowedIds($user);
            abort_unless($allowed, 403);
            $selected = (int) $request->session()->get('admin_branch_id', $allowed[0]);
            abort_unless(in_array($selected, $allowed, true), 403);
        } else {
            $selected = (int) $request->session()->get('branch_id', 1);
            $branch = Branches::find($selected);
            if (! $branch || ! $branch->active) {
                $selected = (int) (DB::table('branches')->where('active', true)->orderBy('id')->value('id') ?? 1);
                $request->session()->put('branch_id', $selected);
            }
        }
        Branches::use($selected);
        $request->attributes->set('branch_id', $selected);

        return $next($request);
    }
}
