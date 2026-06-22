<?php

namespace Reachweb\StatamicKeilaIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaContactExistsException;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaPermanentException;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaTransientException;
use Reachweb\StatamicKeilaIntegration\Support\Email;
use Reachweb\StatamicKeilaIntegration\Support\KeilaClient;
use Throwable;

class SyncContactToKeila implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    /**
     * @param  array{
     *     email: string,
     *     top: array<string, mixed>,
     *     data: array<string, mixed>,
     *     tags: array<int, string>,
     *     source: ?string,
     *     form: string,
     *     consent_ip?: ?string,
     *     consent_at?: ?string,
     * }  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * Retry backoff in seconds. With $tries = 3 only two retries ever run, so
     * the array is kept at $tries − 1 entries (a third would be dead).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        if (! $client = KeilaClient::fromConfig()) {
            // Credentials disappeared between dispatch and run; the listener
            // already warned. No-op rather than fail.
            return;
        }

        try {
            $existing = $client->find($this->payload['email']);

            $existing === null
                ? $this->create($client)
                : $this->update($client, $existing);
        } catch (KeilaTransientException $e) {
            // Bubble so the queue retries.
            throw $e;
        } catch (KeilaPermanentException $e) {
            Log::error('[keila] sync dropped (permanent error)', $this->context($e));
        }
    }

    protected function create(KeilaClient $client): void
    {
        try {
            $client->create($this->attributes(existing: null, status: 'active'));

            Log::info('[keila] contact created', $this->context());
        } catch (KeilaContactExistsException) {
            // Lost the lookup/create race: the contact appeared in between.
            // Re-read and update instead (upsert).
            $existing = $client->find($this->payload['email']) ?? [];

            $this->update($client, $existing);
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    protected function update(KeilaClient $client, array $existing): void
    {
        $status = $existing['status'] ?? 'active';

        // Never let a bare form submit flip a non-active contact back to active.
        // An opt-in toggle only proves the submitter ticked a box, not that they
        // own the address — so neither a hard-bounced (unreachable) contact nor
        // one that previously unsubscribed (an explicit withdrawal of consent)
        // is resurrected here. We still refresh their tags/data; status is left
        // untouched (passing null omits it from the update). Reactivating an
        // unsubscribe must go through a real confirmation step, not this path.
        $newStatus = in_array($status, ['unreachable', 'unsubscribed'], true) ? null : 'active';

        $client->update($this->payload['email'], $this->attributes($existing, $newStatus));

        $outcome = match ($status) {
            'unreachable' => 'skipped-unreachable',
            'unsubscribed' => 'skipped-unsubscribed',
            default => 'updated',
        };

        Log::info("[keila] contact {$outcome}", $this->context());
    }

    /**
     * Build the contact attributes, merging onto the existing contact so we
     * never clobber custom data and never blank out top-level fields.
     *
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    protected function attributes(?array $existing, ?string $status): array
    {
        $existingData = is_array($existing['data'] ?? null) ? $existing['data'] : [];

        // Tag union: existing ++ configured, de-duped, order preserving.
        $existingTags = array_values(array_filter((array) ($existingData['tags'] ?? []), 'is_string'));
        $tags = array_values(array_unique([...$existingTags, ...$this->payload['tags']]));

        // Whole existing custom-data map carried over, then our fields merged in.
        $data = array_merge($existingData, $this->payload['data']);
        $data['tags'] = $tags;

        if (! blank($this->payload['source'] ?? null)) {
            $data['source'] = $this->payload['source'];
        }

        // Consent audit trail (GDPR/CAN-SPAM proof of consent). Record it the
        // first time we see this contact and preserve the original on every
        // re-submit, so a later (possibly third-party) submission can never
        // overwrite the legally meaningful first record. The array_merge above
        // already carried any existing consent_* across, so `??=` only fills the
        // gaps on a genuinely new contact.
        if (! blank($this->payload['consent_at'] ?? null)) {
            $data['consent_at'] ??= $this->payload['consent_at'];
        }

        if (! blank($this->payload['consent_ip'] ?? null)) {
            $data['consent_ip'] ??= $this->payload['consent_ip'];
        }

        $data['consent_source'] ??= $this->payload['form'];

        // top already contains only non-empty mapped top-level fields.
        $attributes = $this->payload['top'];
        $attributes['data'] = $data;

        if ($status !== null) {
            $attributes['status'] = $status;
        }

        return $attributes;
    }

    public function failed(?Throwable $e): void
    {
        Log::error('[keila] sync permanently failed after retries', $this->context($e));
    }

    /**
     * @return array<string, mixed>
     */
    protected function context(?Throwable $e = null): array
    {
        $context = [
            'form' => $this->payload['form'] ?? null,
            'email' => Email::mask($this->payload['email'] ?? null),
        ];

        if ($e) {
            $context['error'] = $e->getMessage();
        }

        return $context;
    }
}
