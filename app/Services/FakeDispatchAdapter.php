<?php

namespace App\Services;

use App\Contracts\DispatchAdapter;
use Illuminate\Validation\ValidationException;

class FakeDispatchAdapter implements DispatchAdapter
{
    public function manifest(array $orderIds): array
    {
        if (! app()->environment(['testing', 'local'])) {
            throw ValidationException::withMessages(['adapter' => 'Bộ giả lập chỉ dùng trong local/test.']);
        }
        sort($orderIds);

        return ['adapter' => 'fake', 'reference' => 'SANDBOX-'.substr(hash('sha256', json_encode($orderIds)), 0, 16), 'eta' => null];
    }
}
