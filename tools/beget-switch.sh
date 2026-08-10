#!/usr/bin/env bash
set -eu

current=${1:?Current public directory is required}
next=${2:?Prepared public directory is required}
backup=${3:?Backup directory is required}
base=$(dirname "$current")

if [ "$(basename "$current")" != "public_html" ]; then
  printf 'Refusing unexpected current directory: %s\n' "$current" >&2
  exit 2
fi

if [ "$(dirname "$next")" != "$base" ] || [ "$(dirname "$backup")" != "$base" ]; then
  printf 'All directories must have the same parent\n' >&2
  exit 2
fi

if [ ! -d "$current" ] || [ ! -d "$next" ] || [ -e "$backup" ]; then
  printf 'Preflight failed: current/next/backup state is unsafe\n' >&2
  exit 2
fi

mv -- "$current" "$backup"
if mv -- "$next" "$current"; then
  printf 'SWITCHED %s -> %s\n' "$next" "$current"
  exit 0
fi

mv -- "$backup" "$current"
printf 'ROLLED_BACK %s -> %s\n' "$backup" "$current" >&2
exit 1
