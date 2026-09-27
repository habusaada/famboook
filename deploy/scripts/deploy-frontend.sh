#!/usr/bin/env bash
# Famboook Next.js Staff Portal — build/restart TEMPLATE (docs/08 §20 D).
# Requires frontend/.env.production.local (see deploy/env/frontend.production.env.example).
# The process manager (systemd unit / pm2) runs: npx next start -H 127.0.0.1 -p 3000
set -euo pipefail

cd "$(dirname "$0")/../../frontend"

npm ci
npm run build                 # refuses to build without NEXT_PUBLIC_API_URL

# Restart the service (placeholder name):
sudo systemctl restart famboook-frontend
