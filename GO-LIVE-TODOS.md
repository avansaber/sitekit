## Go-Live Status

The repository-side production changes are implemented and locally tested. **AWS deployment is not complete:** the host configuration, retained-volume mount, IAM/SSM/S3 setup, and live operational drills below are still required.

See the [AWS deployment runbook](deploy/production/README.md) for commands, permissions, and recovery procedures. No AWS resources have been modified by this work.

### Implemented

- [x] [Canonical application image](Dockerfile) with PHP 8.4-FPM, Node 24 asset builds, production dependencies, and compiled Vite/Filament assets. Local secrets, data, and development tooling are excluded from builds.
- [x] [Separate production Compose stack](docker-compose.production.yml), leaving the local Sail definition unchanged while sharing PHP 8.4, Node 24, and MySQL 8.0 contracts.
- [x] `restart: unless-stopped` on app, queue, scheduler, MySQL, and Caddy, plus systemd startup for services explicitly stopped before EC2 shutdown.
- [x] Retained-volume binds for MySQL, Laravel storage, and Caddy state; mount guards prevent fallback writes to the root disk.
- [x] [Caddy HTTPS configuration](deploy/production/Caddyfile) as the only published service. Laravel trusts Caddy's fixed IP, kept outside Docker's automatic address pool.
- [x] Database-backed queue, cache, and sessions without a production Redis dependency.
- [x] [SSM startup/deployment automation](deploy/production/sitekit.sh), validated persistent APP_KEY/database credentials, private runtime environment files, and a Docker boot dependency on secrets and the data mount.
- [x] Control-plane MySQL/storage backups to S3, a daily timer, documented pre-stop backup, and explicit checksum/key-verified restore.
- [x] [Disposable integration test](tests/ProductionDeploymentTest.sh): real production containers/MySQL, fake SSM/S3, failed-upload recovery, corruption/key guards, database/storage restore, and container restart all pass.
- [x] Public registration and team invitations are disabled; the trusted `sitekit:make-user` command creates exactly one verified operator with a personal team.
- [x] Filament requires confirmed two-factor authentication before the operator can use management routes, while preserving access to the enrollment profile.
- [x] Agent provisioning callbacks require an unexpired, pending, single-use token and rotate it atomically to the permanent agent credential.
- [x] Focused authentication, email-delivery, and provisioning tests pass; production Compose and Caddy configurations validate.

### Before First Deployment

- [ ] Configure the real region, domain, mail settings, SSM path, bucket, and non-conflicting Docker address ranges in the root-owned host configuration.
- [ ] Confirm the retained encrypted EBS volume, filesystem, UUID-based `/etc/fstab` entry, mount, and retention settings. Never format an existing data volume.
- [ ] Create the persistent SecureString parameters and grant the instance role only the required SSM/KMS and backup-prefix S3 permissions. Do not copy local Sail credentials or regenerate an existing APP_KEY.
- [ ] Confirm S3 encryption/public-access/retention settings, DNS, security groups, Docker/Compose prerequisites, and image-build capacity/architecture.
- [ ] Install the boot units, deploy, create the sole operator with `sitekit:make-user`, enable two-factor authentication, and verify real email delivery, OAuth redirects, and managed-agent callbacks.
- [ ] Complete a real EC2 stop/start drill and verify retained database/storage/certificates, queue processing, and scheduling.
- [ ] Complete a real S3 backup/restore drill on an isolated host/network. Local tests do not verify live AWS IAM, SSM/S3 access, systemd ordering, or Let's Encrypt issuance.

### Existing Uncommitted Work

Existing local-development, email-verification, and migration-order changes were preserved. The pending [trusted-proxy configuration](bootstrap/app.php) is used with the production Caddy address, not wildcard trust. Nothing has been committed.