<?php

namespace App\Services;

interface LocationAdapter
{
    /** Return nullable metrics; implementations must not log or retain the destination. */
    public function estimate(array $origin, array $destination, array $context = []): array;
}
