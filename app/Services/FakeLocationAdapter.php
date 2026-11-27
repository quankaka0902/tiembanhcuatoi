<?php

namespace App\Services;

use LogicException;

class FakeLocationAdapter implements LocationAdapter
{
    public function __construct(private array $fixtures = [])
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Adapter vị trí giả chỉ dùng ở local/test.');
        }
    }

    public function estimate(array $origin, array $destination, array $context = []): array
    {
        // Check again: a long-lived container cannot carry this fake into production.
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Adapter vị trí giả chỉ dùng ở local/test.');
        }
        $fixture = $this->fixtures[$context['branch_id'] ?? 0] ?? [];
        $failure = $fixture['failure'] ?? null;
        $valid = isset($fixture['distance_meters'], $fixture['travel_minutes'])
            && is_numeric($fixture['distance_meters']) && is_numeric($fixture['travel_minutes'])
            && is_finite((float) $fixture['distance_meters']) && is_finite((float) $fixture['travel_minutes'])
            && $fixture['distance_meters'] >= 0 && $fixture['travel_minutes'] >= 0;
        if ($failure || ! $valid) {
            return ['available' => false, 'distance_meters' => null, 'travel_minutes' => null,
                'provider' => 'fake', 'estimated' => true,
                'reason' => in_array($failure, ['timeout', 'permission_denied', 'provider_unavailable'], true) ? $failure : 'fake_no_fixture'];
        }

        return ['available' => true, 'distance_meters' => max(0, (int) $fixture['distance_meters']),
            'travel_minutes' => max(0, (int) $fixture['travel_minutes']), 'provider' => 'fake', 'estimated' => true, 'reason' => null];
    }
}
