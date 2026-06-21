<?php

namespace Reachweb\StatamicKeilaIntegration\Exceptions;

use RuntimeException;

/**
 * A retryable failure (5xx / 429 / 408 / connection / timeout). The job lets
 * this bubble so the queue retries according to $tries / backoff().
 */
class KeilaTransientException extends RuntimeException {}
