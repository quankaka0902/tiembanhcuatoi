<?php

namespace App\Http\Controllers;

use App\Models\User;
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
        return Inertia::render('Auth', ['mode' => $mode, 'token' => $token, 'email' => request('email')]);
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
        if (! Auth::attempt([...$data, 'active' => true])) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng.']);
        }
        $request->session()->regenerate();
        $user = $request->user();

        return redirect()->intended($user->role === 'customer' ? '/' : '/quan-tri');
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
