#!/usr/bin/env bash
# PostgreSQL Compatibility Harness — Verification (LOCKED)
# Runs smoke test + unit tests. Exit 0 = all passed.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "=== Smoke test ==="
php smoke_test.php
echo ""

echo "=== Unit tests ==="
php vendor/bin/phpunit --no-coverage
echo ""

echo "=== Verification passed ==="
