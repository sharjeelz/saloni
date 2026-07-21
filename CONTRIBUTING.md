# Contributing & Branching Strategy

## Branches

We use a lightweight trunk-based flow with a staging integration branch.

| Branch | Purpose | Deploys to |
| ------ | ------- | ---------- |
| `main` | Production-ready. Protected. Only merges via PR. | Production |
| `develop` | Integration branch for the current sprint. | Staging |
| `feature/*` | One story/task each, branched from `develop`. | — |
| `fix/*` | Bug fixes, branched from `develop` (or `main` for hotfixes). | — |
| `hotfix/*` | Urgent production fixes, branched from `main`. | Production |

Branch names reference the board ID where possible, e.g. `feature/E4-1-services-crud`.

## Workflow

1. Branch from `develop`: `git switch -c feature/E4-1-services-crud develop`
2. Commit in small, focused increments.
3. Open a PR into `develop`. CI (`.github/workflows/ci.yml`) must pass:
   - **API** — `php artisan test` + migrations validated on PostgreSQL
   - **Web** — `npm run lint` + `npm run build`
4. At least one review approval, then squash-merge.
5. At the end of a sprint, `develop` is merged into `main` and tagged (`v0.x`).

## Commit messages

Conventional-ish, imperative mood:

```
Add service CRUD endpoints (E4-1)

- ServiceController with index/store/update/destroy
- Tenant-scoped via BelongsToSalon
```

## Local setup

See [`README.md`](./README.md). In short: `composer install`, `npm ci` in `api/web`,
copy `.env.example` → `.env`, `php artisan key:generate`, `docker compose up -d db`,
`php artisan migrate`.
