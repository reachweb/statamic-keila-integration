<?php

namespace Reachweb\StatamicKeilaIntegration\Tests;

use Reachweb\StatamicKeilaIntegration\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
