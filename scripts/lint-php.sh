#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repository_root"

if [[ -d "$repository_root/.git" || -f "$repository_root/.git" ]]; then
    mapfile -d '' php_files < <(git -c "safe.directory=$repository_root" ls-files -z '*.php')
else
    mapfile -d '' php_files < <(find . -type f -name '*.php' -not -path './vendor/*' -not -path './build/*' -print0)
fi

for php_file in "${php_files[@]}"; do
    php -l "$php_file" >/dev/null
done

echo "PHP syntax valid for ${#php_files[@]} package files."
