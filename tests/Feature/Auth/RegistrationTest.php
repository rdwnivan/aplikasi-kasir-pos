<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Accounts come with a fixed role (owner / gudang / kasir), so nobody may sign themselves
 * up: the public demo would otherwise collect role-less accounts.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_registration_screen()
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_sign_up()
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseMissing(User::class, ['email' => 'test@example.com']);
    }
}
