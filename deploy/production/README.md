# AWS Production Deployment

This is the minimum-cost, single-EC2 control-plane stack: Caddy, PHP-FPM, a queue worker, a scheduler, and MySQL. Redis is not required. The Sail definition remains development-only.

The image contains production Composer dependencies and compiled Vite/Filament assets. Source code is not bind-mounted. MySQL, Laravel storage, and Caddy certificate state live on a separately mounted, retained EBS volume. Only Caddy publishes TCP 80/443 and UDP 443.

## Host Prerequisites

- A dedicated Linux EC2 host with Docker Engine enabled at boot and Docker Compose **2.30 or newer** (raw environment-file support).
- AWS CLI v2, `jq`, Bash, `mountpoint` and `flock` from util-linux, GNU tar, gzip, and `sha256sum`.
- This repository and its lockfiles at `/opt/sitekit`, or another permanent path without spaces. Build on EC2 or in CI for the EC2 CPU architecture, not implicitly for a different development-machine architecture. Building images needs more memory/disk than running them; check available capacity before using a small instance.
- An instance role with access to the dedicated SSM parameter path and backup S3 prefix. Do not install long-lived AWS access keys on the host.
- An S3 bucket with public access blocked, encryption enabled, and a suitable retention/lifecycle policy. Uploads request SSE-S3 (`AES256`); adapt this deliberately if the bucket requires a customer KMS key instead.
- DNS for the configured hostname pointing to the instance. Use a stable address or update DNS after stop/start. Allow inbound TCP 80/443 and optionally UDP 443, but not MySQL, PHP-FPM, or development ports. Keep SSH restricted or use SSM Session Manager.

### Retained EBS Volume

Attach the planned encrypted data volume and ensure it is not deleted on instance termination. Identify it by its EBS volume ID/device mapping, not by assuming a particular NVMe device number:

```bash
lsblk -f
sudo blkid
```

Format **only a confirmed new, empty data volume**, never the root volume or a volume with existing data. For a new filesystem, an operator can run `sudo mkfs.ext4 /dev/CONFIRMED_DATA_DEVICE` after verifying the mapping. On an existing volume, skip formatting.

Create the mountpoint, then add the verified filesystem UUID to `/etc/fstab`:

```bash
sudo mkdir -p /srv/sitekit-data
```

```fstab
UUID=VERIFIED_DATA_FILESYSTEM_UUID /srv/sitekit-data ext4 defaults,nofail 0 2
```

```bash
sudo mount /srv/sitekit-data
mountpoint /srv/sitekit-data
findmnt /srv/sitekit-data
```

The mountpoint must be the filesystem mount itself, not a subdirectory on the root filesystem. The host script refuses to operate if it is not mounted, and Compose refuses to create missing bind directories. Systemd also requires this mount before Docker starts.

## Configuration And Secrets

From the repository on the EC2 host:

```bash
sudo install -d -m 0700 /etc/sitekit
sudo install -m 0600 deploy/production/host.env.example /etc/sitekit/host.env
sudoedit /etc/sitekit/host.env
```

Replace the example region, SSM path, domain, URL, bucket, and mail settings with the approved values. This is a root-owned **shell configuration file**; quote values containing spaces. Do not place secrets here. `APP_URL` must be exactly `https://SITEKIT_DOMAIN`, with no trailing slash.

The proxy network defaults to `172.30.42.0/24`. Automatic container addresses use `172.30.42.128/25`, leaving Caddy's fixed `172.30.42.2` outside that pool. If this conflicts with host/VPC/VPN networks, change `SITEKIT_PROXY_SUBNET`, `SITEKIT_CONTAINER_IP_RANGE`, and `SITEKIT_PROXY_IP` together. Laravel trusts only this Caddy address, never `*`.

Create these **SecureString** parameters directly in SSM, using the AWS console or your secure provisioning process. Under the default path their names are:

