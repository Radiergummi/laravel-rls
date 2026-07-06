#!/usr/bin/env bash
# Bring up Toxiproxy in front of the test Postgres (rls-pg on 5432): admin API on
# 127.0.0.1:8474 and a proxy listen port on 127.0.0.1:5433. Enables the bench latency sweep,
# which is skipped when the admin API or the proxied data path is unreachable. The harness
# creates the 'postgres' proxy (listen 0.0.0.0:5433 -> host.docker.internal:5432) at run time.
set -euo pipefail

docker rm -f rls-toxiproxy >/dev/null 2>&1 || true

docker run -d --name rls-toxiproxy \
  -p 8474:8474 -p 5433:5433 \
  ghcr.io/shopify/toxiproxy:latest

echo "toxiproxy up: admin 127.0.0.1:8474, proxy listen 127.0.0.1:5433 -> host.docker.internal:5432"
echo "the bench harness creates the 'postgres' proxy at run time"
echo "tear down with: docker rm -f rls-toxiproxy"
