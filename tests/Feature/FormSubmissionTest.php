<?php

namespace Reachweb\StatamicKeilaIntegration\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Reachweb\StatamicKeilaIntegration\Jobs\SyncContactToKeila;
use Reachweb\StatamicKeilaIntegration\Tests\FeatureTestCase;
use Statamic\Events\SubmissionCreated;
use Statamic\Forms\SendEmails;

class FormSubmissionTest extends FeatureTestCase
{
    public function test_keila_sync_never_breaks_the_native_submission_pipeline(): void
    {
        // Bus::fake() captures the afterResponse Keila job (and the native
        // SendEmails job) instead of running them — proving the request path
        // never depends on Keila being reachable.
        Bus::fake();
        Http::preventStrayRequests();

        config()->set('statamic-keila-integration.url', 'https://keila.test');
        config()->set('statamic-keila-integration.token', 'secret');
        config()->set('statamic-keila-integration.forms', [
            'newsletter' => [
                'opt_in_field' => 'newsletter_opt_in',
                'tags' => ['newsletter'],
                'field_map' => ['email' => 'email'],
            ],
        ]);

        $created = false;
        Event::listen(SubmissionCreated::class, function () use (&$created) {
            $created = true;
        });

        $response = $this
            ->withoutMiddleware(VerifyCsrfToken::class)
            ->post(route('statamic.forms.submit', 'newsletter'), [
                'email' => 'jane@example.com',
                'newsletter_opt_in' => '1',
            ]);

        // Visitor gets a clean success, not a 500.
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        // Native submission was saved...
        $this->assertTrue($created, 'The native submission should still be created.');

        // ...native notification emails still fire (dispatched after save)...
        Bus::assertDispatched(SendEmails::class);

        // ...and the Keila sync is deferred off the request lifecycle.
        Bus::assertDispatchedAfterResponse(SyncContactToKeila::class);
    }
}
