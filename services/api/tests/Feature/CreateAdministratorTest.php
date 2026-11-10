<?php

namespace Tests\Feature;

use App\Console\Commands\CreateAdministrator;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class CreateAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_stdin_create_only_provisions_without_printing_password(): void
    {
        $existing = User::factory()->subscriber()->create();
        $original = $existing->fresh()->getRawOriginal();
        [$status, $output] = $this->provision('dedicated@hyperline.test', 'Isolated-Fixture-Password!2026');
        $this->assertSame(0, $status);
        $this->assertStringNotContainsString('Isolated-Fixture-Password!2026', $output);
        $admin = User::where('email', 'dedicated@hyperline.test')->firstOrFail();
        $this->assertTrue(Hash::check('Isolated-Fixture-Password!2026', $admin->password));
        $this->assertTrue($admin->hasRole(Role::ADMIN));
        $this->assertTrue($original === $existing->fresh()->getRawOriginal());
    }

    public function test_create_only_refuses_existing_user_case_insensitively(): void
    {
        $user = User::factory()->subscriber()->create(['email' => 'protected@hyperline.test']);
        $original = $user->fresh()->getRawOriginal();
        [$status] = $this->provision('PROTECTED@hyperline.test', 'Replacement-Fixture!2026');
        $this->assertSame(1, $status);
        $this->assertTrue($original === $user->fresh()->getRawOriginal());
        $this->assertFalse($user->fresh()->hasRole(Role::ADMIN));
        $this->assertSame(1, User::count());
    }

    public function test_empty_stdin_never_falls_back_to_bootstrap_secret(): void
    {
        config(['ott.admin.initial_password' => 'Bootstrap-Fixture!2026']);
        [$status] = $this->provision('missing@hyperline.test', '');
        $this->assertSame(1, $status);
        $this->assertSame(0, User::count());
    }

    public function test_legacy_command_still_updates_only_target_account(): void
    {
        $user = User::factory()->subscriber()->create(['email' => 'legacy@hyperline.test']);
        $other = User::factory()->create(); $original = $other->fresh()->getRawOriginal();
        $this->artisan('hyperline:admin', ['email' => $user->email, '--password' => 'Legacy-Fixture-Password!2026'])->assertExitCode(0);
        $this->assertTrue($user->fresh()->hasRole(Role::ADMIN));
        $this->assertTrue($original === $other->fresh()->getRawOriginal());
    }

    public function test_conflicting_password_channels_are_refused_before_creation(): void
    {
        $this->artisan('hyperline:admin', ['email' => 'conflict@hyperline.test', '--password-stdin' => true, '--password' => 'Isolated-Fixture-Password!2026'])
            ->expectsOutput('Use either standard input or the password option, never both.')
            ->assertExitCode(1);
        $this->assertSame(0, User::count());
    }

    private function provision(string $email, string $password): array
    {
        $command = app(CreateAdministrator::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['email' => $email, '--create-only' => true, '--password-stdin' => true], $command->getDefinition());
        $stream = fopen('php://memory', 'r+'); fwrite($stream, $password."\n"); rewind($stream); $input->setStream($stream);
        $output = new BufferedOutput();
        try { return [$command->run($input, $output), $output->fetch()]; }
        finally { fclose($stream); }
    }
}
