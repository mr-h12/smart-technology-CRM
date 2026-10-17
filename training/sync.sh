#!/bin/sh
# Copies training scripts into worker-pdf, runs one, copies shots back.
# usage: training/sync.sh <port> walk/<script>.js [args]
set -e
cd "$(dirname "$0")"
docker exec crm-worker-pdf mkdir -p /tmp/training/walk
docker cp run.js crm-worker-pdf:/tmp/training/run.js >/dev/null
docker cp walk/. crm-worker-pdf:/tmp/training/walk >/dev/null
docker exec -w /tmp/training crm-worker-pdf node run.js "$@"
docker cp crm-worker-pdf:/tmp/training/shots/. shots/ >/dev/null
