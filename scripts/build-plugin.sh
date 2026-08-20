#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="${1:-}"

PLUGINS=(
  "thumbnail-manager"
  "post-scheduler"
  "tumblr-auto-reposter"
  "reddit-to-wordpress"
  "pod-designer"
)

usage() {
  printf 'Usage: %s [plugin|all]\n\n' "$(basename "$0")"
  printf 'Plugins:\n'
  for plugin in "${PLUGINS[@]}"; do
    printf '  - %s\n' "$plugin"
  done
}

is_known_plugin() {
  local candidate="$1"
  local plugin

  for plugin in "${PLUGINS[@]}"; do
    if [[ "$candidate" == "$plugin" ]]; then
      return 0
    fi
  done

  return 1
}

choose_plugin() {
  local choice

  printf 'Select plugin to build:\n'
  printf '  1) thumbnail-manager\n'
  printf '  2) post-scheduler\n'
  printf '  3) tumblr-auto-reposter\n'
  printf '  4) reddit-to-wordpress\n'
  printf '  5) pod-designer\n'
  printf '  6) all\n'
  printf 'Choice: '
  read -r choice

  case "$choice" in
    1) PLUGIN="thumbnail-manager" ;;
    2) PLUGIN="post-scheduler" ;;
    3) PLUGIN="tumblr-auto-reposter" ;;
    4) PLUGIN="reddit-to-wordpress" ;;
    5) PLUGIN="pod-designer" ;;
    6) PLUGIN="all" ;;
    *) printf 'Invalid selection: %s\n' "$choice" >&2; exit 1 ;;
  esac
}

build_plugin() {
  local plugin="$1"
  local source_dir="$ROOT_DIR/$plugin"
  local zip_file="$ROOT_DIR/$plugin.zip"

  if [[ ! -d "$source_dir" ]]; then
    printf 'Missing plugin directory: %s\n' "$source_dir" >&2
    exit 1
  fi

  rm -f "$zip_file"
  (
    cd "$ROOT_DIR"
    zip -r "$zip_file" "$plugin" \
      -x '*.git*' \
      -x '*node_modules*' \
      -x '*.DS_Store' \
      -x '*.swp' \
      -x '*.zip'
  )

  printf 'Built %s\n' "$zip_file"
}

if [[ -z "$PLUGIN" ]]; then
  choose_plugin
fi

if [[ "$PLUGIN" == "-h" || "$PLUGIN" == "--help" ]]; then
  usage
  exit 0
fi

if [[ "$PLUGIN" == "all" ]]; then
  for plugin in "${PLUGINS[@]}"; do
    build_plugin "$plugin"
  done
  exit 0
fi

if ! is_known_plugin "$PLUGIN"; then
  printf 'Unknown plugin: %s\n\n' "$PLUGIN" >&2
  usage >&2
  exit 1
fi

build_plugin "$PLUGIN"
