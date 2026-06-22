<?php

namespace Reachweb\StatamicKeilaIntegration\Support;

class Email
{
    /**
     * Mask an email for logging, e.g. "jane@example.com" -> "j***@example.com".
     *
     * Masks the local part proportionally ("a@x.com" -> "*@x.com", not "a***@x.com") so short addresses aren't left effectively unredacted; the domain stays visible for log correlation.
     */
    public static function mask(?string $email): string
    {
        if (blank($email) || ! str_contains($email, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $email, 2);

        $length = mb_strlen($local);

        $masked = match (true) {
            $length <= 1 => '*',
            $length === 2 => mb_substr($local, 0, 1).'*',
            default => mb_substr($local, 0, 1).str_repeat('*', $length - 1),
        };

        return $masked.'@'.$domain;
    }
}
