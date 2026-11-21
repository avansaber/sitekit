<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ApiTokens;
use App\Http\Middleware\RequireTwoFactorAuthentication;
use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\EditProfile;
use App\Filament\Pages\EditTeam;
use App\Listeners\SwitchTeam;
use App\Models\Team;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Hasnayeen\Themes\Http\Middleware\SetTheme;
use Hasnayeen\Themes\ThemesPlugin;
use Laravel\Fortify\Fortify;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->passwordReset()
            ->emailVerification(EmailVerificationPrompt::class)
            ->viteTheme('resources/css/app.css')
            ->colors([
                'primary' => Color::Gray,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->spa()
            ->navigationGroups([
                NavigationGroup::make()->label('Infrastructure'),
                NavigationGroup::make()->label('Applications'),
                NavigationGroup::make()->label('Security'),
                NavigationGroup::make()->label('Monitoring'),
                NavigationGroup::make()->label('Settings'),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Profile')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn () => $this->shouldRegisterMenuItem()
                        ? url(EditProfile::getUrl())
                        : url($panel->getPath())),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                EditProfile::class,
                ApiTokens::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RequireTwoFactorAuthentication::class,
            ])
            ->plugin(ThemesPlugin::make())
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn () => view('components.unified-assistant')
            );

        if (Features::hasApiFeatures()) {
            $panel->userMenuItems([
                MenuItem::make()
                    ->label('API Tokens')
                    ->icon('heroicon-o-key')
                    ->url(fn () => $this->shouldRegisterMenuItem()
                        ? url(ApiTokens::getUrl())
                        : url($panel->getPath())),
            ]);
        }

        if (Features::hasTeamFeatures()) {
            $panel
                ->tenant(Team::class)
                ->tenantRegistration(CreateTeam::class)
                ->tenantProfile(EditTeam::class)
                ->tenantMiddleware([
                    SetTheme::class,
                ])
                ->userMenuItems([
                    MenuItem::make()
                        ->label(fn () => __('Team Settings'))
                        ->icon('heroicon-o-cog-6-tooth')
                        ->url(fn () => $this->shouldRegisterMenuItem()
                            ? url(EditTeam::getUrl())
                            : url($panel->getPath())),
                ]);
        }

        return $panel;
    }

    public function boot()
    {
        /**
         * Disable Fortify routes
         */
        Fortify::$registersRoutes = false;

        /**
         * Disable Jetstream routes
         */
        Jetstream::$registersRoutes = false;

        /**
         * Listen and switch team if tenant was changed
         */
        Event::listen(
            TenantSet::class,
            SwitchTeam::class,
        );
    }

    public function shouldRegisterMenuItem(): bool
    {
        $hasVerifiedEmail = auth()->user()?->hasVerifiedEmail();

        return Filament::hasTenancy()
            ? $hasVerifiedEmail && Filament::getTenant()
            : $hasVerifiedEmail;
    }
}
