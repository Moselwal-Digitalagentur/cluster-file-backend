#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Moselwal GmbH
# SPDX-License-Identifier: MIT

# Verifies that every PHP source file in Classes/, Tests/, and Configuration/
# carries a SPDX-FileCopyrightText + SPDX-License-Identifier header.
# A lean replacement for `reuse lint` when the tool is not available locally.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SCAN_PATHS=(
    "${PROJECT_ROOT}/Classes"
    "${PROJECT_ROOT}/Tests"
    "${PROJECT_ROOT}/Configuration"
    "${PROJECT_ROOT}/Build"
)

MISSING_COPYRIGHT=()
MISSING_LICENSE=()

while IFS= read -r -d '' file; do
    if ! head -10 "${file}" | grep -q "SPDX-FileCopyrightText"; then
        MISSING_COPYRIGHT+=("${file#${PROJECT_ROOT}/}")
    fi
    if ! head -10 "${file}" | grep -q "SPDX-License-Identifier"; then
        MISSING_LICENSE+=("${file#${PROJECT_ROOT}/}")
    fi
done < <(find "${SCAN_PATHS[@]}" -type f \( -name "*.php" -o -name "*.sh" \) -print0 2>/dev/null)

EXIT_CODE=0
if [ "${#MISSING_COPYRIGHT[@]}" -gt 0 ]; then
    echo "❌ Missing SPDX-FileCopyrightText in:"
    printf '   - %s\n' "${MISSING_COPYRIGHT[@]}"
    EXIT_CODE=1
fi
if [ "${#MISSING_LICENSE[@]}" -gt 0 ]; then
    echo "❌ Missing SPDX-License-Identifier in:"
    printf '   - %s\n' "${MISSING_LICENSE[@]}"
    EXIT_CODE=1
fi

if [ "${EXIT_CODE}" -eq 0 ]; then
    COUNT=$(find "${SCAN_PATHS[@]}" -type f \( -name "*.php" -o -name "*.sh" \) 2>/dev/null | wc -l | tr -d ' ')
    echo "✓ REUSE headers present in all ${COUNT} source files."
fi

exit "${EXIT_CODE}"
