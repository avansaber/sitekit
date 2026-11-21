<?php

namespace App\Providers;

use App\Events\ServerStatsUpdated;
use App\Events\ServerStatusChanged;
use App\Listeners\ServerStatsListener;
use App\Listeners\ServerStatusListener;
use App\Models\WebApp;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Channels\SlackWebhookChannel;
use App\Observers\WebAppObserver;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model observers
        WebApp::observe(WebAppObserver::class);

        // Register server event listeners for resource monitoring
        Event::listen(ServerStatsUpdated::class, ServerStatsListener::class);
        Event::listen(ServerStatusChanged::class, ServerStatusListener::class);

        // Register custom notification channels
        Notification::extend('slack', function ($app) {
            return new SlackWebhookChannel();
        });

        Notification::extend('discord', function ($app) {
            return new DiscordWebhookChannel();
        });
    }
}
