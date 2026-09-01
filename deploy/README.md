# Deploying Salooni to Oracle Cloud (OCI)

Production stack: **one Ampere A1 VM running Docker Compose** — Postgres, the
Laravel API, a queue worker, the scheduler, the Next.js frontend, and Caddy
(automatic HTTPS). The host holds a clone of this repo and **builds the images
itself**; GitHub Actions just SSHes in and says "pull and rebuild". No registry,
no cross-compilation. This is production-ready for early scale; move Postgres to
a managed instance and the app behind a load balancer when traffic demands it.

```
DNS ─▶ VM.Standard.A1.Flex (Docker Compose)          volumes
       ├─ caddy      :80/:443 auto-TLS                caddy_data
       ├─ api        Laravel (:8080)                  uploads  ◀─ logos/offers
       ├─ worker     queue:work                       db_data  ◀─ Postgres
       ├─ scheduler  schedule:work
       ├─ web        Next.js (:3000)
       └─ db         postgres:16
```

> **Why build on the host?** The box is natively ARM64, so it compiles its own
> images faster than an x86 CI runner could cross-build them under emulation —
> and it removes a registry, its credentials, and the image-tagging dance
> entirely. The trade-off is a ~30-60s outage while containers rebuild and
> restart, and rollback means checking out an older commit rather than
> re-running a tagged image. For one box and one developer that is the right
> side of the trade. All four base images (`serversideup/php`,
> `node:22-alpine`, `postgres:16-alpine`, `caddy:2-alpine`) publish arm64, so
> nothing needs special handling.

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
```

Run [`bootstrap-host.sh`](bootstrap-host.sh), which does the five things the box
needs before Docker is any use — patch packages, extend the root filesystem to
the boot volume you paid for, open 80/443 in the **host** firewall, add swap so
the Next.js build cannot OOM-kill Postgres, and set the timezone so the
scheduler fires at Riyadh times. It then installs Docker. It is idempotent, so
re-running it is harmless:

From your laptop (the repo is private, so a raw GitHub URL would 404 — copy the
file over instead):

```bash
scp deploy/bootstrap-host.sh ubuntu@<public-ip>:~
ssh ubuntu@<public-ip> 'bash ~/bootstrap-host.sh'
```

Then **log out and back in** — the `docker` group only takes effect in a new
session. Confirm with `docker run --rm hello-world` (no `sudo`).

> The script refuses to run on anything but Ubuntu. On Oracle Linux — the OCI
> default image — SELinux blocks Docker bind-mounts, podman shadows `docker`,
> and firewalld replaces iptables. Recreate the instance with the **Canonical
> Ubuntu 24.04** image rather than adapting.

**Give the host read access to the repo.** It clones a private repo, so generate
a keypair and register the public half as a GitHub **deploy key**:

```bash
ssh-keygen -t ed25519 -C "salooni-oracle-host" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
```

Paste that into **repo Settings -> Deploy keys -> Add deploy key**. Leave *Allow
write access* **unchecked** — the host only ever reads. Then clone:

```bash
sudo mkdir -p /srv && sudo chown $USER:$USER /srv
git clone git@github.com:YOUR_ORG/salooni.git /srv/salon
cd /srv/salon && git checkout develop
mkdir -p /srv/salon/backups
```

Create the secrets file **`/srv/salon/deploy/.env`** from
[`.env.example`](.env.example) and fill in real values. Generate the app key
locally with `php artisan key:generate --show` and paste it into `APP_KEY`. Then
lock it down:

```bash
chmod 600 /srv/salon/deploy/.env
```

> `deploy/.env` is gitignored, so the deploy's `git reset --hard` never touches
> it. `.env` and `backups/` are the only host-owned things; everything else in
> `/srv/salon` is a checkout the deploy overwrites.

## 3. DNS

Two A records pointing at the **reserved public IP**:

- `api.yourdomain.com`
- `app.yourdomain.com`

Let them propagate *before* the first deploy — Caddy requests certificates on
first boot, and Let's Encrypt rate-limits repeated failures for a domain.

## 4. Configure GitHub (once)

Repo **Settings → Secrets and variables → Actions**:

| Kind     | Name                  | Value                          |
|----------|-----------------------|--------------------------------|
| Secret   | `STAGING_SSH_HOST`    | reserved public IP / hostname  |
| Secret   | `STAGING_SSH_USER`    | `ubuntu`                        |
| Secret   | `STAGING_SSH_KEY`     | private key for that user      |
| Variable | `STAGING_ENABLED`     | `true`                          |

That is all CI needs — it builds nothing, so there are no registry credentials
and no build arguments here. `NEXT_PUBLIC_API_URL` lives in the host
`deploy/.env`, because that is where the build now happens.

> `STAGING_SSH_KEY` is the key for logging **into** the box. It is a different
> key from the deploy key in section 2, which is how the box authenticates **to
> GitHub**. Do not reuse one for both.

## 5. First deploy

Push to `develop` (or run the **Deploy · Staging** workflow manually). It will
SSH to the host, `git reset --hard origin/develop`, `docker compose up -d
--build`, migrate, cache config/routes/views, and restart the worker.

> The first build is slow — `composer install` and `npm run build` from scratch,
> perhaps 5-10 minutes. Later deploys reuse Docker's layer cache and rebuild
> only the layers whose inputs changed.

You can also deploy by hand at any time, which is the fastest way to debug a
failing rollout:

```bash
cd /srv/salon && git pull && cd deploy && docker compose up -d --build
```

Then create your super-admin once:

```bash
cd /srv/salon/deploy
docker compose exec -T api php artisan salon:super-admin you@yourdomain.com
```

**Verify the deploy:**

```bash
cd /srv/salon/deploy
docker compose config                   # resolves build paths + .env; run this first
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
chmod +x /srv/salon/deploy/backup-db.sh
crontab -e
# 03:15 Riyadh daily (the box runs UTC):
15 0 * * * /srv/salon/deploy/backup-db.sh >> /srv/salon/backups/backup.log 2>&1
```

Set `BACKUP_S3_BUCKET` and the `BACKUP_S3_*` credentials in `.env` to also push
each dump to **OCI Object Storage**, which is S3-compatible — see the comments in
[`.env.example`](.env.example) for generating a Customer Secret Key and finding
your namespace. **Restore:**

```bash
cd /srv/salon/deploy
gunzip -c /srv/salon/backups/salon-YYYYMMDD-HHMMSS.sql.gz \
  | docker compose exec -T db psql -U salon -d salon
```

> Take one manual backup before every deploy until you trust the pipeline. The
> `db_data` Docker volume also survives `compose down` — only `down -v` destroys it.

Boot-volume backups are a second, cheaper safety net: enable an OCI **backup
policy** (Bronze = weekly) on the boot volume. That protects the whole box, not
just the database.

## 7. Operations

```bash
cd /srv/salon/deploy
docker compose ps                    # what's running
docker compose logs -f api           # tail API logs
docker compose logs -f caddy         # TLS / cert issues
docker compose restart api           # bounce a service
docker compose build --no-cache api  # force a clean rebuild
```

- **Deploys** are just a push to `develop`; the workflow is idempotent.
- **Rollback**: check out the last good commit and rebuild —
  `cd /srv/salon && git checkout <sha> && cd deploy && docker compose up -d --build`.
  Slower than swapping a tagged image; the price of building on the host.
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
