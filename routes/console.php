<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
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