| Parameter | Requirement |
| --- | --- |
| `/sitekit/production/APP_KEY` | Persistent Laravel AES-256 key: `base64:` followed by the base64 encoding of 32 bytes. Preserve an existing key when migrating encrypted application data. |
| `/sitekit/production/DB_PASSWORD` | Random application database password, at least 20 characters. |
| `/sitekit/production/MYSQL_ROOT_PASSWORD` | Distinct random root password, at least 20 characters; never passed to PHP containers. |
| `/sitekit/production/MAIL_USERNAME` and `/MAIL_PASSWORD` under the same path | Credentials if required by the selected delivery mailer. |
| `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITLAB_CLIENT_ID`, `GITLAB_CLIENT_SECRET`, `BITBUCKET_CLIENT_ID`, `BITBUCKET_CLIENT_SECRET` under the same path | The providers you enable. Configure their HTTPS callback URLs for the public hostname. |
| Other uppercase environment-variable names under the same path | Optional provider/API secrets supported by the application. |

All parameter names must be immediate children of the configured path; nested names and multiline/NUL-containing values are rejected. Database passwords must be printable ASCII without spaces, single/double quotes, or backslashes because of the MySQL image's initialization handling. Random hex passwords are a suitable choice. OAuth/API secrets can retain quotes and dollar signs literally.

For a brand-new installation, generate the APP_KEY once with a trusted tool and store it in SSM. Do **not** regenerate it at image build, boot, or restore. Preserve this parameter independently of database backups: encrypted credentials and sessions cannot be recovered with a different key. Changing SSM database passwords does not change existing MySQL users; coordinate SQL credential rotation separately, then redeploy.

The loader uses the instance role and automatic AWS CLI pagination. It renders mode-0600 runtime files under `/run/sitekit` without printing values. Containers receive raw values, and Laravel configuration is cached at container startup, not during image construction. Root and Docker administrators can still inspect container environments; restrict host access accordingly.

### Instance Role Permissions

Use your account, region, bucket, path, and optional customer KMS key in these narrowly scoped permissions:

| Action | Resource / Condition |
| --- | --- |
| `ssm:GetParametersByPath` | `arn:aws:ssm:REGION:ACCOUNT:parameter/sitekit/production/*` |
| `kms:Decrypt` | The customer key used by SSM, if applicable; restrict via the regional SSM service and encryption context. |
| `s3:PutObject`, `s3:GetObject`, `s3:AbortMultipartUpload` | `arn:aws:s3:::BACKUP_BUCKET/control-plane/*` |
| `s3:ListBucket` | `arn:aws:s3:::BACKUP_BUCKET`, restricted to `control-plane/*` with `s3:prefix`. |

The runtime role does not need permission to create parameters, delete backups, or provision infrastructure. Avoid granting recursive access to a broader SSM parent path. Configure the bucket lifecycle separately to control retention and incomplete multipart uploads.

## First Deployment

The installer gates the **entire Docker daemon** on the data mount and SSM loader. Use this on the dedicated SiteKit EC2 host, not a shared Docker host with unrelated workloads. It installs a Docker drop-in, a SiteKit boot service, and a daily 02:00 UTC backup timer with a small randomized delay.

```bash
cd /opt/sitekit
sudo bash deploy/production/sitekit.sh install
sudo bash deploy/production/sitekit.sh deploy
sudo systemctl start sitekit.service sitekit-backup.timer
sudo systemctl status sitekit.service sitekit-backup.timer --no-pager
curl --fail https://YOUR_SITEKIT_DOMAIN/up
```

Deployment loads secrets, builds both image targets, waits for MySQL, runs migrations once, and starts the application/worker/scheduler/proxy. Existing installations enter maintenance mode during deployment. A failed migration leaves writers stopped for investigation; the script does not silently continue or roll back data.

Use the root-only runtime environment for administrative commands:

```bash
sudo docker compose --env-file /etc/sitekit/host.env \
    -f docker-compose.production.yml exec app php artisan sitekit:make-user
```

This command creates the sole verified operator and refuses to add a second user. Enable two-factor authentication immediately after signing in. Public registration and team invitations are disabled. The example SMTP values are placeholders, not working mail delivery. OAuth redirects, webhooks, and managed-agent callbacks must use the public HTTPS hostname.

For updates, run `sudo bash deploy/production/sitekit.sh deploy` from the intended checkout. Do not run Sail or the development Compose definition on this host. Do not run `key:generate`, database seeders, or development migrations at startup.

