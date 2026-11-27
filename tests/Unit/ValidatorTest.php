<?php
declare(strict_types=1);

namespace AnaBot\Tests\Unit;

use AnaBot\Core\Validator;

class ValidatorTest
{
    public function run(): void
    {
        echo "Testing Validator..." . PHP_EOL;

        // Test 1: Required & Email
        $v1 = Validator::make([
            'email' => 'invalid-email',
            'username' => '',
        ], [
            'email' => 'required|email',
            'username' => 'required',
        ]);
        assert($v1->fails(), 'Validator must fail on invalid email and empty username');
        assert(isset($v1->errors()['email']), 'Email must have validation error');
        assert(isset($v1->errors()['username']), 'Username must have validation error');
        echo "  [PASS] Required and email validation rules" . PHP_EOL;

        // Test 2: Min & Max & In
        $v2 = Validator::make([
            'age' => 25,
            'status' => 'active',
            'password' => 'secret123',
        ], [
            'age' => 'numeric|min:18|max:65',
            'status' => 'in:active,pending,archived',
            'password' => 'min:8',
        ]);
        assert($v2->passes(), 'Validator must pass valid values');
        echo "  [PASS] Numeric, Min, Max, In rules" . PHP_EOL;

        // Test 3: Same rule (e.g. password confirmation)
        $v3 = Validator::make([
            'password' => 'mypassword',
            'password_confirmation' => 'mypassword',
        ], [
            'password' => 'required',
            'password_confirmation' => 'same:password',
        ]);
        assert($v3->passes(), 'Same rule must pass when values match');
        echo "  [PASS] Same field matching rule" . PHP_EOL;
    }
}
