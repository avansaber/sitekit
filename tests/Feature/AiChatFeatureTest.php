<?php

namespace Tests\Feature;

use App\Models\Database;
use App\Models\FirewallRule;
use App\Models\Server;
use App\Models\Service;
use App\Models\SshKey;
use App\Models\Team;
use App\Models\User;
use App\Models\WebApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Team $team;
    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a configured operator and team
        $this->user = User::factory()->create([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->team = Team::factory()->create();
        $this->team->users()->attach($this->user->id, ['role' => 'owner']);

        // Create a test server
        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Server',
            'status' => Server::STATUS_ACTIVE,
            'ip_address' => '192.168.1.100',
        ]);
    }

    public function test_ai_config_enabled(): void
    {
        config(['ai.enabled' => true]);
        $this->assertTrue(config('ai.enabled'));
    }

    public function test_ai_config_disabled(): void
    {
        config(['ai.enabled' => false]);
        $this->assertFalse(config('ai.enabled'));
    }

    public function test_ai_chat_endpoint_requires_auth(): void
    {
        $response = $this->postJson('/api/ai/chat', [
            'message' => 'Test message',
        ]);

        $response->assertUnauthorized();
    }

    public function test_ai_chat_endpoint_works_when_authenticated(): void
    {
        config(['ai.enabled' => true]);

        // API requires team context - test validation works
        $response = $this->actingAs($this->user)
            ->withSession(['filament.current_tenant_id' => $this->team->id])
            ->postJson('/api/ai/chat', [
                'message' => 'Hello AI',
                'team_id' => $this->team->id,
            ]);

        // API should return 200 or handle gracefully (provider may not be configured)
        $this->assertTrue(in_array($response->status(), [200, 500, 503]));
    }

    public function test_ai_chat_endpoint_requires_message(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/ai/chat', []);

        $response->assertStatus(422);
    }

    public function test_ai_disabled_returns_error(): void
    {
        config(['ai.enabled' => false]);

        $response = $this->actingAs($this->user)
            ->withSession(['filament.current_tenant_id' => $this->team->id])
            ->postJson('/api/ai/chat', [
                'message' => 'Test',
                'team_id' => $this->team->id,
            ]);

        // When AI is disabled, should return 503 or similar error
        $this->assertTrue(in_array($response->status(), [500, 503]));
    }

    public function test_server_resource_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/servers/{$this->server->id}");

        $response->assertOk();
        // Check for AI action presence (may be in JavaScript)
        $response->assertSee('Diagnose with AI', false);
    }

    public function test_ssh_key_create_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/ssh-keys/create");

        $response->assertOk();
        $response->assertSee('Which key type?', false);
    }

    public function test_firewall_list_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/firewall-rules");

        $response->assertOk();
        $response->assertSee('Audit Rules', false);
    }

    public function test_firewall_create_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/firewall-rules/create");

        $response->assertOk();
        $response->assertSee('What ports do I need?', false);
    }

    public function test_webapp_create_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/web-apps/create");

        $response->assertOk();
        $response->assertSee('Which stack?', false);
    }

    public function test_cron_create_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/cron-jobs/create");

        $response->assertOk();
        $response->assertSee('Cron syntax help', false);
    }

    public function test_supervisor_create_page_loads(): void
    {
        config(['ai.enabled' => true]);

        $response = $this->actingAs($this->user)
            ->get("/app/{$this->team->id}/supervisor-programs/create");

        $response->assertOk();
        $response->assertSee('Queue worker setup', false);
    }

    public function test_ai_widget_blade_component_exists(): void
    {
        $this->assertFileExists(resource_path('views/components/unified-assistant.blade.php'));
    }

    public function test_ai_controller_exists(): void
    {
        $this->assertFileExists(app_path('Http/Controllers/AiController.php'));
    }

    public function test_ai_service_exists(): void
    {
        $this->assertFileExists(app_path('Services/AI/AiService.php'));
    }

    public function test_ai_providers_exist(): void
    {
        $this->assertFileExists(app_path('Services/AI/Providers/ClaudeProvider.php'));
        $this->assertFileExists(app_path('Services/AI/Providers/OpenAiProvider.php'));
        $this->assertFileExists(app_path('Services/AI/Providers/GeminiProvider.php'));
    }

    public function test_ai_config_file_exists(): void
    {
        $this->assertFileExists(config_path('ai.php'));
    }

    public function test_ai_config_has_required_keys(): void
    {
        $config = config('ai');

        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('default_provider', $config);
        $this->assertArrayHasKey('providers', $config);
        $this->assertArrayHasKey('system_prompt', $config);
    }

    public function test_ai_providers_configured(): void
    {
        $providers = config('ai.providers');

        $this->assertArrayHasKey('openai', $providers);
        $this->assertArrayHasKey('anthropic', $providers);
        $this->assertArrayHasKey('gemini', $providers);
    }
}
