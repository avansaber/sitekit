<?php

namespace App\Http\Controllers;

use App\Events\ServerStatusChanged;
use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisioningController extends Controller
{
    public function show(string $token): Response
    {
        $server = Server::where('agent_token', $token)
            ->where('status', Server::STATUS_PENDING)
            ->where('agent_token_expires_at', '>', now())
            ->first();

        if (!$server) {
            return response('# Error: Invalid or expired token', 400)
                ->header('Content-Type', 'text/plain');
        }

        $script = $this->generateProvisioningScript($server);

        return response($script)
            ->header('Content-Type', 'text/plain');
    }

    public function callback(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'ip_address' => 'required|ip',
            'public_key' => 'required|string',
            'os_name' => 'nullable|string|max:100',
            'os_version' => 'nullable|string|max:50',
            'cpu_count' => 'nullable|integer|min:1',
            'memory_mb' => 'nullable|integer|min:1',
            'disk_gb' => 'nullable|integer|min:1',
        ]);

        $result = DB::transaction(function () use ($token, $validated): ?array {
            $server = Server::where('agent_token', $token)
                ->where('status', Server::STATUS_PENDING)
                ->where('agent_token_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$server) {
                return null;
            }

            $previousStatus = $server->status;
            $permanentToken = Str::random(64);

            $server->update([
                'ip_address' => $validated['ip_address'],
                'agent_public_key' => $validated['public_key'],
                'os_name' => $validated['os_name'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'cpu_count' => $validated['cpu_count'] ?? null,
                'memory_mb' => $validated['memory_mb'] ?? null,
                'disk_gb' => $validated['disk_gb'] ?? null,
                'status' => Server::STATUS_PROVISIONING,
                'provisioning_phase' => Server::PHASE_BOOTSTRAP,
                'last_heartbeat_at' => now(),
                'agent_token' => $permanentToken,
                'agent_token_expires_at' => null,
            ]);

            return [$server, $previousStatus, $permanentToken];
        });

        if (!$result) {
            return response()->json(['error' => 'Invalid or expired token'], 404);
        }

        [$server, $previousStatus, $permanentToken] = $result;

        if ($previousStatus !== Server::STATUS_PROVISIONING) {
            event(new ServerStatusChanged($server, $previousStatus));
        }

        return response()->json([
            'success' => true,
            'server_id' => $server->id,
            'agent_token' => $permanentToken,
            'saas_url' => $request->getSchemeAndHttpHost(),
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }

    protected function generateProvisioningScript(Server $server): string
    {
        // Use the request URL if available (handles ngrok/tunnels), otherwise fall back to config
        $baseUrl = request()->getSchemeAndHttpHost();
        $callbackUrl = $baseUrl . '/api/provision/callback/' . $server->agent_token;
        // Download agent from GitHub releases
        $agentDownloadUrl = 'https://raw.githubusercontent.com/avansaber/sitekit-agent/main/bin';

        // This is now a MINIMAL bootstrap script that only:
        // 1. Creates the sitekit user
        // 2. Downloads and installs the agent
        // 3. Starts the agent service
        // All software installation (nginx, php, mysql, etc.) is handled by the agent via provision_* jobs

        return <<<BASH
#!/bin/bash
set -e

# =============================================
#  SiteKit Agent Bootstrap Script
#  Server: {$server->name}
#  Generated: $(date -u +"%Y-%m-%dT%H:%M:%SZ")
# =============================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_status() { echo -e "\${BLUE}[*]\${NC} \$1"; }
print_success() { echo -e "\${GREEN}[✓]\${NC} \$1"; }
print_warning() { echo -e "\${YELLOW}[!]\${NC} \$1"; }
print_error() { echo -e "\${RED}[✗]\${NC} \$1"; }

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║     SiteKit Agent Bootstrap Script       ║"
echo "╚══════════════════════════════════════════╝"
echo ""

# Check if running as root
if [ "\$EUID" -ne 0 ]; then
    print_error "Please run as root (sudo bash)"
    exit 1
fi

# Detect architecture
ARCH=\$(uname -m)
case \$ARCH in
    x86_64)  ARCH="amd64" ;;
    aarch64) ARCH="arm64" ;;
    armv7l)  ARCH="arm" ;;
    *)
        print_error "Unsupported architecture: \$ARCH"
        exit 1
        ;;
esac

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME=\$NAME
    OS_VERSION=\$VERSION_ID
    OS_ID=\$ID
else
    print_error "Cannot detect OS. /etc/os-release not found."
    exit 1
fi

print_status "Detected OS: \$OS_NAME \$OS_VERSION (\$ARCH)"

