<?php

namespace App\Http\Middleware;

use App\Filament\Pages\EditProfile;
use Closure;
use Illuminate\Http\Request;
use Laravel\Jetstream\Http\Livewire\TwoFactorAuthenticationForm;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorAuthentication
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        if ($request->routeIs('filament.app.pages.edit-profile')
            || $request->routeIs('filament.app.auth.logout')
            || $request->routeIs('filament.app.auth.email-verification.*')
            || $this->isTwoFactorSetupRequest($request)) {
            return $next($request);
        }

        $tenant = $user->currentTeam ?? $user->allTeams()->first();

        if (! $tenant) {
            abort(403, 'An operator team is required.');
        }

        return redirect()->to(EditProfile::getUrl(panel: 'app', tenant: $tenant));
    }

    private function isTwoFactorSetupRequest(Request $request): bool
    {
        if (! $request->routeIs('livewire.update')) {
            return false;
        }

        foreach ($request->input('components', []) as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '', true);

            if (($snapshot['memo']['name'] ?? null) === TwoFactorAuthenticationForm::class) {
                return true;
            }
        }

        return false;
    }
}
