<?php

namespace Reachweb\StatamicKeilaIntegration\Tests\Jobs;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaTransientException;
use Reachweb\StatamicKeilaIntegration\Jobs\SyncContactToKeila;
use Reachweb\StatamicKeilaIntegration\Tests\TestCase;

class SyncContactToKeilaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('statamic-keila-integration.url', 'https://keila.test');
        config()->set('statamic-keila-integration.token', 'secret-token');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'email' => 'jane@example.com',
            'top' => ['email' => 'jane@example.com', 'first_name' => 'Jane'],
            'data' => ['room_interest' => 'Sea View'],
            'tags' => ['newsletter', 'kosaktis-website'],
            'source' => 'kosaktis-footer',
            'form' => 'newsletter',
            'consent_ip' => '203.0.113.9',
            'consent_at' => '2026-06-21T10:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function runJob(array $payload): void
    {
        (new SyncContactToKeila($payload))->handle();
    }

    public function test_new_email_is_created_as_active_with_tags(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['message' => 'not found'], 404)
                : Http::response(['data' => ['id' => 'c_1', 'status' => 'active']], 200);
        });

        $this->runJob($this->payload());

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/v1/contacts')
                && data_get($body, 'data.status') === 'active'
                && data_get($body, 'data.email') === 'jane@example.com'
                && data_get($body, 'data.first_name') === 'Jane'
                && data_get($body, 'data.data.room_interest') === 'Sea View'
                && data_get($body, 'data.data.source') === 'kosaktis-footer'
                && data_get($body, 'data.data.consent_ip') === '203.0.113.9'
                && data_get($body, 'data.data.consent_at') === '2026-06-21T10:00:00+00:00'
                && data_get($body, 'data.data.consent_source') === 'newsletter'
                && in_array('newsletter', data_get($body, 'data.data.tags'), true)
                && in_array('kosaktis-website', data_get($body, 'data.data.tags'), true);
        });
    }

    public function test_existing_active_contact_unions_tags_without_clobbering_data(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => [
                    'id' => 'c_1',
                    'status' => 'active',
                    'data' => [
                        'tags' => ['existing-tag'],
                        'city' => 'Kos',
                        'consent_at' => '2020-01-01T00:00:00+00:00',
                        'consent_ip' => '198.51.100.1',
                    ],
                ]], 200)
                : Http::response(['data' => ['id' => 'c_1', 'status' => 'active']], 200);
        });

        $this->runJob($this->payload());

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $tags = data_get($request->data(), 'data.data.tags');

            return str_contains($request->url(), 'id_type=email')
                && data_get($request->data(), 'data.status') === 'active'
                && in_array('existing-tag', $tags, true)
                && in_array('newsletter', $tags, true)
                && in_array('kosaktis-website', $tags, true)
                && data_get($request->data(), 'data.data.city') === 'Kos'          // preserved
                && data_get($request->data(), 'data.data.room_interest') === 'Sea View'
                // The original proof of consent is preserved, never overwritten
                // by the re-submit's fresher consent_at/consent_ip.
                && data_get($request->data(), 'data.data.consent_at') === '2020-01-01T00:00:00+00:00'
                && data_get($request->data(), 'data.data.consent_ip') === '198.51.100.1'
                // consent_source was absent on the existing contact, so it's
                // backfilled with the form handle.
                && data_get($request->data(), 'data.data.consent_source') === 'newsletter';
        });
    }

    public function test_unsubscribed_contact_is_not_reactivated_by_a_bare_submit(): void
    {
        // A previous unsubscribe is an explicit withdrawal of consent; a public
        // form submit must not silently flip it back to active (a third party
        // could enter someone else's address). Tags/data are still refreshed.
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => ['id' => 'c_1', 'status' => 'unsubscribed', 'data' => []]], 200)
                : Http::response(['data' => ['id' => 'c_1', 'status' => 'unsubscribed']], 200);
        });

        $this->runJob($this->payload());

        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            // Status must be omitted entirely so Keila leaves it unsubscribed,
            // but tags/data are still refreshed (the update isn't skipped).
            return ! array_key_exists('status', (array) data_get($request->data(), 'data'))
                && in_array('newsletter', (array) data_get($request->data(), 'data.data.tags'), true)
                && data_get($request->data(), 'data.data.room_interest') === 'Sea View';
        });
    }

    public function test_unreachable_contact_is_not_reactivated(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response(['data' => ['id' => 'c_1', 'status' => 'unreachable', 'data' => []]], 200)
                : Http::response(['data' => ['id' => 'c_1', 'status' => 'unreachable']], 200);
        });

        $this->runJob($this->payload());

        // The contact is still updated (tags/data), but we must NOT flip status.
        Http::assertSent(function (Request $request) {
            if ($request->method() !== 'PUT') {
                return false;
            }

            return ! array_key_exists('status', (array) data_get($request->data(), 'data'));
        });
    }

    public function test_server_error_on_lookup_throws_for_retry(): void
    {
        Http::fake(fn () => Http::response('boom', 500));

        $this->expectException(KeilaTransientException::class);

        $this->runJob($this->payload());
    }

    public function test_server_error_on_create_throws_for_retry(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response('', 404)
                : Http::response('boom', 503);
        });

        $this->expectException(KeilaTransientException::class);

        $this->runJob($this->payload());
    }

    public function test_unauthorized_is_dropped_without_retry(): void
    {
        Http::fake(fn () => Http::response(['error' => 'unauthorized'], 401));

        // Permanent error: must NOT throw (no retry), and must not attempt a write.
        $this->runJob($this->payload());

        Http::assertSent(fn (Request $request) => $request->method() === 'GET');
        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT'], true));
    }

    public function test_create_conflict_falls_back_to_update(): void
    {
        $lookups = 0;

        Http::fake(function (Request $request) use (&$lookups) {
            if ($request->method() === 'GET') {
                $lookups++;

                // 1st lookup misses; after the create conflict we re-read and find it.
                return $lookups === 1
                    ? Http::response('', 404)
                    : Http::response(['data' => ['id' => 'c_1', 'status' => 'active', 'data' => ['tags' => ['old']]]], 200);
            }

            if ($request->method() === 'POST') {
                // Real Keila returns a 400 changeset error for a duplicate email,
                // never 409/422. See KeilaClient::isAlreadyExists().
                return Http::response([
                    'errors' => [['status' => '400', 'title' => 'Validation failed', 'detail' => 'has already been taken']],
                ], 400);
            }

            return Http::response(['data' => ['id' => 'c_1', 'status' => 'active']], 200);
        });

        $this->runJob($this->payload());

        Http::assertSent(fn (Request $request) => $request->method() === 'POST');
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && in_array('old', data_get($request->data(), 'data.data.tags'), true));
    }

    public function test_missing_credentials_is_a_noop(): void
    {
        config()->set('statamic-keila-integration.url', null);
        config()->set('statamic-keila-integration.token', null);

        Http::fake();

        $this->runJob($this->payload());

        Http::assertNothingSent();
    }

    public function test_the_email_is_masked_in_logs(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'GET'
                ? Http::response('', 404)
                : Http::response(['data' => ['id' => 'c_1', 'status' => 'active']], 200);
        });

        Log::spy();

        $this->runJob($this->payload());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            return ($context['email'] ?? null) === 'j***@example.com'
                && ! str_contains(json_encode($context), 'jane@example.com');
        });
    }
}
