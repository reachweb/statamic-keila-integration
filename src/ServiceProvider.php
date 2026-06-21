<?php

namespace Reachweb\StatamicKeilaIntegration;

use Reachweb\StatamicKeilaIntegration\Listeners\ForwardSubmissionToKeila;
use Statamic\Events\SubmissionCreated;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        SubmissionCreated::class => [
            ForwardSubmissionToKeila::class,
        ],
    ];

    public function bootAddon(): void
    {
        // Register the addon's views under the `statamic-keila-integration`
        // namespace and make them publishable so a host site can copy the
        // newsletter partial into resources/views/vendor/... and customise it.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-keila-integration');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-keila-integration'),
        ], 'statamic-keila-integration-views');
    }
}
