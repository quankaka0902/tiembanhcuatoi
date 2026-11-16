<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifyStaffFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $proof = $request->user()->staffProof();
        if ($proof && hash_equals($proof, (string) $request->session()->get('staff_mfa_verified'))) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/dang-nhap')->with('notice', 'Đăng nhập lại và xác thực hai bước để vào quản trị.');
    }
}
