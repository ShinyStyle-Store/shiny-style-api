<?php

namespace Tests\Unit\Support;

use App\Support\AdminPasswordPolicy;
use PHPUnit\Framework\TestCase;

class AdminPasswordPolicyTest extends TestCase
{
    public function test_nine_characters_are_rejected(): void
    {
        $this->assertNotEmpty(AdminPasswordPolicy::violations('Aa1!aaaaa'));
    }

    public function test_valid_ten_character_password_is_accepted(): void
    {
        $this->assertSame([], AdminPasswordPolicy::violations('Aa1!aaaaaa'));
    }

    public function test_mixed_case_is_required(): void
    {
        $this->assertContains('Password must contain mixed case.', AdminPasswordPolicy::violations('aa1!aaaaaaa'));
    }

    public function test_number_is_required(): void
    {
        $this->assertContains('Password must contain numbers.', AdminPasswordPolicy::violations('Aa!aaaaaaa'));
    }

    public function test_symbol_is_required(): void
    {
        $this->assertContains('Password must contain symbols.', AdminPasswordPolicy::violations('Aa1aaaaaaa'));
    }
}
