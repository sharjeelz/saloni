# Deploying Salooni to Oracle Cloud (OCI)

Production stack: **one Ampere A1 VM running Docker Compose** — Postgres, the
Laravel API, a queue worker, the scheduler, the Next.js frontend, and Caddy
(automatic HTTPS). Images are built by GitHub Actions for **linux/arm64**,
pushed to GHCR, and rolled out over SSH. This is production-ready for early
scale; move Postgres to a managed instance and the app behind a load balancer
when traffic demands it.

```
DNS ─▶ VM.Standard.A1.Flex (Docker Compose)          volumes
       ├─ caddy      :80/:443 auto-TLS                caddy_data
       ├─ api        Laravel (:8080)                  uploads  ◀─ logos/offers
       ├─ worker     queue:work                       db_data  ◀─ Postgres
       ├─ scheduler  schedule:work
       ├─ web        Next.js (:3000)
       └─ db         postgres:16
```

> **Architecture note.** The host is ARM64. Every base image we use
> (`serversideup/php`, `node:22-alpine`, `postgres:16-alpine`, `caddy:2-alpine`)
> publishes arm64, and CI builds `platforms: linux/arm64` under QEMU. If you ever
> move to an x86 host, flip that line in
> [`deploy-staging.yml`](../.github/workflows/deploy-staging.yml).

---

## 1. Oracle Cloud setup (once)

1. **Region** — `me-jeddah-1` (Jeddah) for lowest KSA latency. Note **PDPL**
   applies: you store customers' phone numbers, so keeping data in-Kingdom is
   the safer default.
2. **Compute instance** — Ubuntu 24.04 LTS, shape **`VM.Standard.A1.Flex`** with
   **4 OCPU / 24 GB**, boot volume **50 GB**. Upload your SSH public key at
   creation; the default login user is `ubuntu`.

   > **"Out of host capacity" is the most common blocker.** A1 capacity in
   > Jeddah is frequently exhausted. Retry over a few hours, or try
   > `me-abudhabi-1` / `me-dubai-1`. Oracle does not queue requests — you just
   > retry the launch.

3. **Reserved public IP** — the ephemeral IP changes on stop/start. Under
   *Instance → Attached VNICs → IPv4 Addresses*, edit the public IP and switch it
   to **Reserved**, so DNS stays valid across reboots.
4. **VCN Security List** — *Networking → VCN → Subnet → Security List*, add
   **ingress** rules:
   - `0.0.0.0/0` → TCP **443**
   - `0.0.0.0/0` → TCP **80** (Caddy needs it for the ACME challenge)
   - **your IP only** → TCP **22**

   Postgres is never exposed — it stays on the private Docker network.

5. **Host firewall — do not skip this.** Unlike EC2, an Ubuntu image on OCI ships
   with iptables rules that **drop 80/443 even after you open the Security
   List**. This is the single most common "my Security List is right but nothing
   loads" cause. On the box:

   ```bash
   sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 80 -j ACCEPT
   sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport 443 -j ACCEPT
   sudo netfilter-persistent save
   ```

   Both layers must allow the traffic. Verify from your laptop with
   `nc -vz <public-ip> 443` before debugging anything else.

6. *(Optional)* **Object Storage bucket** for off-box DB backups — see §5.

## 2. Bootstrap the host (once, over SSH)

```bash
ssh ubuntu@<public-ip>

# Install Docker Engine + compose plugin (the script detects arm64)
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER   # re-login after this

# App directory
sudo mkdir -p /srv/salon/backups
sudo chown -R $USER:$USER /srv/salon

# Log in to GHCR so the host can pull private images.
# Use a Personal Access Token with read:packages (or make the packages public).
echo "$GHCR_PAT" | docker login ghcr.io -u YOUR_GH_USERNAME --password-stdin
```

Create the secrets file **`/srv/salon/.env`** from
[`.env.example`](.env.example) and fill in real values. Generate the app key
locally with `php artisan key:generate --show` and paste it into `APP_KEY`. Then
lock it down:

```bash
chmod 600 /srv/salon/.env
```

> The GitHub Action copies `docker-compose.yml` and `Caddyfile` to `/srv/salon`
> on every deploy — you don't place those by hand. Only `.env` and `backups/`
> are host-owned.

## 3. DNS

Two A records pointing at the **reserved public IP**:

- `api.yourdomain.com`
- `app.yourdomain.com`

Let them propagate *before* the first deploy — Caddy requests certificates on
first boot, and Let's Encrypt rate-limits repeated failures for a domain.

## 4. Configure GitHub (once)

Repo **Settings → Secrets and variables → Actions**:

