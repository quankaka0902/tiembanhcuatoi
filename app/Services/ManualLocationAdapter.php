<?php

namespace App\Services;

class ManualLocationAdapter implements LocationAdapter
{
    public function estimate(array $origin, array $destination, array $context = []): array
    {
        return ['available' => false, 'distance_meters' => null, 'travel_minutes' => null,
            'provider' => 'manual', 'estimated' => true, 'reason' => 'provider_unavailable'];
    }
}
