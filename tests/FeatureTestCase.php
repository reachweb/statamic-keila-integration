<?php

namespace Reachweb\StatamicKeilaIntegration\Tests;

use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class FeatureTestCase extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $fixtures = __DIR__.'/__fixtures__';

        $app['config']->set('statamic.forms.forms', $fixtures.'/forms');
        $app['config']->set('statamic.system.blueprints_path', $fixtures.'/blueprints');
    }
}
