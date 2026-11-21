<x-filament-panels::page.simple>
    @if ($this->isEmailDeliveryConfigured())
        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            {{
                __('filament-panels::pages/auth/email-verification/email-verification-prompt.messages.notification_sent', [
                    'email' => filament()->auth()->user()->getEmailForVerification(),
                ])
            }}
        </p>

        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            {{ __('filament-panels::pages/auth/email-verification/email-verification-prompt.messages.notification_not_received') }}

            {{ $this->resendNotificationAction }}
        </p>
    @else
        <p role="alert" class="text-center text-sm text-gray-500 dark:text-gray-400">
            {{ app(\App\Services\EmailDeliveryConfiguration::class)->unavailableMessage() }}
        </p>
    @endif
</x-filament-panels::page.simple>
