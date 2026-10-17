#!/bin/sh
# Build HTML, render both PDFs in worker-pdf, copy them back, rasterize pages at 60 dpi.
set -e
cd "$(dirname "$0")"
python3 build.py
docker exec crm-worker-pdf mkdir -p /tmp/training/src /tmp/crm/resources
docker cp ../crm/resources/fonts crm-worker-pdf:/tmp/crm/resources/ >/dev/null
docker cp src/. crm-worker-pdf:/tmp/training/src >/dev/null
docker cp shots/. crm-worker-pdf:/tmp/training/shots >/dev/null
docker cp render.js crm-worker-pdf:/tmp/training/render.js >/dev/null
docker exec -u root crm-worker-pdf chown -R www-data /tmp/training /tmp/crm
docker exec -w /tmp/training crm-worker-pdf node render.js ar en
for l in ar en; do docker cp crm-worker-pdf:/tmp/training/sales-$l.pdf sales-$l.pdf >/dev/null; done
rm -f pages/*.png
for l in ar en; do pdftoppm -r 60 -png sales-$l.pdf pages/sales-$l; done
ls pages | wc -l
