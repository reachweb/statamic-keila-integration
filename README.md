# Statamic Keila Integration

Subscribe Statamic form submitters to a self-hosted [Keila](https://www.keila.io) newsletter instance via Keila's HTTP API.

When a mapped form is submitted with its opt-in toggle accepted, the addon creates a new Keila contact, updates an existing one, or reactivates a previously unsubscribed one — and tags it so it falls into a Keila segment. It is a **non-interfering side effect**: the native Statamic submission (storage + notification emails) always completes unchanged, regardless of what happens with Keila.

## Requirements

- Statamic 6, Laravel 11+, PHP 8.2+
- A Keila instance running **≥ v0.17.0** (the contacts API needs `id_type=email` lookups, added in 0.17.0)

## Installation

```bash
composer require reachweb/statamic-keila-integration
```

Publish the config:

```bash
php artisan vendor:publish --tag=statamic-keila-integration-config
```

Add your credentials to `.env`:

```dotenv
KEILA_URL=https://news.example.com
KEILA_API_TOKEN=
```

The token identifies the Keila **project** that contacts are written to (one token = one project). Forms differentiate via tags, not separate projects.

## Configuration

Edit `config/statamic-keila-integration.php` and map each Statamic form handle you want forwarded:

```php
'forms' => [
    'newsletter' => [
        'opt_in_field' => 'newsletter_opt_in',          // a TOGGLE field; must pass Laravel 'accepted'
        'tags'         => ['newsletter', 'website'],     // -> data.tags, drives a Keila segment
        'source'       => 'website-footer',              // optional -> data.source
        'field_map'    => [
            // statamic field handle => keila target
            'email'         => 'email',
            'first_name'    => 'first_name',
            'last_name'     => 'last_name',
            'room_interest' => 'data.room_interest',      // arbitrary field -> nested custom data
        ],
    ],
],
```

### How mapping works

`field_map` is **explicit** — every Keila field you want populated, including `email`, must be listed. There are no conventional defaults.

- A target of `email`, `first_name`, `last_name`, or `external_id` maps to that **top-level** Keila contact field.
- A target starting with `data.` maps into the contact's **nested custom-data** object (dot paths may nest, e.g. `data.preferences.room`).
- A form whose `field_map` resolves no valid `email` is skipped (with a logged warning).

### The opt-in field

The opt-in field **must be a toggle** (or any field whose submitted value passes Laravel's [`accepted`](https://laravel.com/docs/validation#rule-accepted) rule: `true`, `"1"`, `"on"`, `"yes"`). Add it to your form blueprint:

```yaml
-
  handle: newsletter_opt_in
  field:
    type: toggle
    display: 'Subscribe to our newsletter'
```

If the toggle is off (or absent), nothing is sent to Keila.

### The Keila segment

Tags are stored on each contact under custom data as `data.tags` (an array). Keila has no native "tags" field — create a **segment** in Keila that filters contacts where `data.tags` contains your tag (e.g. `newsletter`), and target your campaigns at that segment.

## Behaviour

On an accepted submission, a queued job:

1. Looks the contact up by email.
2. Builds the contact's custom data by **merging onto whatever already exists** — the tag list becomes the union of existing + configured tags, and other custom fields are never clobbered.
3. Then:
   - **New** contact → created with `status: active`.
   - **Active / unsubscribed** contact → updated; status set to `active` (a fresh opt-in re-subscribes an unsubscribed contact).
   - **Unreachable** contact (hard bounce) → tags/data updated, but status is **left as-is** — the addon will not resurrect a bounced address.

### Queue & reliability

The job implements `ShouldQueue` and respects your app's `QUEUE_CONNECTION`:

- With a real queue, the sync is deferred to a worker and retried (3 tries, exponential backoff) on 5xx / 429 / timeout failures. Permanent errors (401/422) are logged and dropped.
- With `QUEUE_CONNECTION=sync`, it runs **after the HTTP response is sent**, so a slow or failing Keila never delays or breaks the visitor's submission.

Emails are masked in logs (`j***@example.com`). Errors are never surfaced to the site visitor.

## Frontend: smooth (AJAX) submissions

This addon handles the server side — it does not render your form. It does, however, ship an optional **publishable [Alpine](https://alpinejs.dev) partial** that submits the native Statamic form with `fetch` for inline, no-reload feedback, with a no-JS fallback (a normal POST + `{{ if success }}`).

### Ready-made partial

Publish it:

```bash
php artisan vendor:publish --tag=statamic-keila-integration-views
```

That copies `newsletter.antlers.html` to `resources/views/vendor/statamic-keila-integration/`. Include it anywhere — e.g. your footer:

```antlers
{{ partial:statamic-keila-integration::newsletter }}
```

It is self-contained: the Alpine component lives inline in `x-data`, so there's **no JS import or build step** — Alpine (already on your page) picks it up. It's also toast-aware but not toast-dependent — on success it optionally calls `$store.toasts?.push(...)` and dispatches a `newsletter:subscribed` event, both degrading gracefully when absent.

> You can include it **without** publishing — the addon auto-namespaces its views, so the same `{{ partial:statamic-keila-integration::newsletter }}` resolves to the packaged copy. Publishing just gives you an editable copy that takes precedence.

Then customise the published copy:

- **Styling** — the defaults use generic, portable Tailwind core utilities. Restyle freely with your own classes.
- **Copy** — strings are hardcoded English run through the `| trans` modifier; translate them via your lang files, or swap in your own strings / globals.
- **Fields** — match the form handle (`form:newsletter`) and the opt-in field `name` (`newsletter_opt_in`) to your own form blueprint.

### Or build your own

The partial relies on Statamic returning JSON when the form is submitted with an AJAX header:

- success → `200 {"success": true, "redirect": …}`
- validation error → `400 {"error": {"<field>": "<message>"}, "errors": [...]}`
- detection → send the `X-Requested-With: XMLHttpRequest` header.

A minimal progressive enhancement (the same idea as the shipped partial, distilled):

```html
<div x-data="{ state: 'idle', message: '' }"
     x-on:submit.prevent="
       state = 'loading';
       const form = $event.target;
       const res = await fetch(form.action, {
         method: 'POST',
         headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
         body: new FormData(form),
       }).catch(() => null);
       const data = res && await res.json().catch(() => ({}));
       if (res?.ok && data?.success) { state = 'success'; form.reset(); }
       else { state = 'error'; message = (data?.error && Object.values(data.error)[0]) || 'Please try again.'; }
     ">
  {{ form:newsletter }}
    <div x-show="state !== 'success'"> … inputs + opt-in toggle … </div>
    <p x-show="state === 'success'" x-cloak>You're subscribed!</p>
  {{ /form:newsletter }}
</div>
```

Notes:

- **Copy:** this addon is **single opt-in** (the contact is set `active` immediately, no confirmation email), so say "You're subscribed!" — not "check your inbox to confirm."
- **Static caching:** if you run Statamic's static caching, the form's `_token` must stay fresh. Submitting `new FormData(form)` picks up the token Statamic keeps live via its nocache layer — but test a real submit on a cached page (a stale token returns `419`).

## Spam protection

Keila skips CAPTCHA for API calls, so keep spam protection on the form itself (honeypot / Cloudflare Turnstile). That is out of scope for this addon.

## Testing

```bash
composer install
vendor/bin/phpunit
```
