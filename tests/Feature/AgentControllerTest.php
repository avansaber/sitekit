<?php

namespace Tests\Feature;

use App\Models\AgentJob;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Team $team;
    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->withPersonalTeam()->create();
        $this->team = $this->user->currentTeam;
        $this->server = Server::factory()->create([
            'team_id' => $this->team->id,
            'status' => Server::STATUS_PROVISIONING,
            'agent_token' => 'test-agent-token-123',
            'agent_token_expires_at' => now()->addHours(24),
        ]);
    }

    public function test_heartbeat_updates_server_status(): void
    {
        $response = $this->postJson('/api/agent/heartbeat', [
            'os_name' => 'Ubuntu',
            'os_version' => '24.04',
            'cpu_count' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 100,
            'cpu_percent' => 25.5,
            'memory_percent' => 45.0,
            'disk_percent' => 30.0,
            'services_status' => [
                ['name' => 'nginx', 'status' => 'running', 'enabled' => true],
                ['name' => 'php8.3-fpm', 'status' => 'running', 'enabled' => true],
            ],
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->server->refresh();

        $this->assertEquals(Server::STATUS_ACTIVE, $this->server->status);
        $this->assertEquals('Ubuntu', $this->server->os_name);
        $this->assertEquals('24.04', $this->server->os_version);
        $this->assertNotNull($this->server->last_heartbeat_at);
    }

    public function test_heartbeat_syncs_services_on_first_connect(): void
    {
        $response = $this->postJson('/api/agent/heartbeat', [
            'services_status' => [
                ['name' => 'nginx', 'status' => 'running', 'enabled' => true],
                ['name' => 'php8.3-fpm', 'status' => 'running', 'enabled' => true],
                ['name' => 'redis-server', 'status' => 'running', 'enabled' => true],
            ],
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk();

        // Services should be synced
        $this->assertEquals(3, $this->server->services()->count());
        $this->assertTrue($this->server->services()->where('type', Service::TYPE_NGINX)->exists());
        $this->assertTrue($this->server->services()->where('type', Service::TYPE_PHP)->where('version', '8.3')->exists());
        $this->assertTrue($this->server->services()->where('type', Service::TYPE_REDIS)->exists());
    }

    public function test_heartbeat_updates_service_status(): void
    {
        // Create existing service
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'type' => Service::TYPE_REDIS,
            'version' => '7.2',
            'status' => Service::STATUS_ACTIVE,
        ]);

        // Set server to active first
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $response = $this->postJson('/api/agent/heartbeat', [
            'services_status' => [
                ['name' => 'redis-server', 'status' => 'stopped', 'enabled' => true, 'version' => '7.2'],
            ],
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk();

        $service->refresh();
        $this->assertEquals(Service::STATUS_STOPPED, $service->status);
    }

    public function test_jobs_returns_pending_jobs(): void
    {
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $job = AgentJob::create([
            'server_id' => $this->server->id,
            'team_id' => $this->team->id,
            'type' => 'service_restart',
            'payload' => ['service_type' => 'nginx', 'version' => 'latest'],
            'status' => AgentJob::STATUS_PENDING,
        ]);

        $response = $this->getJson('/api/agent/jobs', [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('jobs.0.id', $job->id)
            ->assertJsonPath('jobs.0.type', 'service_restart');

        // Job should now be running
        $job->refresh();
        $this->assertEquals(AgentJob::STATUS_RUNNING, $job->status);
    }

    public function test_job_complete_updates_job_status(): void
    {
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $job = AgentJob::create([
            'server_id' => $this->server->id,
            'team_id' => $this->team->id,
            'type' => 'service_restart',
            'payload' => ['service_type' => 'nginx', 'version' => 'latest'],
            'status' => AgentJob::STATUS_RUNNING,
        ]);

        $response = $this->postJson("/api/agent/jobs/{$job->id}/complete", [
            'status' => 'completed',
            'output' => 'Service restarted successfully',
            'exit_code' => 0,
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'ok']);

        $job->refresh();
        $this->assertEquals(AgentJob::STATUS_COMPLETED, $job->status);
        $this->assertEquals('Service restarted successfully', $job->output);
        $this->assertEquals(0, $job->exit_code);
    }

    public function test_service_start_callback_updates_service_status(): void
    {
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'type' => Service::TYPE_REDIS,
            'version' => '7.2',
            'status' => Service::STATUS_STOPPED,
        ]);

        $job = AgentJob::create([
            'server_id' => $this->server->id,
            'team_id' => $this->team->id,
            'type' => 'service_start',
            'payload' => ['service_id' => $service->id, 'service_type' => 'redis', 'version' => '7.2'],
            'status' => AgentJob::STATUS_RUNNING,
        ]);

        $response = $this->postJson("/api/agent/jobs/{$job->id}/complete", [
            'status' => 'completed',
            'output' => 'Service started',
            'exit_code' => 0,
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk();

        $service->refresh();
        $this->assertEquals(Service::STATUS_ACTIVE, $service->status);
    }

    public function test_service_stop_callback_updates_service_status(): void
    {
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'type' => Service::TYPE_REDIS,
            'version' => '7.2',
            'status' => Service::STATUS_ACTIVE,
        ]);

        $job = AgentJob::create([
            'server_id' => $this->server->id,
            'team_id' => $this->team->id,
            'type' => 'service_stop',
            'payload' => ['service_id' => $service->id, 'service_type' => 'redis', 'version' => '7.2'],
            'status' => AgentJob::STATUS_RUNNING,
        ]);

        $response = $this->postJson("/api/agent/jobs/{$job->id}/complete", [
            'status' => 'completed',
            'output' => 'Service stopped',
            'exit_code' => 0,
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk();

        $service->refresh();
        $this->assertEquals(Service::STATUS_STOPPED, $service->status);
    }

    public function test_heartbeat_stores_service_metrics(): void
    {
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'type' => Service::TYPE_REDIS,
            'version' => '7.2',
            'status' => Service::STATUS_ACTIVE,
        ]);

        $response = $this->postJson('/api/agent/heartbeat', [
            'services_status' => [
                [
                    'name' => 'redis-server',
                    'status' => 'running',
                    'enabled' => true,
                    'version' => '7.2',
                    'cpu_percent' => 2.5,
                    'memory_mb' => 128,
                    'uptime_seconds' => 3600,
                ],
            ],
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk();

        // Check that service stat was created
        $stat = $service->stats()->latest()->first();
        $this->assertNotNull($stat);
        $this->assertEquals(2.5, $stat->cpu_percent);
        $this->assertEquals(128, $stat->memory_mb);
        $this->assertEquals(3600, $stat->uptime_seconds);
    }

    public function test_unauthorized_request_is_rejected(): void
    {
        $response = $this->postJson('/api/agent/heartbeat', [
            'cpu_percent' => 25.5,
        ]);

        $response->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $this->server->update([
            'agent_token_expires_at' => now()->subHour(),
        ]);

        $response = $this->postJson('/api/agent/heartbeat', [
            'cpu_percent' => 25.5,
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertStatus(401);
    }

    public function test_heartbeat_stores_database_health(): void
    {
        $this->server->update(['status' => Server::STATUS_ACTIVE]);

        $response = $this->postJson('/api/agent/heartbeat', [
            'database_health' => [
                'mysql' => [
                    'status' => 'ok',
                    'response_ms' => 15,
                ],
                'postgresql' => [
                    'status' => 'error',
                    'error' => 'Connection refused',
                ],
            ],
        ], [
            'Authorization' => 'Bearer test-agent-token-123',
        ]);

        $response->assertOk();

        $this->server->refresh();
        $health = $this->server->database_health;

        $this->assertIsArray($health);
        $this->assertEquals('ok', $health['mysql']['status']);
        $this->assertEquals(15, $health['mysql']['response_ms']);
        $this->assertEquals('error', $health['postgresql']['status']);
        $this->assertEquals('Connection refused', $health['postgresql']['error']);
    }

    public function test_server_is_database_healthy_helper(): void
    {
        $this->server->update([
            'status' => Server::STATUS_ACTIVE,
            'database_health' => [
                'mysql' => ['status' => 'ok', 'response_ms' => 10],
                'postgresql' => ['status' => 'error', 'error' => 'Connection refused'],
            ],
        ]);

        $this->assertTrue($this->server->isDatabaseHealthy('mysql'));
        $this->assertFalse($this->server->isDatabaseHealthy('postgresql'));
        $this->assertNull($this->server->isDatabaseHealthy('redis')); // Not in health data
    }

    public function test_provisioning_callback_rotates_token_and_cannot_be_replayed(): void
    {
        $this->server->update(['status' => Server::STATUS_PENDING]);

        $response = $this->postJson(
            '/api/provision/callback/test-agent-token-123',
            $this->provisioningPayload(),
        );

        $response->assertOk()->assertJsonPath('success', true);

        $permanentToken = $response->json('agent_token');
        $this->assertNotSame('test-agent-token-123', $permanentToken);
        $this->assertDatabaseHas('servers', [
            'id' => $this->server->id,
            'status' => Server::STATUS_PROVISIONING,
            'agent_token' => $permanentToken,
            'agent_token_expires_at' => null,
        ]);

        $this->postJson(
            '/api/provision/callback/test-agent-token-123',
            $this->provisioningPayload(),
        )->assertNotFound();
    }

    public function test_provisioning_callback_rejects_expired_token(): void
    {
        $this->server->update([
            'status' => Server::STATUS_PENDING,
            'agent_token_expires_at' => now()->subMinute(),
        ]);

        $this->postJson(
            '/api/provision/callback/test-agent-token-123',
            $this->provisioningPayload(),
        )->assertNotFound();

        $this->assertSame(Server::STATUS_PENDING, $this->server->fresh()->status);
    }

    public function test_provisioning_script_rejects_non_pending_server(): void
    {
        $this->get('/provision/test-agent-token-123')
            ->assertStatus(400)
            ->assertSeeText('Invalid or expired token');
    }

    private function provisioningPayload(): array
    {
        return [
            'ip_address' => '203.0.113.10',
            'public_key' => 'test-public-key',
            'os_name' => 'Ubuntu',
            'os_version' => '24.04',
            'cpu_count' => 4,
            'memory_mb' => 8192,
            'disk_gb' => 100,
        ];
    }
}
