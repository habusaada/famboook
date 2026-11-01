#!/usr/bin/env bash
# Famboook Next.js — frontend build step, kept for the runbooks that call it (docs/08 §20 D).
#
#   deploy/scripts/deploy-frontend.sh <release-id>
#
# FB-DEVOPS-01 compatibility change: this script used to run `npm ci`, a plain
# `npm run build` and `sudo systemctl restart famboook-frontend`. A plain build
# empties frontend/.next/, which deletes the isolated releases served from
# .next/<id>. It now only builds an isolated release through build-frontend.sh.
# Install dependencies, smoke-test and switch as separate steps:
# docs/deployment/FB-DEVOPS-01-ISOLATED-FRONTEND-BUILDS.md
set -euo pipefail

exec bash "$(dirname "${BASH_SOURCE[0]}")/build-frontend.sh" "$@"
