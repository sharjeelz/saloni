#!/usr/bin/env bash
# One-time host preparation for the Salooni stack on an Oracle Cloud
# VM.Standard.A1.Flex running **Ubuntu 24.04**.
#
#   scp deploy/bootstrap-host.sh ubuntu@<public-ip>:~
#   ssh ubuntu@<public-ip> 'bash ~/bootstrap-host.sh'
#
# (The repo is private, so there is no raw-URL curl|bash form.)
# Safe to re-run: every step checks whether
# it has already been done. Installs Docker as the LAST step, because the steps
# before it (disk, firewall, swap) are what make Docker's first build succeed.
#
# What it deliberately does NOT do: clone the repo, write .env, or start the
# stack. Those need your deploy key and your secrets — see deploy/README.md §2.
set -euo pipefail

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
ok()  { printf '    \033[0;32m%s\033[0m\n' "$1"; }

if [ "$(id -u)" -eq 0 ]; then
  echo "Run this as the 'ubuntu' user, not root — it uses sudo where needed." >&2
  exit 1
fi

if ! grep -qi ubuntu /etc/os-release; then
  echo "This script targets Ubuntu. On Oracle Linux the package manager," >&2
  echo "default user, firewall and SELinux all differ — recreate the" >&2
  echo "instance with the Canonical Ubuntu 24.04 image instead." >&2
  exit 1
fi

# --- 1. Patch the base image -------------------------------------------------
# OCI images lag behind on security updates; do this before installing anything.
say "Updating system packages (this takes a few minutes)"
sudo apt-get update -qq
sudo DEBIAN_FRONTEND=noninteractive apt-get upgrade -y -qq
ok "packages current"

# --- 2. Grow the root filesystem ---------------------------------------------
# OCI enlarges the block device when you ask for a bigger boot volume, but does
# not extend the partition. Without this you silently lose the extra space and
# hit "no space left on device" mid-build.
say "Extending the root filesystem to the full boot volume"
if [ -x /usr/libexec/oci-growfs ]; then
  sudo /usr/libexec/oci-growfs -y || ok "already at full size"
else
  ok "oci-growfs not present; skipping"
fi
df -h / | tail -1

# --- 3. Open the host firewall -----------------------------------------------
# Ubuntu images on OCI ship iptables rules that drop everything but SSH,
# INDEPENDENTLY of the VCN security list. Both layers must allow 80/443 or
# connections simply hang with no error. This is the #1 OCI gotcha.
say "Opening ports 80 and 443 in the host firewall"
for port in 80 443; do
  if sudo iptables -C INPUT -m state --state NEW -p tcp --dport "$port" -j ACCEPT 2>/dev/null; then
    ok "port $port already open"
  else
    sudo iptables -I INPUT 6 -m state --state NEW -p tcp --dport "$port" -j ACCEPT
    ok "port $port opened"
  fi
done
# Without this the rules vanish on reboot and the site goes dark.
sudo netfilter-persistent save >/dev/null
ok "rules persisted across reboots"

# --- 4. Swap -----------------------------------------------------------------
# Not about capacity — 24 GB is plenty. It is a margin for the Next.js build,
# which spikes hard; without swap the OOM killer may pick Postgres instead.
say "Adding a 4 GB swap file"
if swapon --show | grep -q '/swapfile'; then
  ok "swap already active"
else
  sudo fallocate -l 4G /swapfile
  sudo chmod 600 /swapfile
  sudo mkswap /swapfile >/dev/null
  sudo swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || \
    echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
  ok "4 GB swap active and persisted"
fi

# --- 5. Timezone -------------------------------------------------------------
# The Laravel scheduler sends reminders and runs renewals on wall-clock times.
# Left at UTC, "daily at 03:15" would fire at 06:15 Riyadh.
say "Setting the timezone to Asia/Riyadh"
sudo timedatectl set-timezone Asia/Riyadh
ok "$(date)"

# --- 6. Docker ---------------------------------------------------------------
say "Installing Docker Engine + compose plugin"
if command -v docker >/dev/null 2>&1; then
  ok "docker already installed: $(docker --version)"
else
  curl -fsSL https://get.docker.com | sudo sh
  ok "$(docker --version)"
fi

# Lets you run docker without sudo — but only in a NEW login session.
if id -nG "$USER" | tr ' ' '\n' | grep -qx docker; then
  ok "$USER already in the docker group"
else
  sudo usermod -aG docker "$USER"
  ok "added $USER to the docker group"
fi

cat <<'EOF'

────────────────────────────────────────────────────────────────────
Host prepared. Two things before you continue:

  1. LOG OUT AND BACK IN.  Group membership only applies to new
     sessions, so `docker` without sudo will fail until you do.
     Verify with:  docker run --rm hello-world

  2. Then follow deploy/README.md §2 from "Give the host read access
     to the repo" — deploy key, clone to /srv/salon, write
     /srv/salon/deploy/.env.
────────────────────────────────────────────────────────────────────
EOF
