<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\ProvisioningController;
use App\Http\Controllers\SourceProviderController;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Http\Controllers\TeamInvitationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', fn () => view('welcome'));

// Public documentation routes
Route::get('/docs/{topic?}', [\App\Http\Controllers\PublicDocsController::class, 'show'])
    ->name('docs.show');

Route::redirect('/login', '/app/login')->name('login');

Route::redirect('/register', '/app/login')->name('register');
Route::redirect('/app/register', '/app/login')->name('filament.register');

Route::redirect('/dashboard', '/app')->name('dashboard');

Route::get('/team-invitations/{invitation}', [TeamInvitationController::class, 'accept'])
    ->middleware(['signed', 'verified', 'auth', AuthenticateSession::class])
    ->name('team-invitations.accept');

Route::get('/provision/{token}', [ProvisioningController::class, 'show'])
    ->name('provision.show');

// OAuth routes for Git providers
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/oauth/{provider}/redirect', [SourceProviderController::class, 'redirect'])
        ->name('oauth.redirect');
    Route::get('/oauth/{provider}/callback', [SourceProviderController::class, 'callback'])
        ->name('oauth.callback');
    Route::delete('/oauth/{provider}/disconnect', [SourceProviderController::class, 'disconnect'])
        ->name('oauth.disconnect');

    // Backup downloads
    Route::get('/backups/{backup}/download', [BackupController::class, 'download'])
        ->name('backups.download');

    // AI Chat routes (session-based auth for Filament integration)
    Route::prefix('ai')->group(function () {
        Route::post('/chat', [\App\Http\Controllers\AiController::class, 'chat'])->name('ai.chat');
        Route::post('/explain', [\App\Http\Controllers\AiController::class, 'explain'])->name('ai.explain');
    });
});
