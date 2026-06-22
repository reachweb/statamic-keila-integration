<?php

namespace Reachweb\StatamicKeilaIntegration\Exceptions;

use RuntimeException;

/**
 * A non-retryable failure (a 4xx such as 400 / 403). Retrying won't help, so
 * the job logs and drops instead of throwing.
 */
class KeilaPermanentException extends RuntimeException {}
