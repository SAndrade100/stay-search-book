<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receives_a_token(): void 
    {
        $response  = $this->postJson('/api/v1/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']])
            ->assertJsonMissingPath('data.user.password');

        $user = User::firstWhere('email', 'ada@example.com');
        $this->assertNotNull($user);
        $this->assertNotSame('secret-password', $user->password, 'Password must be stored hashed.');
    }

    public function test_registration_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_a_user_can_log_in_and_use_the_token(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $token = $this->postJson('/api/v1/login', [
            'email' => 'ada@example.com',
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->json('data.token');

        $this->withToken($token)->getJson('/api/v1/books')->assertOk();
    }

    public function test_wrong_password_and_unknown_email_get_the_same_generic_error(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $wrongPassword = $this->postJson('/api/v1/login', ['email' => 'ada@example.com', 'password' => 'nope']);
        $unknownEmail = $this->postJson('/api/v1/login', ['email' => 'ghost@example.com', 'password' => 'nope']);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();
        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_an_unknown_email_still_costs_one_password_hash_comparison(): void
    {
        $hasher = Hash::spy();

        $this->postJson('/api/v1/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
            ->assertUnauthorized();

        $hasher->shouldHaveReceived('check')->once();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $credentials = ['email' => 'ada@example.com', 'password' => 'wrong'];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', $credentials)->assertUnauthorized();
        }

        $this->postJson('/api/v1/login', $credentials)->assertTooManyRequests();
    }

    public function test_logout_revokes_only_the_token_that_was_used(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('current')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;

        $this->withToken($current)->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out.']);

        $this->app['auth']->forgetGuards();

        $this->withToken($current)->getJson('/api/v1/books')->assertUnauthorized();
        $this->withToken($other)->getJson('/api/v1/books')->assertOk();
    }


    public function test_a_user_keeps_at_most_five_active_tokens(): void
    {
        $user = User::factory()->create();

        $tokens = collect(range(1, User::MAX_API_TOKENS + 1))->map(fn () => $user->issueApiToken());

        $this->assertSame(User::MAX_API_TOKENS, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens->first())->getJson('/api/v1/books')->assertUnauthorized();
        $this->withToken($tokens->last())->getJson('/api/v1/books')->assertOk();
    }

    public function test_tokens_expire(): void
    {
        $token = User::factory()->create()->issueApiToken();

        $this->withToken($token)->getJson('/api/v1/books')->assertOk();

        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/books')->assertUnauthorized();
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/v1/register', [])->assertTooManyRequests();
    }

    public function test_protected_routes_answer_json_401_even_without_an_accept_header(): void
    {
        $response = $this->get('/api/v1/books');

        $response->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_unknown_routes_answer_json_404(): void
    {
        $this->get('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }
}
