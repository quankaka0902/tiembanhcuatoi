<?php

namespace App\Contracts;

interface DispatchAdapter
{
    /** Internal IDs only. No address, credentials or outbound service request. */
    public function manifest(array $orderIds): array;
}
