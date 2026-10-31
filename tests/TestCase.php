<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    // RefreshDatabase drops every table. A DB_DATABASE exported in the shell
    // wins over phpunit.xml, so the name is checked before anything runs.
    protected function setUpTraits()
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("The tests only run on a database whose name ends in _test, not on {$database}.");
        }

        return parent::setUpTraits();
    }

    // Google and any other service are faked in each test; nothing leaves the machine.
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
