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
     * }  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
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

        // Never resurrect a bounced/unreachable contact — only flip to active
        // for genuine opt-ins (new, active, or a re-consenting unsubscribe).
        $newStatus = $status === 'unreachable' ? null : 'active';

        $client->update($this->payload['email'], $this->attributes($existing, $newStatus));

        $outcome = match ($status) {
            'unreachable' => 'skipped-unreachable',
            'unsubscribed' => 'reactivated',
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
