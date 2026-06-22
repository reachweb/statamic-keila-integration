<?php

namespace Reachweb\StatamicKeilaIntegration\Tests\Listeners;

use Illuminate\Support\Facades\Bus;
use Reachweb\StatamicKeilaIntegration\Jobs\SyncContactToKeila;
use Reachweb\StatamicKeilaIntegration\Tests\TestCase;
use Statamic\Events\SubmissionCreated;
use Statamic\Facades\Form;

class ForwardSubmissionToKeilaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('statamic-keila-integration.url', 'https://keila.test');
        config()->set('statamic-keila-integration.token', 'secret');
        config()->set('statamic-keila-integration.forms', [
            'newsletter' => [
                'opt_in_field' => 'newsletter_opt_in',
                'tags' => ['newsletter'],
                'source' => 'footer',
                'field_map' => [
                    'email' => 'email',
                    'first_name' => 'first_name',
                    'room_interest' => 'data.room_interest',
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function submit(string $form, array $data): void
    {
        $submission = Form::make($form)->makeSubmission();
        $submission->data($data);

        event(new SubmissionCreated($submission));
    }

    public function test_accepted_opt_in_dispatches_the_sync_job_after_response(): void
    {
        Bus::fake();

        $this->submit('newsletter', [
            'email' => 'jane@example.com',
            'first_name' => 'Jane',
            'room_interest' => 'Sea View',
            'newsletter_opt_in' => true,
        ]);

        Bus::assertDispatchedAfterResponse(SyncContactToKeila::class, function (SyncContactToKeila $job) {
            return $job->payload['email'] === 'jane@example.com'
                && $job->payload['top']['first_name'] === 'Jane'
                && $job->payload['data']['room_interest'] === 'Sea View'
                && in_array('newsletter', $job->payload['tags'], true)
                && $job->payload['source'] === 'footer'
                // Proof of consent is captured at dispatch time (request alive).
                && array_key_exists('consent_ip', $job->payload)
                && str_ends_with($job->payload['consent_at'], '+00:00');
        });
    }

    public function test_unmapped_form_is_ignored(): void
    {
        Bus::fake();

        $this->submit('contact', ['email' => 'jane@example.com', 'newsletter_opt_in' => true]);

        Bus::assertNotDispatchedAfterResponse(SyncContactToKeila::class);
    }

    public function test_opt_in_not_accepted_is_ignored(): void
    {
        Bus::fake();

        $this->submit('newsletter', ['email' => 'jane@example.com', 'newsletter_opt_in' => false]);

        Bus::assertNotDispatchedAfterResponse(SyncContactToKeila::class);
    }

    public function test_missing_opt_in_field_is_ignored(): void
    {
        Bus::fake();

        $this->submit('newsletter', ['email' => 'jane@example.com']);

        Bus::assertNotDispatchedAfterResponse(SyncContactToKeila::class);
    }

    public function test_missing_credentials_does_not_dispatch(): void
    {
        config()->set('statamic-keila-integration.url', null);

        Bus::fake();

        $this->submit('newsletter', ['email' => 'jane@example.com', 'newsletter_opt_in' => true]);

        Bus::assertNotDispatchedAfterResponse(SyncContactToKeila::class);
    }

    public function test_invalid_email_does_not_dispatch(): void
    {
        Bus::fake();

        $this->submit('newsletter', ['email' => 'not-an-email', 'newsletter_opt_in' => true]);

        Bus::assertNotDispatchedAfterResponse(SyncContactToKeila::class);
    }
}
