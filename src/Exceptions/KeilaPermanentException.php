<?php

namespace Reachweb\StatamicKeilaIntegration\Exceptions;

use RuntimeException;

/**
 * A non-retryable failure (4xx such as 401 / 403 / 422). Retrying won't help,
 * so the job logs and drops instead of throwing.
 */
class KeilaPermanentException extends RuntimeException {}
