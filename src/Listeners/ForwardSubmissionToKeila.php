<?php

namespace Reachweb\StatamicKeilaIntegration\Listeners;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Reachweb\StatamicKeilaIntegration\Jobs\SyncContactToKeila;
use Statamic\Events\SubmissionCreated;
use Throwable;

class ForwardSubmissionToKeila
{
    /**
     * Keila's first-class (top-level) contact fields. Everything else must be
     * mapped into the nested custom-data object with a `data.*` target.
     *
     * @var array<int, string>
     */
    protected array $topLevelFields = ['email', 'first_name', 'last_name', 'external_id'];

    public function handle(SubmissionCreated $event): void
    {
        // This runs synchronously inside Submission::save(), which is dispatched
        // OUTSIDE the form controller's try/catch. Anything that escapes here
        // would 500 the visitor and skip the native notification emails, so we
        // swallow everything — the addon is a non-interfering side effect.
        try {
            $this->process($event->submission);
        } catch (Throwable $e) {
            Log::error('[keila] listener error (swallowed to protect submission)', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function process(mixed $submission): void
    {
        $handle = $submission->form()->handle();

        $config = Arr::get(config('statamic-keila-integration.forms', []), $handle);

        if (! is_array($config)) {
            return; // Form isn't mapped — nothing to do.
        }

        $values = collect($submission->data())->all();

        if (! $this->optInAccepted($config['opt_in_field'] ?? null, $values)) {
            return;
        }

        if (blank(config('statamic-keila-integration.url')) || blank(config('statamic-keila-integration.token'))) {
            $this->warnOnce('credentials', '[keila] KEILA_URL / KEILA_API_TOKEN are not set; skipping contact sync.');

            return;
        }

        [$top, $data, $email] = $this->mapFields((array) ($config['field_map'] ?? []), $values);

        if (blank($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->warnOnce(
                "email:{$handle}",
                "[keila] No valid email resolved for form [{$handle}]; check its field_map.",
            );

            return;
        }

        // afterResponse(): under `sync` this runs once the response is flushed
        // and the native emails have sent, so a slow/failing Keila can never
        // delay or break the visitor's submission. Under a real queue it's
        // enqueued as normal and the worker handles retries.
        SyncContactToKeila::dispatch([
            'email' => $email,
            'top' => $top,
            'data' => $data,
            'tags' => array_values((array) ($config['tags'] ?? [])),
            'source' => $config['source'] ?? null,
            'form' => $handle,
        ])->afterResponse();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function optInAccepted(?string $field, array $values): bool
    {
        if (! $field || ! array_key_exists($field, $values)) {
            return false;
        }

        return Validator::make([$field => $values[$field]], [$field => 'accepted'])->passes();
    }

    /**
     * Resolve the configured field_map against the submission values.
     *
     * @param  array<string, string>  $fieldMap
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: ?string}
     */
    protected function mapFields(array $fieldMap, array $values): array
    {
        $top = [];
        $data = [];
        $email = null;

        foreach ($fieldMap as $source => $target) {
            if (! array_key_exists($source, $values)) {
                continue;
            }

            $value = $values[$source];

            if (blank($value)) {
                continue;
            }

            if ($target === 'email') {
                $email = is_string($value) ? trim($value) : $value;
                $top['email'] = $email;
            } elseif (in_array($target, $this->topLevelFields, true)) {
                $top[$target] = $value;
            } elseif (Str::startsWith($target, 'data.')) {
                Arr::set($data, Str::after($target, 'data.'), $value);
            } else {
                Log::warning("[keila] Unknown field_map target [{$target}]; skipping.");
            }
        }

        return [$top, $data, $email];
    }

    protected function warnOnce(string $key, string $message): void
    {
        if (Cache::add('keila:warn:'.md5($key), true, now()->addHour())) {
            Log::warning($message);
        }
    }
}
