<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the app for a test, refusing any database except in-memory SQLite so tests never wipe MySQL.
     *
     * This runs before RefreshDatabase and the other test traits.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        if ($connection !== 'sqlite' || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException("Tests must use the in-memory SQLite database, not '{$connection}'. "
                .'Check the force="true" env entries in phpunit.xml.');
        }

        return $app;
    }
}
