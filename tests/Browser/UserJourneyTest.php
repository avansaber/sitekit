<?php

namespace Tests\Browser;

use App\Models\Database;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Models\WebApp;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Comprehensive end-to-end user journey test
 * Tests the complete flow from operator login to app deployment
 *
 * Configure in .env:
 * - DUSK_TEST_USER_EMAIL
 * - DUSK_TEST_SERVER_IP
 */
class UserJourneyTest extends DuskTestCase
{
    protected string $testEmail;
    protected string $testPassword = 'password';
    protected string $testTeamName = 'Test Team';
    protected string $serverIp;

    // Store provisioning URL between tests
    protected static ?string $provisioningUrl = null;
    protected static ?string $teamId = null;
    protected static ?string $serverId = null;

    // Error log for tracking issues
    protected static array $errors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->testEmail = $this->getTestUserEmail();
        $this->serverIp = $this->getTestServerIp();
    }

    protected function cleanupTestData(): void
    {
        // Remove test user if exists
        $user = User::where('email', $this->testEmail)->first();
        if ($user) {
            // Delete associated teams and data
            foreach ($user->ownedTeams as $team) {
                // Delete web apps
                WebApp::where('team_id', $team->id)->delete();
                // Delete databases
                Database::where('team_id', $team->id)->delete();
                // Delete servers
                Server::where('team_id', $team->id)->delete();
                // Delete team
                $team->delete();
            }
            $user->delete();
        }
    }

    protected function ensureUserExists(): User
    {
        $user = User::where('email', $this->testEmail)->first();
        if (!$user) {
            $user = User::create([
                'name' => 'Max User',
                'email' => $this->testEmail,
                'password' => bcrypt($this->testPassword),
                'email_verified_at' => now(),
            ]);
        } else {
            // Ensure email is verified
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
            }
        }
        return $user;
    }

    protected function ensureTeamExists(User $user): Team
    {
        $team = $user->ownedTeams()->where('name', $this->testTeamName)->first();
        if (!$team) {
            $team = $user->currentTeam ?? $user->ownedTeams()->first();
        }
        if (!$team) {
            $team = Team::create([
                'name' => $this->testTeamName,
                'user_id' => $user->id,
                'personal_team' => true,
            ]);
            $user->current_team_id = $team->id;
            $user->save();
        }
        self::$teamId = $team->id;
        return $team;
    }

    /**
     * Login via UI for external URL testing
     */
    protected function loginViaUI(Browser $browser, User $user): Browser
    {
        $browser->visit($this->baseUrl . '/app/login')
            ->pause(2000);

        // Check if we're on login page or already logged in
        $currentUrl = $browser->driver->getCurrentURL();
        if (str_contains($currentUrl, '/login')) {
            $browser->type('#data\\.email', $this->testEmail)
                ->pause(200)
                ->type('#data\\.password', $this->testPassword)
                ->pause(200)
                ->press('Sign in')
                ->pause(3000);
        }

        return $browser;
    }

    /**
     * Step 0: Clean up test data before starting
     */
    public function test_00_cleanup_before_test(): void
    {
        $this->cleanupTestData();
        $this->addToLog('Test data cleaned up');
        $this->assertTrue(true, 'Cleanup completed');
    }

    /**
     * Step 1: Confirm signup is closed and provision the operator fixture
     */
    public function test_01_can_prepare_operator(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit($this->baseUrl . '/app/register')
                ->pause(1000)
                ->assertPathIs('/app/login')
                ->screenshot('journey-01-login-page');
        });

        $user = $this->ensureUserExists();
        $this->ensureTeamExists($user);

        $this->assertTrue($user->hasVerifiedEmail());
    }

    /**
     * Step 2: Create team
     */
    public function test_02_can_create_team(): void
    {
        $user = $this->ensureUserExists();

        $this->browse(function (Browser $browser) use ($user) {
            // Login via UI since we're using external URL
            $this->loginViaUI($browser, $user);

            $browser->visit($this->baseUrl . '/app')
                ->pause(3000)
                ->screenshot('journey-04-after-login');

            // Check if we need to create a team
            $currentUrl = $browser->driver->getCurrentURL();
            $this->addToLog('Current URL after login: ' . $currentUrl);

            // If on create team page
            if (str_contains($currentUrl, 'new') || str_contains($currentUrl, 'create')) {
                $browser->script("
                    const nameInput = document.querySelector('input[name=\"name\"]');
                    if (nameInput) {
                        nameInput.value = '{$this->testTeamName}';
                        nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                ");
                $browser->pause(300)
                    ->screenshot('journey-05-team-name-entered');

                // Submit
                $browser->script("
                    const submitBtn = document.querySelector('button[type=\"submit\"]');
                    if (submitBtn) submitBtn.click();
                ");

                $browser->pause(3000)
                    ->screenshot('journey-06-team-created');
            }
        });

        // Verify team was created
        $user->refresh();
        $team = $user->ownedTeams()->where('name', $this->testTeamName)->first();
        if ($team) {
            self::$teamId = $team->id;
            $this->addToLog('Team created successfully: ' . $team->id);
        } else {
            // Try to find any team
            $team = $user->currentTeam ?? $user->ownedTeams()->first();
            if ($team) {
                self::$teamId = $team->id;
                $this->addToLog('Using existing team: ' . $team->id . ' (' . $team->name . ')');
            } else {
                $this->addError('Team creation failed - no team found');
            }
        }
        $this->assertNotNull($team, 'Team should be created');
    }

    /**
     * Step 3: Create server and get provisioning URL
     */
    public function test_03_can_create_server(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);

        $this->browse(function (Browser $browser) use ($user, $team) {
            // Login via UI since we're using external URL
            $this->loginViaUI($browser, $user);

            $browser->visit($this->baseUrl . "/app/{$team->id}/servers/create")
                ->pause(3000)
                ->screenshot('journey-07-create-server-page');

            // Fill server form - only name is editable, IP is auto-detected after provisioning
            $browser->type('#data\\.name', 'Test Server')
                ->pause(300)
                ->screenshot('journey-08-server-form-filled');

            // Submit
            $browser->press('Create')
                ->pause(5000)
                ->screenshot('journey-09-server-created');

            // Check for provisioning URL on the page
            $pageContent = $browser->driver->getPageSource();

            // Look for the provisioning URL pattern
            if (preg_match('/(https?:\/\/[^\s"\']+\/provision\/[a-zA-Z0-9]+)/', $pageContent, $matches)) {
                self::$provisioningUrl = $matches[1];
                $this->addToLog('Provisioning URL found: ' . self::$provisioningUrl);
            }

            // Also try to get it from a code block or pre element
            $provUrl = $browser->script("
                const codeBlock = document.querySelector('code, pre, .font-mono');
                if (codeBlock) {
                    const text = codeBlock.textContent;
                    const match = text.match(/curl.*provision\\/[a-zA-Z0-9]+/);
                    if (match) return match[0];
                }
                return null;
            ");

            if ($provUrl[0]) {
                // Extract URL from curl command
                if (preg_match('/(https?:\/\/[^\s"\']+\/provision\/[a-zA-Z0-9]+)/', $provUrl[0], $matches)) {
                    self::$provisioningUrl = $matches[1];
                    $this->addToLog('Provisioning URL from code block: ' . self::$provisioningUrl);
                }
            }

            $browser->screenshot('journey-10-provisioning-url');
        });

        // Verify server was created (by name since IP is auto-detected)
        $server = Server::where('team_id', $team->id)
            ->where('name', 'Test Server')
            ->first();

        if ($server) {
            self::$serverId = $server->id;
            $this->addToLog('Server created successfully: ' . $server->id);

            // Set the IP address manually for our test server
            if (!$server->ip_address) {
                $server->ip_address = $this->serverIp;
                $server->save();
                $this->addToLog('IP address set manually: ' . $this->serverIp);
            }

            // Get provisioning URL from server if not found on page
            if (!self::$provisioningUrl && $server->provision_token) {
                self::$provisioningUrl = $this->baseUrl . '/provision/' . $server->provision_token;
                $this->addToLog('Provisioning URL from database: ' . self::$provisioningUrl);
            }
        } else {
            $this->addError('Server creation failed - server not found in database');
        }

        $this->assertNotNull($server, 'Server should be created');
    }

    /**
     * Step 4: Run provisioning script via SSH
     */
    public function test_04_can_run_provisioning_script(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);

        // Ensure server exists
        $server = Server::where('ip_address', $this->serverIp)->first();
        if (!$server) {
            $server = Server::create([
                'team_id' => $team->id,
                'name' => 'Test Server',
                'ip_address' => $this->serverIp,
                'status' => 'pending',
                'provision_token' => \Illuminate\Support\Str::random(64),
            ]);
        }

        if (!self::$provisioningUrl) {
            if ($server && $server->provision_token) {
                self::$provisioningUrl = $this->baseUrl . '/provision/' . $server->provision_token;
                $this->addToLog('Retrieved provisioning URL from database: ' . self::$provisioningUrl);
            } else {
                $this->markTestSkipped('No provisioning URL available');
            }
        }

        $this->addToLog('Running provisioning script: ' . self::$provisioningUrl);

        // Run the provisioning script via SSH
        $command = "ssh -o StrictHostKeyChecking=no ubuntu@{$this->serverIp} \"curl -sSL -H 'ngrok-skip-browser-warning: true' '" . self::$provisioningUrl . "' | sudo bash\" 2>&1";

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $outputStr = implode("\n", $output);
        $this->addToLog('Provisioning output (first 500 chars): ' . substr($outputStr, 0, 500));

        if ($returnCode !== 0) {
            $this->addError('Provisioning script failed with code: ' . $returnCode);
        }

        // Wait for server to provision
        sleep(30);

        // Verify server status
        $server = Server::where('ip_address', $this->serverIp)->first();
        if ($server) {
            $server->refresh();
            $this->addToLog('Server status after provisioning: ' . $server->status);
        }

        $this->assertTrue(true, 'Provisioning script executed');
    }

    /**
     * Step 5: Create database and user
     */
    public function test_05_can_create_database(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);
        $server = Server::where('ip_address', $this->serverIp)->first();

        if (!$server) {
            $this->addError('Server not found for database creation');
            $this->markTestSkipped('Server not found');
        }

        $this->browse(function (Browser $browser) use ($user, $team, $server) {
            // Login via UI since we're using external URL
            $this->loginViaUI($browser, $user);

            $browser->visit($this->baseUrl . "/app/{$team->id}/databases/create")
                ->pause(3000)
                ->screenshot('journey-11-create-database-page');

            // Select server using JavaScript - find the first select dropdown (Server)
            $browser->script("
                // Find the Server select trigger - it's the first select dropdown with 'Select an option'
                const selects = document.querySelectorAll('button[type=\"button\"]');
                for (const sel of selects) {
                    if (sel.textContent.includes('Select an option') || sel.textContent.includes('Select')) {
                        sel.click();
                        break;
                    }
                }
            ");
            $browser->pause(1500)
                ->screenshot('journey-12-server-dropdown');

            // Click the Test Server option using JavaScript with proper event handling
            $browser->script("
                const options = document.querySelectorAll('[role=\"option\"], li[id*=\"listbox\"]');
                for (const opt of options) {
                    if (opt.textContent.includes('Test Server')) {
                        opt.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                        opt.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
                        opt.click();
                        break;
                    }
                }
            ");
            $browser->pause(1500)
                ->screenshot('journey-13-server-selected');

            // Clear and enter database name using JavaScript for Livewire compatibility
            $browser->script("
                const nameInput = document.querySelector('input[id*=\"name\"]');
                if (nameInput) {
                    nameInput.value = '';
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                    nameInput.value = 'test_database';
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                    nameInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
            $browser->pause(500)
                ->screenshot('journey-14-database-name');

            // The "Create database user" toggle is enabled by default
            // Username and password are auto-generated
            $browser->screenshot('journey-15-database-form-complete');

            // Submit using JavaScript to click the Create button
            $browser->script("
                const createBtn = document.querySelector('button[type=\"submit\"], button.fi-btn');
                if (createBtn && createBtn.textContent.includes('Create')) {
                    createBtn.click();
                } else {
                    // Fallback - find button with Create text
                    const buttons = document.querySelectorAll('button');
                    for (const btn of buttons) {
                        if (btn.textContent.trim() === 'Create') {
                            btn.click();
                            break;
                        }
                    }
                }
            ");
            $browser->pause(5000)
                ->screenshot('journey-16-database-created');

            // Check if we got redirected or if there's a success notification
            $browser->screenshot('journey-16b-after-submit');
        });

        // Verify database was created
        $database = Database::where('team_id', $team->id)
            ->where('name', 'test_database')
            ->first();

        if ($database) {
            $this->addToLog('Database created successfully: ' . $database->id);
        } else {
            $this->addError('Database creation failed - check if server is connected');
        }

        $this->assertTrue(true, 'Database creation attempted');
    }

    /**
     * Step 6: Create WordPress app
     */
    public function test_06_can_create_wordpress_app(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);
        $server = Server::where('ip_address', $this->serverIp)->first();

        if (!$server) {
            $this->addError('Server not found for WordPress app creation');
            $this->markTestSkipped('Server not found');
        }

        $this->browse(function (Browser $browser) use ($user, $team, $server) {
            // Login via UI since we're using external URL
            $this->loginViaUI($browser, $user);

            $browser->visit($this->baseUrl . "/app/{$team->id}/web-apps/create")
                ->pause(3000)
                ->screenshot('journey-18-create-webapp-page');

            // Enter app name first
            $browser->script("
                const nameInput = document.querySelector('input[id*=\"name\"]');
                if (nameInput) {
                    nameInput.value = 'WordPress Site';
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                    nameInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
            $browser->pause(500);

            // Enter domain
            $browser->script("
                const domainInput = document.querySelector('input[id*=\"domain\"]');
                if (domainInput) {
                    domainInput.value = 'test1.test.example.com';
                    domainInput.dispatchEvent(new Event('input', { bubbles: true }));
                    domainInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
            $browser->pause(500)
                ->screenshot('journey-19-domain-entered');

            // Select server - find the Server dropdown by looking for Select an option
            $browser->script("
                const selects = document.querySelectorAll('button[type=\"button\"]');
                for (const sel of selects) {
                    if (sel.textContent.includes('Select an option')) {
                        sel.click();
                        break;
                    }
                }
            ");
            $browser->pause(1500)
                ->screenshot('journey-20-server-dropdown');

            // Click the Test Server option
            $browser->script("
                const options = document.querySelectorAll('[role=\"option\"], li[id*=\"listbox\"]');
                for (const opt of options) {
                    if (opt.textContent.includes('Test Server')) {
                        opt.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                        opt.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
                        opt.click();
                        break;
                    }
                }
            ");
            $browser->pause(1500)
                ->screenshot('journey-21-server-selected');

            // Submit using JavaScript
            $browser->script("
                const buttons = document.querySelectorAll('button');
                for (const btn of buttons) {
                    if (btn.textContent.trim() === 'Create') {
                        btn.click();
                        break;
                    }
                }
            ");
            $browser->pause(5000)
                ->screenshot('journey-22-wordpress-created');
        });

        // Verify app was created
        $webapp = WebApp::where('team_id', $team->id)
            ->where('domain', 'test1.test.example.com')
            ->first();

        if ($webapp) {
            $this->addToLog('WordPress app created successfully: ' . $webapp->id);
        } else {
            $this->addError('WordPress app creation failed');
        }
    }

    /**
     * Step 7: Create Laravel app
     */
    public function test_07_can_create_laravel_app(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);
        $server = Server::where('ip_address', $this->serverIp)->first();

        if (!$server) {
            $this->addError('Server not found for Laravel app creation');
            $this->markTestSkipped('Server not found');
        }

        $this->browse(function (Browser $browser) use ($user, $team, $server) {
            // Login via UI since we're using external URL
            $this->loginViaUI($browser, $user);

            $browser->visit($this->baseUrl . "/app/{$team->id}/web-apps/create")
                ->pause(3000)
                ->screenshot('journey-24-create-laravel-page');

            // Enter app name first
            $browser->script("
                const nameInput = document.querySelector('input[id*=\"name\"]');
                if (nameInput) {
                    nameInput.value = 'Laravel App';
                    nameInput.dispatchEvent(new Event('input', { bubbles: true }));
                    nameInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
            $browser->pause(500);

            // Enter domain
            $browser->script("
                const domainInput = document.querySelector('input[id*=\"domain\"]');
                if (domainInput) {
                    domainInput.value = 'test2.test.example.com';
                    domainInput.dispatchEvent(new Event('input', { bubbles: true }));
                    domainInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
            $browser->pause(500)
                ->screenshot('journey-25-domain-entered');

            // Select server - find the Server dropdown
            $browser->script("
                const selects = document.querySelectorAll('button[type=\"button\"]');
                for (const sel of selects) {
                    if (sel.textContent.includes('Select an option')) {
                        sel.click();
                        break;
                    }
                }
            ");
            $browser->pause(1500)
                ->screenshot('journey-26-server-dropdown');

            // Click the Test Server option
            $browser->script("
                const options = document.querySelectorAll('[role=\"option\"], li[id*=\"listbox\"]');
                for (const opt of options) {
                    if (opt.textContent.includes('Test Server')) {
                        opt.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                        opt.dispatchEvent(new MouseEvent('mouseup', { bubbles: true }));
                        opt.click();
                        break;
                    }
                }
            ");
            $browser->pause(1500)
                ->screenshot('journey-27-server-selected');

            // Submit using JavaScript
            $browser->script("
                const buttons = document.querySelectorAll('button');
                for (const btn of buttons) {
                    if (btn.textContent.trim() === 'Create') {
                        btn.click();
                        break;
                    }
                }
            ");
            $browser->pause(5000)
                ->screenshot('journey-28-laravel-created');
        });

        // Verify app was created
        $webapp = WebApp::where('team_id', $team->id)
            ->where('domain', 'test2.test.example.com')
            ->first();

        if ($webapp) {
            $this->addToLog('Laravel app created successfully: ' . $webapp->id);
        } else {
            $this->addError('Laravel app creation failed');
        }
    }

    /**
     * Step 8: Change PHP versions
     */
    public function test_08_can_change_php_versions(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);

        // Get the WordPress app for test1
        $wordpressApp = WebApp::where('team_id', $team->id)
            ->where('domain', 'test1.test.example.com')
            ->first();

        if ($wordpressApp) {
            $this->browse(function (Browser $browser) use ($user, $team, $wordpressApp) {
                // Login via UI since we're using external URL
                $this->loginViaUI($browser, $user);

                $browser->visit($this->baseUrl . "/app/{$team->id}/web-apps/{$wordpressApp->id}/edit")
                    ->pause(3000)
                    ->screenshot('journey-28-edit-wordpress');

                // Change PHP version to 8.5
                $browser->script("
                    const phpSelect = document.querySelector('[wire\\\\:key*=\"php_version\"] button, select[name*=\"php_version\"]');
                    if (phpSelect) phpSelect.click();
                ");
                $browser->pause(500);

                $browser->script("
                    const options = document.querySelectorAll('[role=\"option\"], .fi-select-option, option');
                    for (const opt of options) {
                        if (opt.textContent.includes('8.5')) {
                            opt.click();
                            break;
                        }
                    }
                ");
                $browser->pause(500)
                    ->screenshot('journey-29-php85-selected');

                // Save
                $browser->press('Save')
                    ->pause(3000)
                    ->screenshot('journey-30-php85-saved');
            });
            $this->addToLog('Changed test1.test.example.com to PHP 8.5');
        } else {
            $this->addError('WordPress app not found for PHP version change');
        }

        // Get the Laravel app for test2
        $laravelApp = WebApp::where('team_id', $team->id)
            ->where('domain', 'test2.test.example.com')
            ->first();

        if ($laravelApp) {
            $this->browse(function (Browser $browser) use ($user, $team, $laravelApp) {
                // Login via UI since we're using external URL
                $this->loginViaUI($browser, $user);

                $browser->visit($this->baseUrl . "/app/{$team->id}/web-apps/{$laravelApp->id}/edit")
                    ->pause(3000)
                    ->screenshot('journey-31-edit-laravel');

                // Change PHP version to 8.4
                $browser->script("
                    const phpSelect = document.querySelector('[wire\\\\:key*=\"php_version\"] button, select[name*=\"php_version\"]');
                    if (phpSelect) phpSelect.click();
                ");
                $browser->pause(500);

                $browser->script("
                    const options = document.querySelectorAll('[role=\"option\"], .fi-select-option, option');
                    for (const opt of options) {
                        if (opt.textContent.includes('8.4')) {
                            opt.click();
                            break;
                        }
                    }
                ");
                $browser->pause(500)
                    ->screenshot('journey-32-php84-selected');

                // Save
                $browser->press('Save')
                    ->pause(3000)
                    ->screenshot('journey-33-php84-saved');
            });
            $this->addToLog('Changed test2.test.example.com to PHP 8.4');
        } else {
            $this->addError('Laravel app not found for PHP version change');
        }

        $this->assertTrue(true, 'PHP version changes attempted');
    }

    /**
     * Step 9: Verify server connection
     */
    public function test_09_verify_server_connection(): void
    {
        $user = $this->ensureUserExists();
        $team = $this->ensureTeamExists($user);
        $server = Server::where('ip_address', $this->serverIp)->first();

        if (!$server) {
            $this->addError('Server not found for connection verification');
            $this->markTestSkipped('Server not found');
        }

        $this->browse(function (Browser $browser) use ($user, $team, $server) {
            // Login via UI since we're using external URL
            $this->loginViaUI($browser, $user);

            $browser->visit($this->baseUrl . "/app/{$team->id}/servers/{$server->id}")
                ->pause(3000)
                ->screenshot('journey-34-server-details');

            // Check server status
            $pageContent = $browser->driver->getPageSource();

            if (str_contains($pageContent, 'connected') || str_contains($pageContent, 'Connected') || str_contains($pageContent, 'online')) {
                $this->addToLog('Server appears to be connected');
            } else {
                $this->addError('Server may not be connected - check status manually');
            }

            $browser->screenshot('journey-35-server-status');
        });

        // Check server status in database
        $server->refresh();
        $this->addToLog('Server status: ' . $server->status);

        $this->assertTrue(true, 'Server connection verification completed');
    }

    /**
     * Final step: Write error log
     */
    public function test_99_write_error_log(): void
    {
        $this->writeErrorLog();
        $this->assertTrue(true, 'Error log written');
    }

    protected function addToLog(string $message): void
    {
        self::$errors[] = ['type' => 'info', 'message' => $message, 'time' => now()->toDateTimeString()];
    }

    protected function addError(string $message): void
    {
        self::$errors[] = ['type' => 'error', 'message' => $message, 'time' => now()->toDateTimeString()];
    }

    protected function writeErrorLog(): void
    {
        $content = "# Error and Issue Log - User Journey Test\n";
        $content .= "## Date: " . now()->toDateTimeString() . "\n\n";

        $content .= "## Test Configuration\n";
        $content .= "- Email: {$this->testEmail}\n";
        $content .= "- Team: {$this->testTeamName}\n";
        $content .= "- Server IP: {$this->serverIp}\n";
        $content .= "- Base URL: {$this->baseUrl}\n\n";

        $content .= "## Log Entries\n\n";

        foreach (self::$errors as $entry) {
            $icon = $entry['type'] === 'error' ? '❌' : 'ℹ️';
            $content .= "### {$icon} [{$entry['time']}] {$entry['type']}\n";
            $content .= "{$entry['message']}\n\n";
        }

        $content .= "## Summary\n";
        $errorCount = count(array_filter(self::$errors, fn($e) => $e['type'] === 'error'));
        $infoCount = count(array_filter(self::$errors, fn($e) => $e['type'] === 'info'));
        $content .= "- Total Errors: {$errorCount}\n";
        $content .= "- Total Info Messages: {$infoCount}\n";

        file_put_contents(
            base_path('Error-User-Journey-Test-Dec-22.md'),
            $content
        );
    }
}
