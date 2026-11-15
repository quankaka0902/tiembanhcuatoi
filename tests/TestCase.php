<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        $config = $application->make('config');
        $driver = $config->get('database.default');
        $connection = $config->get('database.connections.'.$driver, []);
        $database = $connection['database'] ?? '';
        $isolated = ($driver === 'sqlite' && $database === ':memory:')
            || ($driver === 'mysql' && str_ends_with($database, '_test'));

        if (! $application->environment('testing') || ! $isolated || ! empty($connection['url'])) {
            throw new RuntimeException('Use in-memory SQLite or a dedicated MySQL schema ending in _test, with DB_URL unset.');
        }

        return $application;
    }
}
