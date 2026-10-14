#!/usr/bin/env bash
# F-26 · 1.1: the security headers nginx sends. Read-only against the running
# stack, so it can run without verify-boot.sh's teardown. Exit 1 on any miss.
set -uo pipefail

BASE="https://localhost:${HTTPS_PORT:-8443}"
FAIL=0
check() { if printf '%s' "$2" | grep -qiE "$3"; then echo "  ✓ $1"; else echo "  ✗ $1"; FAIL=1; fi; }

# `/` is the SPA; `/.env` is nginx's own 403, which only carries headers with `always`.
for path in / /.env; do
    h=$(curl -skI "$BASE$path")
    check "$path: Server names no version (§14.3)" "$h" '^server: nginx\s*$'
    check "$path: HSTS (SEC-14)" "$h" '^strict-transport-security: max-age=31536000\s*$'
    check "$path: Referrer-Policy" "$h" '^referrer-policy: same-origin\s*$'
    check "$path: CSP scripts from self only (SEC-13)" "$h" "^content-security-policy: .*script-src 'self' 'sha256-[A-Za-z0-9+/=]+';"
done

# The pre-paint theme script stays inline (ThemeFlashTest: no network before
# paint), so the CSP allows it by hash. An edit to the script without the nginx
# hash would be blocked in the browser and noticed nowhere else.
hash=$(curl -sk "$BASE/" | python3 -c 'import sys,re,hashlib,base64
m=re.search(r"<script>(.*?)</script>",sys.stdin.read(),re.S)
print(base64.b64encode(hashlib.sha256(m.group(1).encode()).digest()).decode() if m else "none")')
csp=$(curl -skI "$BASE/" | grep -i '^content-security-policy:')
check "CSP carries the served theme script's hash" "$csp" "'sha256-${hash//+/\\+}'"

exit $FAIL
