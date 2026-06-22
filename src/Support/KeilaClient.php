<?php

namespace Reachweb\StatamicKeilaIntegration\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaContactExistsException;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaPermanentException;
use Reachweb\StatamicKeilaIntegration\Exceptions\KeilaTransientException;

class KeilaClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $token,
        protected int $timeout = 10,
    ) {}

    /**
     * Build a client from config, or null when credentials are missing.
     */
    public static function fromConfig(): ?self
    {
        $url = config('statamic-keila-integration.url');
        $token = config('statamic-keila-integration.token');

        if (blank($url) || blank($token)) {
            return null;
        }

        return new self(
            rtrim((string) $url, '/'),
            (string) $token,
            (int) config('statamic-keila-integration.http.timeout', 10),
        );
    }

    /**
     * Look up a contact by email. Returns the (unwrapped) contact, or null for 404.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $email): ?array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->get(
            'contacts/'.rawurlencode($email),
            ['id_type' => 'email'],
        ), 'lookup');

        if ($response->status() === 404) {
            return null;
        }

        $this->throwForStatus($response, 'lookup');

        return $this->unwrap($response);
    }

    /**
     * Create a contact. Body is wrapped in Keila's top-level `data` envelope.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function create(array $attributes): array
    {
        $response = $this->send(
            fn (PendingRequest $request) => $request->post('contacts', ['data' => $attributes]),
            'create',
        );

        if ($this->isAlreadyExists($response)) {
            throw new KeilaContactExistsException('Contact already exists');
        }

        $this->throwForStatus($response, 'create');

        return $this->unwrap($response);
    }

    /**
     * Update (or reactivate) a contact by email.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function update(string $email, array $attributes): array
    {
        $response = $this->send(fn (PendingRequest $request) => $request->put(
            'contacts/'.rawurlencode($email).'?id_type=email',
            ['data' => $attributes],
        ), 'update');

        $this->throwForStatus($response, 'update');

        return $this->unwrap($response);
    }

    /**
     * @param  callable(PendingRequest): Response  $callback
     */
    protected function send(callable $callback, string $operation): Response
    {
        try {
            return $callback($this->request());
        } catch (ConnectionException $e) {
            throw new KeilaTransientException("Keila {$operation} connection error: {$e->getMessage()}", previous: $e);
        }
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl.'/api/v1')
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->timeout)
            ->timeout($this->timeout);
    }

    /**
     * A create conflict (email already taken) surfaces as an HTTP 400 changeset
     * error whose detail mentions the email ("has already been taken"). Keila
     * routes all contact validation errors through a 400, so we also tolerate
     * 409/422 defensively. The substring guard separates a duplicate-email 400
     * from other 400s (malformed body, oversized custom data, invalid email),
     * which must still fall through to throwForStatus() as permanent.
     */
    protected function isAlreadyExists(Response $response): bool
    {
        if (! in_array($response->status(), [400, 409, 422], true)) {
            return false;
        }

        $body = Str::lower($response->body());

        return Str::contains($body, ['has already been taken', 'already exists', 'already taken']);
    }

    protected function throwForStatus(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        if ($status >= 500 || in_array($status, [408, 429], true)) {
            throw new KeilaTransientException("Keila {$operation} failed with status {$status}");
        }

        throw new KeilaPermanentException("Keila {$operation} failed with status {$status}");
    }

    /**
     * Unwrap Keila's top-level `data` response envelope.
     *
     * @return array<string, mixed>
     */
    protected function unwrap(Response $response): array
    {
        $json = $response->json();

        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return is_array($json) ? $json : [];
    }
}