# Install minimal dependencies
print_status "Installing base dependencies..."
if command -v apt-get &> /dev/null; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq curl openssl ca-certificates jq > /dev/null 2>&1
elif command -v yum &> /dev/null; then
    yum install -y -q curl openssl ca-certificates jq epel-release > /dev/null 2>&1
elif command -v dnf &> /dev/null; then
    dnf install -y -q curl openssl ca-certificates jq > /dev/null 2>&1
fi
print_success "Base dependencies installed"

# Create SiteKit directories
print_status "Creating SiteKit directories..."
mkdir -p /opt/sitekit/{bin,config,logs,data}
chmod 755 /opt/sitekit
print_success "Directories created"

# =============================================
#  Create SiteKit System User
# =============================================

print_status "Creating sitekit system user..."
if ! id "sitekit" &>/dev/null; then
    useradd -m -s /bin/bash -d /home/sitekit sitekit
    # Add to sudo group for administrative tasks
    usermod -aG sudo sitekit 2>/dev/null || usermod -aG wheel sitekit 2>/dev/null || true
    # Allow sudo without password for sitekit
    echo "sitekit ALL=(ALL) NOPASSWD:ALL" > /etc/sudoers.d/sitekit
    chmod 440 /etc/sudoers.d/sitekit
    # Create .ssh directory
    mkdir -p /home/sitekit/.ssh
    chmod 700 /home/sitekit/.ssh
    touch /home/sitekit/.ssh/authorized_keys
    chmod 600 /home/sitekit/.ssh/authorized_keys
    # Create web directory for all web applications
    mkdir -p /home/sitekit/web
    # Set home dir to 755 so nginx can traverse to webroot
    chmod 755 /home/sitekit
    chmod 755 /home/sitekit/web
    chown -R sitekit:sitekit /home/sitekit
    print_success "sitekit user created with sudo access"
else
    print_warning "sitekit user already exists"
    mkdir -p /home/sitekit/web
    chmod 755 /home/sitekit
    chmod 755 /home/sitekit/web
    chown sitekit:sitekit /home/sitekit/web
fi

