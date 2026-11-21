<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Models\User;
use App\Services\EmailDeliveryConfiguration;
use Filament\Facades\Filament;
use Filament\Notifications\Auth\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class EmailVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Notification::fake();
    }

    public function test_prompt_does_not_claim_email_was_sent_and_disables_resend(): void
    {
        config()->set('mail.default', 'log');
        $this->actingAs(User::factory()->unverified()->create());

        $this->get(Filament::getEmailVerificationPromptUrl())
            ->assertOk()
            ->assertSee(app(EmailDeliveryConfiguration::class)->unavailableMessage())
            ->assertDontSee('We have sent an email');

        Livewire::test(EmailVerificationPrompt::class)
            ->assertActionDisabled('resendNotification')
            ->callAction('resendNotification')
            ->assertNotNotified();

        Notification::assertNothingSent();
    }

    public function test_resend_becomes_available_after_delivery_is_configured(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        $this->configureSmtp();

        Livewire::test(EmailVerificationPrompt::class)
            ->assertDontSee(app(EmailDeliveryConfiguration::class)->unavailableMessage())
            ->assertActionEnabled('resendNotification')
            ->callAction('resendNotification')
            ->assertNotified();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_verification_links_still_verify_email(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $url = Filament::getVerifyEmailUrl($user);
        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_invalid_verification_hash_does_not_verify_email(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);

        $url = URL::temporarySignedRoute('filament.app.auth.email-verification.verify', now()->addHour(), [
            'id' => $user->getKey(),
            'hash' => sha1('wrong@example.com'),
        ]);

        $this->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function configureSmtp(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => 'localhost', 'port' => 1025]);
    }
}