## Stop / Start

Before stopping EC2, take a successful control-plane backup and stop the application cleanly:

```bash
sudo bash /opt/sitekit/deploy/production/sitekit.sh backup \
    && sudo systemctl stop sitekit.service \
    && sudo sync
```

Only stop the EC2 instance after this command succeeds. The backup prints its S3 URL, not secrets. On the next boot, systemd mounts the retained volume, loads SSM secrets **before Docker**, and starts SiteKit. This also brings back services explicitly stopped before shutdown, which `restart: unless-stopped` alone would not do.

Validate an actual EC2 stop/start before launch, including DNS, the `/up` endpoint, login, email delivery, queue processing, scheduler behavior, and retained MySQL/storage/certificate state. Inspect failures with:

```bash
sudo journalctl -u sitekit-secrets -u sitekit -u sitekit-backup --no-pager -n 100
```

## Backup And Restore

These backups protect **SiteKit itself**, independently of its managed-server backup feature. The timer and manual command use the same host script and operation lock.

Backup enters maintenance mode, gracefully stops PHP/queue/scheduler writers, dumps MySQL including routines/events/triggers, and archives Laravel storage. It excludes temporary cache/views/sessions and the maintenance marker. S3 receives the compressed dump, storage archive, and a versioned checksum manifest, uploaded last. An upload/dump failure attempts to resume the previously running writers and returns a failure exit code. This is a brief scheduled interruption, not an online zero-downtime backup.

Secrets and Caddy certificate state are not in the S3 archive. Retain SSM parameters; Caddy state remains on EBS and certificates can be reissued if that state is lost. Backup scratch files and old pre-restore storage also consume EBS capacity; monitor available space.

Record the printed backup URL. After verifying the target host, key, database, and source backup, restore explicitly:

```bash
sudo bash /opt/sitekit/deploy/production/sitekit.sh restore \
    s3://YOUR_BUCKET/control-plane/BACKUP_ID --confirm
```

**Restore replaces the complete SiteKit database and storage.** Take a pre-restore backup if the current state matters. The command downloads and validates the manifest, APP_KEY fingerprint, checksums, gzip, and archive paths/types before stopping the app. It then replaces the database, installs storage, runs any pending migrations, and recreates all application/Caddy containers so bind mounts point to the restored directories. Sessions are intentionally invalidated. Existing storage is retained under `storage.before-restore-*`; delete it only after recovery has been verified.

If import or migration fails after replacement begins, keep services stopped and investigate or retry with a known-good backup. The previous database is not automatically retained or rolled back. Recheck `/up`, login, encrypted provider/SSH credentials, stored files, and queue/scheduler jobs before returning to service.

Perform a real S3 restore drill on an isolated host/network before going live. Do not run a second restored control plane against the same managed servers. This verifies IAM, network access, S3 encryption policy, and actual production data, which local fake-AWS tests cannot prove.

## Local Verification

From the development checkout, with Docker, curl, jq, and Bash available:

```bash
bash tests/ProductionDeploymentTest.sh
php artisan test --compact --filter='EmailDeliveryConfigurationTest|EmailVerificationFlowTest'
docker compose --env-file deploy/production/host.env.example \
    -f docker-compose.production.yml config --no-env-resolution --quiet
docker run --rm --volume "$PWD:/mnt:ro" koalaman/shellcheck:stable \
    --severity=warning --exclude=SC1090 deploy/production/sitekit.sh \
    deploy/production/entrypoint.sh tests/ProductionDeploymentTest.sh
```

The disposable test uses separate containers, a unique network and temporary data directories, and a random loopback HTTPS port. It mocks AWS calls and Linux-only host checks/ownership/locking while exercising the actual production images, real MySQL migrations/dumps/imports, storage restore, and container restarts. It verifies missing/unmounted inputs, malformed secrets, failed S3 uploads, key mismatches, and corrupted backups without contacting AWS or changing the Sail stack. ShellCheck's SC1090 exclusion covers the intentional dynamic import of the root-owned host configuration.

Live SSM/S3 access, systemd boot ordering, Let's Encrypt issuance, EC2 stop/start, and production mail delivery still require verification on the configured AWS host.