<?php

namespace App\Filament\Pages\Auth;

use App\Services\EmailDeliveryConfiguration;
use Filament\Actions\Action;
use Filament\Pages\Auth\EmailVerification\EmailVerificationPrompt as BaseEmailVerificationPrompt;

class EmailVerificationPrompt extends BaseEmailVerificationPrompt
{
    protected static string $view = 'filament.pages.auth.email-verification-prompt';

    public function isEmailDeliveryConfigured(): bool
    {
        return app(EmailDeliveryConfiguration::class)->isConfigured();
    }

    public function resendNotificationAction(): Action
    {
        return parent::resendNotificationAction()
            ->disabled(fn (): bool => ! $this->isEmailDeliveryConfigured());
    }
}
