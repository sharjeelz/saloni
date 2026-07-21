# Salon Booking & Management SaaS (KSA)

Multi-tenant SaaS for salons in Saudi Arabia — online booking for customers,
appointment & branch management for salon owners and staff.

- **System document:** see the linked Artifact (MVP requirements, epics, sprint plan)
- **Sprint board:** see the linked Artifact (live agile tracker)

## Tech Stack

| Layer | Choice |
|-------|--------|
| Backend / API | Laravel (PHP 8.2) — `api/` |
| Frontend | Next.js (React) — `web/` |
| Database | PostgreSQL 16 (Docker) |
| Local infra | Docker Compose |

## Structure

```
salon-saas/
├── api/                # Laravel API
├── web/                # Next.js frontend + booking page/widget
├── docker-compose.yml  # PostgreSQL for local dev
└── README.md
```

## Getting started (local dev)

```bash
# 1. Start PostgreSQL
docker compose up -d

# 2. Backend (Laravel)
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve            # http://localhost:8000

# 3. Frontend (Next.js) — in a second terminal
cd web
npm install
npm run dev                  # http://localhost:3000
```

## Database connection (local)

| Setting | Value |
|---------|-------|
| Host | 127.0.0.1 |
| Port | 5432 |
| Database | salon |
| User | salon |
| Password | salon_secret |

## Sprint 0 — Foundation (in progress)

- [ ] S0-1 Repo, CI & branching strategy
- [ ] S0-2 Database schema & migration scaffold
- [ ] S0-3 Auth scaffold (email/phone OTP)
- [ ] S0-4 RTL base + AR/EN i18n framework
- [ ] S0-5 Staging deploy pipeline
