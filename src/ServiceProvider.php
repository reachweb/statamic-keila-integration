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
        //
    }
}
