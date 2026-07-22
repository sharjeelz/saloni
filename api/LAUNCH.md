# Launch checklist — getting a real salon live

Everything you need to configure before putting Salooni in front of a paying salon.
Two parts: **configure the server once**, then **onboard each salon**.

---

## 1. Server configuration (once)

### Backend (`api/.env`)

```dotenv
APP_ENV=production
APP_DEBUG=false
# SECURITY: never set OTP_EXPOSE_DEBUG_CODE in production — it returns the
# plaintext OTP in API responses (account-takeover). Local/dev/test only.
APP_URL=https://api.yourdomain.com        # must be the real public URL — logo/QR URLs derive from it

# Database (Postgres)
DB_CONNECTION=pgsql
DB_HOST=... DB_PORT=5432 DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=...

# SMS — ONE Salooni account, shared by all salons (we resell; cost is priced into the plan)
SMS_API_GATEWAY=msegat                     # or your KSA gateway
SMS_API_USERNAME=...
SMS_API_KEY=...
SMS_API_SENDER=Salooni                     # registered CITC sender name
# Until these are set, SMS/OTP fall back to the log driver (code written to storage/logs).

# AI menu onboarding (photo of price list -> services). Without it, "Import from menu" returns a clean 422.
ANTHROPIC_API_KEY=sk-ant-...
# ANTHROPIC_MENU_MODEL=claude-sonnet-5     # optional override

# WhatsApp notifications (OPTIONAL — SMS is the default). Salooni's own number; salons do nothing.
# WHATSAPP_TOKEN=...
# WHATSAPP_PHONE_NUMBER_ID=...

# Subscription billing — salon pays Salooni (under our CR). Customer↔salon money stays cash/pay-at-salon.
PAYMENT_GATEWAY=moyasar                    # 'manual' approves all (dev only)
# MOYASAR_SECRET_KEY=...

# One active booking per phone (0/blank = unlimited)
BOOKING_MAX_ACTIVE_PER_CUSTOMER=1
```

Then:

```bash
cd api
composer install --no-dev --optimize-autoloader
php artisan key:generate          # if APP_KEY not set
php artisan migrate --force
php artisan storage:link          # REQUIRED — serves uploaded logos from /storage
php artisan config:cache
```

### Scheduler (cron) — reminders + subscription renewals

Add one system cron entry so Laravel's scheduler runs:

```cron
* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

This drives `appointments:send-reminders` (every 15 min) and `subscriptions:renew` (daily).

### Frontend (`api/web/.env.local`)

```dotenv
NEXT_PUBLIC_API_URL=https://api.yourdomain.com/api
```

```bash
cd api/web
npm ci
npm run build && npm start        # or deploy the build
```

### Super-admin (cross-tenant oversight)

```bash
php artisan salon:super-admin you@yourdomain.com
```

---

## 2. Onboarding a salon (per salon, ~5–10 min)

1. **Create the account** — owner signs up (email + password, or phone OTP) → their salon is provisioned.
2. **Settings** — set timezone (Asia/Riyadh), default language (ar/en), brand colour, upload the **logo**, choose the notifications channel (SMS or WhatsApp).
3. **Branch** — add the location + set **weekly working hours** (a branch with no hours has no available slots).
4. **Team** — invite staff (they log in by phone OTP).
5. **Services** — the fast path: **Services → Import from menu** → upload a photo of their price list (Arabic, English, or both) → review/edit → add. Or add services manually.
6. **Make staff bookable** — a staff member only appears in availability once assigned to **both** a branch (Branches → Assign staff) **and** the service (Services → Assign staff). This is the #1 "why are there no slots?" cause.
7. **Share** — Settings → *Your booking page* → copy the link or print the **QR code**. Customers book online from there.

### Smoke test before handing it over
Open the public link → pick a service → pick a time → enter a phone → confirm.
The booking should appear on the **Calendar** the same day; mark it done/no-show/cancel to confirm the loop.

---

## Notes
- **SMS sender name** must be registered with the operator (CITC) before real sends. All salons send from the one Salooni sender; the message body names the salon.
- **Payments:** customers pay the salon the way they already do (cash / the salon's own mada). Salooni is not in that flow — only the salon's subscription runs through a gateway.
- **AV on Windows dev:** if `php artisan serve` dies with "Failed opening required server.php", re-run `composer install` and add an antivirus exclusion for the repo.
