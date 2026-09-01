#!/usr/bin/env bash
# Nightly Postgres backup for the Salooni stack.
# Dumps the `db` container to /srv/salon/backups, keeps 14 days, and (optionally)
# ships the dump to OCI Object Storage (S3-compatible). Wire it up via cron —
# see deploy/README.md.
set -euo pipefail

cd /srv/salon

# Load POSTGRES_* from the same env file the stack uses.
set -a; . ./.env; set +a

STAMP="$(date +%Y%m%d-%H%M%S)"
DIR="/srv/salon/backups"
FILE="${DIR}/salon-${STAMP}.sql.gz"
mkdir -p "$DIR"

# pg_dump inside the db container -> gzipped file on the host.
docker compose exec -T db pg_dump -U "${POSTGRES_USER}" "${POSTGRES_DB}" \
  | gzip > "$FILE"

echo "wrote ${FILE}"

# Keep 14 days locally.
find "$DIR" -name 'salon-*.sql.gz' -mtime +14 -delete

# Optional off-box copy to OCI Object Storage via its S3-compatible API.
# Set the BACKUP_S3_* block in .env to enable it (requires the aws CLI:
# `sudo apt-get install -y awscli`).
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
  AWS_ACCESS_KEY_ID="${BACKUP_S3_ACCESS_KEY_ID}" \
  AWS_SECRET_ACCESS_KEY="${BACKUP_S3_SECRET_ACCESS_KEY}" \
  aws s3 cp "$FILE" "s3://${BACKUP_S3_BUCKET}/db/$(basename "$FILE")" \
    --endpoint-url "${BACKUP_S3_ENDPOINT}" \
    --region "${BACKUP_S3_REGION}"
  echo "uploaded to ${BACKUP_S3_BUCKET}/db/"
fi
