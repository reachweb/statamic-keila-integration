<?php

namespace Reachweb\StatamicKeilaIntegration\Support;

class Email
{
    /**
     * Mask an email for logging, e.g. "jane@example.com" -> "j***@example.com".
     */
    public static function mask(?string $email): string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        $first = mb_substr($local, 0, 1);

        return $first.'***@'.$domain;
    }
}
