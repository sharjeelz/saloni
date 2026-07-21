<?php

namespace Tests;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tenancy is a static singleton; clear any leak from a previous test so
        // the BelongsToSalon auto-stamp can't reference a stale salon id.
        Tenancy::clear();
    }
}