# Get system information
print_status "Gathering system information..."
CPU_COUNT=\$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo 1)
MEMORY_MB=\$(free -m 2>/dev/null | awk '/^Mem:/{print \$2}' || echo 0)
DISK_GB=\$(df -BG / 2>/dev/null | awk 'NR==2 {print \$2}' | tr -d 'G' || echo 0)
IP_ADDRESS=\$(curl -s --connect-timeout 10 https://api.ipify.org 2>/dev/null || curl -s --connect-timeout 10 https://ifconfig.me 2>/dev/null || curl -s --connect-timeout 10 https://icanhazip.com 2>/dev/null || echo "unknown")

echo "  - IP Address: \$IP_ADDRESS"
echo "  - CPU Cores:  \$CPU_COUNT"
echo "  - Memory:     \$MEMORY_MB MB"
echo "  - Disk:       \$DISK_GB GB"

# Generate RSA key pair for agent
print_status "Generating RSA key pair..."
if [ ! -f /opt/sitekit/config/agent.key ]; then
    openssl genrsa -out /opt/sitekit/config/agent.key 4096 2>/dev/null
    openssl rsa -in /opt/sitekit/config/agent.key -pubout -out /opt/sitekit/config/agent.pub 2>/dev/null
    chmod 600 /opt/sitekit/config/agent.key
    chmod 644 /opt/sitekit/config/agent.pub
    print_success "RSA key pair generated"
else
    print_warning "RSA key pair already exists, reusing"
fi

PUBLIC_KEY=\$(cat /opt/sitekit/config/agent.pub)

# Register with SiteKit API
print_status "Registering with SiteKit..."

JSON_PAYLOAD=\$(jq -n \\
    --arg ip "\$IP_ADDRESS" \\
    --arg key "\$PUBLIC_KEY" \\
    --arg os_name "\$OS_NAME" \\
    --arg os_version "\$OS_VERSION" \\
    --argjson cpu "\$CPU_COUNT" \\
    --argjson mem "\$MEMORY_MB" \\
    --argjson disk "\$DISK_GB" \\
    '{ip_address: \$ip, public_key: \$key, os_name: \$os_name, os_version: \$os_version, cpu_count: \$cpu, memory_mb: \$mem, disk_gb: \$disk}')

# Make API call with HTTP status code capture
HTTP_CODE=\$(curl -s -w "%{http_code}" --connect-timeout 30 -X POST "{$callbackUrl}" \\
    -H "Content-Type: application/json" \\
    -H "ngrok-skip-browser-warning: true" \\
    -d "\$JSON_PAYLOAD" \\
    -o /tmp/sitekit_response.json)

RESPONSE=\$(cat /tmp/sitekit_response.json 2>/dev/null)
rm -f /tmp/sitekit_response.json

# Validate HTTP status code
if [ "\$HTTP_CODE" != "200" ]; then
    print_error "API request failed with HTTP status: \$HTTP_CODE"
    echo "\$RESPONSE"
    exit 1
fi

# Validate JSON response using jq
if ! echo "\$RESPONSE" | jq -e '.success == true' > /dev/null 2>&1; then
    print_error "Failed to register with SiteKit:"
    echo "\$RESPONSE" | jq . 2>/dev/null || echo "\$RESPONSE"
    exit 1
fi
print_success "Server registered successfully"

# Extract values using jq (reliable JSON parsing)
AGENT_TOKEN=\$(echo "\$RESPONSE" | jq -r '.agent_token // empty')
SERVER_ID=\$(echo "\$RESPONSE" | jq -r '.server_id // empty')
SAAS_URL=\$(echo "\$RESPONSE" | jq -r '.saas_url // empty')

if [ -z "\$AGENT_TOKEN" ]; then
    print_error "Failed to extract agent token from response"
    exit 1
fi

# Create agent configuration (minimal - database credentials added after provisioning)
print_status "Creating agent configuration..."
cat > /opt/sitekit/agent.yaml << EOF
# SiteKit Agent Configuration
# Generated: \$(date -u +"%Y-%m-%dT%H:%M:%SZ")

server_id: "\$SERVER_ID"
saas_url: "\$SAAS_URL"
agent_token: "\$AGENT_TOKEN"

# Intervals
poll_interval: "5s"
stats_interval: "60s"

# Logging
log_level: "info"
EOF
chmod 600 /opt/sitekit/agent.yaml
print_success "Configuration created"

# Download and install agent binary
print_status "Downloading SiteKit agent..."
AGENT_URL="{$agentDownloadUrl}/sentinel-linux-\${ARCH}"

# Stop existing agent if running (for re-install case)
systemctl stop sitekit-agent.service > /dev/null 2>&1 || true

# Try to download agent binary from GitHub
if curl -sL --connect-timeout 30 -o /opt/sitekit/bin/sentinel "\$AGENT_URL" 2>/dev/null && [ -s /opt/sitekit/bin/sentinel ]; then
    if file /opt/sitekit/bin/sentinel | grep -q "ELF"; then
        print_success "Agent downloaded successfully"
    else
        print_error "Downloaded file is not a valid binary"
        rm -f /opt/sitekit/bin/sentinel
    fi
else
    print_error "Failed to download agent binary"
fi

# Fallback: check if binary was pre-installed
if [ ! -f /opt/sitekit/bin/sentinel ] || [ ! -s /opt/sitekit/bin/sentinel ]; then
    if [ -f /opt/sitekit/sentinel ]; then
        mv /opt/sitekit/sentinel /opt/sitekit/bin/sentinel
        print_warning "Using pre-installed agent binary"
    else
        print_warning "No agent binary available. You can manually install it later."
    fi
fi

if [ -f /opt/sitekit/bin/sentinel ]; then
    chmod +x /opt/sitekit/bin/sentinel
fi

# Create systemd service
print_status "Installing systemd service..."
cat > /etc/systemd/system/sitekit-agent.service << EOF
[Unit]
Description=SiteKit Agent
Documentation=https://sitekit.io/docs
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=/opt/sitekit
ExecStart=/opt/sitekit/bin/sentinel
Restart=always
RestartSec=10
StandardOutput=append:/opt/sitekit/logs/agent.log
StandardError=append:/opt/sitekit/logs/agent.log

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
print_success "Systemd service installed"

# Enable and start the service
print_status "Starting SiteKit agent..."
systemctl enable sitekit-agent.service > /dev/null 2>&1
systemctl start sitekit-agent.service

# Wait a moment and check status
sleep 2
if systemctl is-active --quiet sitekit-agent.service; then
    print_success "SiteKit agent is running"
else
    print_warning "Agent service may not be running. Check logs at /opt/sitekit/logs/agent.log"
fi

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║       Bootstrap Complete!                ║"
echo "╚══════════════════════════════════════════╝"
echo ""
print_success "Server ID: \$SERVER_ID"
print_success "Agent logs: /opt/sitekit/logs/agent.log"
echo ""
print_status "Software installation will begin automatically..."
print_status "Monitor progress in your SiteKit dashboard"
echo ""
BASH;
    }
}
