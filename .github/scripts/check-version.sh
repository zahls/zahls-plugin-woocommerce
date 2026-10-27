#!/usr/bin/env bash
# Checks that the plugin header, ZAHLS_VERSION and the readme "Stable tag"
# carry the same version. With an argument (the release version), that must
# match too. Writes version=<version> to $GITHUB_OUTPUT when set.
set -euo pipefail

cd "$(dirname "$0")/../.."

header=$(grep -m1 -E '^\s*\*\s*Version:' woo-zahls-gateway.php | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')
constant=$(grep -m1 -E "define\(\s*'ZAHLS_VERSION'" woo-zahls-gateway.php | sed -E "s/.*'ZAHLS_VERSION',\s*'([^']+)'.*/\1/")
stable=$(grep -m1 -iE '^Stable tag:' readme.txt | sed -E 's/^[^:]*:\s*//' | tr -d '[:space:]')
expected=${1:-$header}

echo "Expected: $expected | Plugin header: $header | ZAHLS_VERSION: $constant | Stable tag: $stable"

if [ "$header" != "$expected" ] || [ "$constant" != "$expected" ] || [ "$stable" != "$expected" ]; then
  echo "::error::Version mismatch: plugin header, ZAHLS_VERSION and readme 'Stable tag' must all be $expected"
  exit 1
fi

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "version=$expected" >> "$GITHUB_OUTPUT"
fi
