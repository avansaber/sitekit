<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_registration_is_disabled(): void
    {
        $this->assertFalse(Features::enabled(Features::registration()));
        $this->get('/app/register')->assertRedirect('/app/login');
        $this->get('/register')->assertRedirect('/app/login');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_operator_can_only_be_created_from_trusted_command(): void
    {
        $this->artisan('sitekit:make-user', [
            '--name' => 'SiteKit Operator',
            '--email' => 'operator@example.com',
            '--password' => 'long-password-123',
        ])->assertSuccessful();

        $user = \App\Models\User::sole();

        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(1, $user->ownedTeams()->count());
        $this->assertTrue($user->currentTeam->personal_team);

        $this->artisan('sitekit:make-user', [
            '--name' => 'Second User',
            '--email' => 'second@example.com',
            '--password' => 'long-password-456',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_operator_must_enable_two_factor_authentication_before_using_panel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $user->switchTeam($user->ownedTeams()->sole());
        $this->actingAs($user);

        $dashboard = \Filament\Pages\Dashboard::getUrl(panel: 'app', tenant: $user->currentTeam);
        $profile = \App\Filament\Pages\EditProfile::getUrl(panel: 'app', tenant: $user->currentTeam);

        $this->get($dashboard)->assertRedirect($profile);
        $this->get($profile)->assertOk();

        $user->forceFill(['two_factor_secret' => encrypt('test-secret')])->save();
        $this->get($dashboard)->assertRedirect($profile);

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->get($dashboard)->assertOk();
    }
}