| Kind     | Name                          | Value                                        |
|----------|-------------------------------|----------------------------------------------|
| Secret   | `STAGING_SSH_HOST`            | reserved public IP / hostname                |
| Secret   | `STAGING_SSH_USER`            | `ubuntu`                                      |
| Secret   | `STAGING_SSH_KEY`             | private key for that user                    |
| Variable | `STAGING_ENABLED`             | `true`                                        |
| Variable | `STAGING_NEXT_PUBLIC_API_URL` | `https://api.yourdomain.com/api`             |

`NEXT_PUBLIC_API_URL` is baked into the frontend **at build time**, so it lives
in CI, not the host `.env`.

## 5. First deploy

Push to `develop` (or run the **Deploy · Staging** workflow manually). It will:
build both arm64 images → push to GHCR → sync the manifests → `docker compose
pull` → `up -d` → migrate → cache config/routes/views → restart the worker.

> The first build is slow — arm64 under QEMU emulation, with `composer install`
> and `npm ci` as the expensive steps. Subsequent builds hit the `type=gha`
> layer cache and are much faster.

Then create your super-admin once:

```bash
cd /srv/salon
docker compose exec -T api php artisan salon:super-admin you@yourdomain.com
```

**Verify the deploy:**

```bash
cd /srv/salon
docker compose ps                       # all services Up / healthy
```

- `https://api.yourdomain.com/up` → Laravel health check
- `https://app.yourdomain.com` → the frontend loads
- Log in through the frontend → a successful round-trip proves DNS, TLS, CORS,
  the API, and Postgres are all wired correctly

If TLS fails, `docker compose logs caddy` names the reason — almost always DNS
not yet pointing at the box, or port 80 blocked by the host iptables rules (§1.5).

## 6. Backups (you own these — Postgres is self-hosted)

```bash
chmod +x /srv/salon/backup-db.sh
crontab -e
# 03:15 Riyadh daily (the box runs UTC):
15 0 * * * /srv/salon/backup-db.sh >> /srv/salon/backups/backup.log 2>&1
```

Set `BACKUP_S3_BUCKET` and the `BACKUP_S3_*` credentials in `.env` to also push
each dump to **OCI Object Storage**, which is S3-compatible — see the comments in
[`.env.example`](.env.example) for generating a Customer Secret Key and finding
your namespace. **Restore:**

```bash
gunzip -c backups/salon-YYYYMMDD-HHMMSS.sql.gz \
  | docker compose exec -T db psql -U salon -d salon
```

> Take one manual backup before every deploy until you trust the pipeline. The
> `db_data` Docker volume also survives `compose down` — only `down -v` destroys it.

Boot-volume backups are a second, cheaper safety net: enable an OCI **backup
policy** (Bronze = weekly) on the boot volume. That protects the whole box, not
just the database.

## 7. Operations

```bash
cd /srv/salon
docker compose ps                    # what's running
docker compose logs -f api           # tail API logs
docker compose logs -f caddy         # TLS / cert issues
docker compose restart api           # bounce a service
```

- **Deploys** are just a push to `develop`; the workflow is idempotent.
- **Rollback**: set `API_IMAGE`/`WEB_IMAGE` in `.env` to a `staging-<sha>` tag
  and `docker compose up -d`.
- **Zero-downtime note**: `up -d` recreates changed containers with a brief blip.
  Caddy retries, so it's near-seamless for a single box; add a second instance +
  a load balancer when you need true rolling deploys.

## Always Free budget

Each tenancy gets **3,000 OCPU hours** and **18,000 GB hours** per month for A1
instances. One 4 OCPU / 24 GB VM running continuously uses **2,920 OCPU hours**
and **17,520 GB hours** — inside the allowance, with roughly 3% headroom.

Consequences worth knowing:

- **Don't split the allowance** across two smaller VMs. Same consumption, extra
  OS overhead, and you'd have to shard the stack.
- **A second A1 instance pushes you over**, even a small or short-lived one.
- The two free `VM.Standard.E2.1.Micro` instances draw from a *separate* pool.
  At 1 GB RAM they can't run this stack, but one makes a fine uptime monitor.
- **Upgrade the tenancy to Pay As You Go.** Always Free resources stay free, and
  it stops Oracle from reclaiming instances the way it can on a free-only
  account.

## Growth path (when one box isn't enough)

1. **Postgres → OCI Database with PostgreSQL** — point `DB_HOST` at the managed
   endpoint, drop the `db` service. Gets you managed backups, PITR, and failover.
2. **Uploads → OCI Object Storage** — S3-compatible, so set `FILESYSTEM_DISK=s3`
   plus the `AWS_*` block with `AWS_ENDPOINT` set to your namespace's S3
   endpoint. Required before running more than one app instance (a local volume
   isn't shared).
3. **Redis (OCI Cache)** — switch `CACHE_STORE`/`SESSION_DRIVER`/
   `QUEUE_CONNECTION` to `redis` for lower DB load.
4. **Load Balancer + 2× instances** — real rolling deploys and HA. Note this
   leaves the Always Free tier.
