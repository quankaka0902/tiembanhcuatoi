<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class Accounts extends Controller
{
    public function form(string $mode, ?string $token = null)
    {
        return Inertia::render('Auth', ['mode' => $mode, 'token' => $token, 'email' => request('email')])
            ->toResponse(request())->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function register(Request $request)
    {
        if (is_string($email = $request->input('email'))) {
            $request->merge(['email' => Str::lower($email)]);
        }
        $data = $request->validate([
            'name' => 'required|string|max:120', 'email' => 'required|email|max:254|unique:users',
            'password' => 'required|string|min:10|max:72|confirmed',
        ]);
        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('notice', 'Đã tạo tài khoản.');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:72']);
        $data['email'] = Str::lower($data['email']);
        $guard = Auth::guard();
        $request->session()->forget('staff_mfa_pending');
        if (! $guard->validate([...$data, 'active' => true])) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }
        $user = $guard->getLastAttempted();
        $guard->getProvider()->rehashPasswordIfRequired($user, $data);
        if ($user->isStaff()) {
            if (! $proof = $user->staffProof()) {
                throw ValidationException::withMessages(['email' => 'Tài khoản nội bộ chưa được cấp khóa xác thực hai bước. Hãy liên hệ quản trị viên.']);
            }
            $request->session()->regenerate(true);
            $request->session()->put('staff_mfa_pending', ['id' => $user->id, 'proof' => $proof, 'expires' => now()->addMinutes(5)->timestamp]);

            return redirect('/xac-thuc-hai-buoc');
        }
        $guard->login($user);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function challenge(Request $request)
    {
        if (($request->session()->get('staff_mfa_pending.expires') ?? 0) <= now()->timestamp) {
            $request->session()->forget('staff_mfa_pending');

            return redirect('/dang-nhap');
        }

        return $this->form('two-factor');
    }

    public function verifyChallenge(Request $request)
    {
        $attempt = $request->session()->get('staff_mfa_pending');
        if (! $attempt || $attempt['expires'] <= now()->timestamp) {
            $request->session()->forget('staff_mfa_pending');

            return redirect('/dang-nhap')->with('notice', 'Phiên xác thực đã hết hạn. Vui lòng đăng nhập lại.');
        }
        $data = $request->validate(['code' => 'required|digits:6']);
        $user = DB::transaction(function () use ($attempt, $data) {
            $candidate = User::lockForUpdate()->find($attempt['id']);
            $proof = $candidate?->staffProof();
            if (! $proof || ! hash_equals($proof, $attempt['proof'])) {
                return null;
            }
            $step = Totp::matchingStep($candidate->two_factor_secret, $data['code'], now()->timestamp);
            if ($step === null || $step <= ($candidate->two_factor_last_step ?? -1)) {
                throw ValidationException::withMessages(['code' => 'Mã xác thực không hợp lệ hoặc đã dùng.']);
            }
            $candidate->forceFill(['two_factor_last_step' => $step])->save();

            return $candidate;
        }, 5);
        $request->session()->forget('staff_mfa_pending');
        if (! $user) {
            return redirect('/dang-nhap')->with('notice', 'Phiên xác thực đã thay đổi. Vui lòng đăng nhập lại.');
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('staff_mfa_verified', $user->staffProof());

        return redirect()->intended('/quan-tri');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function forgot(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:254']);
        Password::sendResetLink(['email' => Str::lower($data['email']), 'active' => true]);

        return back()->with('notice', __('passwords.sent'));
    }

    public function reset(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:254', 'token' => 'required|string',
            'password' => 'required|string|min:10|max:72|confirmed',
        ]);
        $status = Password::reset([
            ...$data, 'email' => Str::lower($data['email']), 'active' => true,
            'password_confirmation' => $request->input('password_confirmation'),
        ], function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }
            event(new PasswordReset($user));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect('/dang-nhap')->with('notice', __('passwords.reset'));
    }
}
