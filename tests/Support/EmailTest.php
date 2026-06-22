<?php

namespace Reachweb\StatamicKeilaIntegration\Tests\Support;

use Reachweb\StatamicKeilaIntegration\Support\Email;
use Reachweb\StatamicKeilaIntegration\Tests\TestCase;

class EmailTest extends TestCase
{
    public function test_masks_a_normal_address_keeping_first_char_and_domain(): void
    {
        $this->assertSame('j***@example.com', Email::mask('jane@example.com'));
    }

    public function test_mask_width_tracks_the_local_part_length(): void
    {
        // 5-char local -> first char + 4 stars (not a fixed-width "***").
        $this->assertSame('j****@example.com', Email::mask('janes@example.com'));
    }

    public function test_masks_a_two_char_local_part(): void
    {
        $this->assertSame('a*@example.com', Email::mask('ab@example.com'));
    }

    public function test_fully_masks_a_single_char_local_part(): void
    {
        // A 1-char local part must be redacted, not left exposed as "a***".
        $this->assertSame('*@example.com', Email::mask('a@example.com'));
    }

    public function test_masks_an_empty_local_part(): void
    {
        $this->assertSame('*@example.com', Email::mask('@example.com'));
    }

    public function test_blank_and_null_return_the_constant(): void
    {
        $this->assertSame('***', Email::mask(null));
        $this->assertSame('***', Email::mask(''));
        $this->assertSame('***', Email::mask('   '));
    }

    public function test_a_value_without_an_at_sign_returns_the_constant(): void
    {
        $this->assertSame('***', Email::mask('not-an-email'));
    }

    public function test_handles_multibyte_local_parts_by_character_not_byte(): void
    {
        // "ürgen" is 5 characters; the first (multibyte) char is kept intact.
        $this->assertSame('ü****@example.com', Email::mask('ürgen@example.com'));
    }
}
