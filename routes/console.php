<?php

use App\Models\User;
use App\Services\Totp;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

Artisan::command('bakery:admin', function () {
    $fields = [
        'name' => $this->ask('Tên quản trị viên'),
        'email' => Str::lower((string) $this->ask('Email')),
        'password' => $this->secret('Mật khẩu (ít nhất 10 ký tự)'),
    ];
    $validator = Validator::make($fields, [
        'name' => 'required|string|max:120',
        'email' => 'required|email|max:254|unique:users',
        'password' => 'required|min:10|max:72',
    ]);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }

    $admin = new User;
    $admin->forceFill($fields + ['role' => 'admin', 'active' => true, 'can_reconcile' => true])->save();
    $this->info('Đã tạo tài khoản quản trị.');

    return 0;
})->purpose('Tạo tài khoản quản trị từ terminal');

Artisan::command('bakery:mfa {email}', function () {
    $email = Str::lower(trim($this->argument('email')));
    $secret = Totp::generateSecret();
    $changed = DB::transaction(function () use ($email, $secret) {
        $user = User::where('email', $email)->whereIn('role', ['admin', 'staff'])->where('active', true)->lockForUpdate()->first();
        if (! $user) {
            return false;
        }
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_last_step' => null])->save();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))->where('user_id', $user->id)->delete();
        }

        return true;
    }, 5);
    if (! $changed) {
        $this->error('Không tìm thấy tài khoản nội bộ đang hoạt động.');

        return 1;
    }
    $this->line($secret);

    return 0;
})->purpose('Cấp hoặc đổi khóa TOTP và thu hồi phiên quản trị cũ');
