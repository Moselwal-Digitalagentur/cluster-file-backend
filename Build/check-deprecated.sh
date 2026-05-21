#!/usr/bin/env bash

# SPDX-FileCopyrightText: 2026 Moselwal GmbH
# SPDX-License-Identifier: MIT

# Statischer Check gegen TYPO3-14-deprecated Symbole.
# Constitution Prinzip I: deprecated APIs sind nicht zulässig.
# Erweitere die Symbol-Liste in Build/deprecated-typo3-14.txt mit neuen Findings.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEPRECATED_LIST="${PROJECT_ROOT}/Build/deprecated-typo3-14.txt"
SCAN_PATHS=("${PROJECT_ROOT}/Classes" "${PROJECT_ROOT}/Configuration")

if [ ! -f "${DEPRECATED_LIST}" ]; then
    echo "❌ Missing ${DEPRECATED_LIST}"
    exit 1
fi

EXIT_CODE=0
while IFS= read -r symbol || [ -n "${symbol}" ]; do
    [ -z "${symbol}" ] && continue
    case "${symbol}" in
        \#*) continue ;;
    esac
    if grep -RIn --include='*.php' --include='*.yaml' --include='*.yml' \
        --fixed-strings "${symbol}" "${SCAN_PATHS[@]}" 2>/dev/null; then
        echo "❌ Deprecated symbol found: ${symbol}"
        EXIT_CODE=1
    fi
done <"${DEPRECATED_LIST}"

if [ "${EXIT_CODE}" -eq 0 ]; then
    echo "✓ No deprecated TYPO3 14 symbols found in scanned paths."
fi

exit "${EXIT_CODE}"
