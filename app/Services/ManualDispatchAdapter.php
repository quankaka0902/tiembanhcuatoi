<?php

namespace App\Services;

use App\Contracts\DispatchAdapter;

class ManualDispatchAdapter implements DispatchAdapter
{
    public function manifest(array $orderIds): array
    {
        return ['adapter' => 'manual', 'reference' => null, 'eta' => null];
    }
}
