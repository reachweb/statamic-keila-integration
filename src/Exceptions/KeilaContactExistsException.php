<?php

namespace Reachweb\StatamicKeilaIntegration\Exceptions;

/**
 * Raised when a create fails because the email already exists (the lookup/create
 * race). The job catches this and falls back to an update — an upsert.
 */
class KeilaContactExistsException extends KeilaPermanentException {}
