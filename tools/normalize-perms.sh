#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_USER="${SUDO_USER:-$(id -un)}"
TARGET_GROUP="www-data"
APPLY=0
FIX_OWNER=0

usage() {
  cat <<'EOF'
Normalize repository ownership and permissions for local collaborative development.

Defaults:
- Dry run (prints commands, does not change anything)
- Root directory: repository root (parent of this script)
- User: current user (or SUDO_USER if present)
- Group: www-data

Options:
  --apply              Apply changes (otherwise dry run)
  --fix-owner          Also run chown -R user:group on the full tree
  --root PATH          Target repository path
  --user NAME          Target owner user
  --group NAME         Target group (default: www-data)
  -h, --help           Show this help

Examples:
  tools/normalize-perms.sh
  tools/normalize-perms.sh --apply
  sudo tools/normalize-perms.sh --apply --fix-owner --user terrencelp --group www-data
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply)
      APPLY=1
      ;;
    --fix-owner)
      FIX_OWNER=1
      ;;
    --root)
      ROOT_DIR="$2"
      shift
      ;;
    --user)
      TARGET_USER="$2"
      shift
      ;;
    --group)
      TARGET_GROUP="$2"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 1
      ;;
  esac
  shift
done

if [[ ! -d "$ROOT_DIR" ]]; then
  echo "Root directory does not exist: $ROOT_DIR" >&2
  exit 1
fi

if ! getent group "$TARGET_GROUP" >/dev/null 2>&1; then
  echo "Group does not exist: $TARGET_GROUP" >&2
  exit 1
fi

run_cmd() {
  if [[ "$APPLY" -eq 1 ]]; then
    eval "$1"
  else
    echo "DRY RUN: $1"
  fi
}

echo "Normalization target"
echo "- Root: $ROOT_DIR"
echo "- User: $TARGET_USER"
echo "- Group: $TARGET_GROUP"
echo "- Apply: $APPLY"
echo "- Fix owner recursively: $FIX_OWNER"

echo

echo "Step 1: Optional ownership normalization"
if [[ "$FIX_OWNER" -eq 1 ]]; then
  run_cmd "chown -R \"$TARGET_USER:$TARGET_GROUP\" \"$ROOT_DIR\""
else
  echo "Skipping full ownership normalization (use --fix-owner to enable)."
fi

echo
echo "Step 2: Group normalization for target user-owned paths"
run_cmd "find \"$ROOT_DIR\" -xdev -user \"$TARGET_USER\" -exec chgrp \"$TARGET_GROUP\" {} +"

echo
echo "Step 3: Directory permissions (2775)"
run_cmd "find \"$ROOT_DIR\" -xdev -user \"$TARGET_USER\" -type d -exec chmod 2775 {} +"

echo
echo "Step 4: Executable files permissions (775)"
run_cmd "find \"$ROOT_DIR\" -xdev -user \"$TARGET_USER\" -type f -perm /111 -exec chmod 775 {} +"

echo
echo "Step 5: Non-executable files permissions (664)"
run_cmd "find \"$ROOT_DIR\" -xdev -user \"$TARGET_USER\" -type f ! -perm /111 -exec chmod 664 {} +"

echo
echo "Step 6: Post-check summary"
run_cmd "echo 'non-user-owned count:' && find \"$ROOT_DIR\" -xdev ! -user \"$TARGET_USER\" | wc -l"
run_cmd "echo 'non-${TARGET_GROUP}-group count:' && find \"$ROOT_DIR\" -xdev ! -group \"$TARGET_GROUP\" | wc -l"
run_cmd "echo 'world-writable regular files:' && find \"$ROOT_DIR\" -xdev -type f -perm -0002 | wc -l"
run_cmd "echo 'world-writable directories:' && find \"$ROOT_DIR\" -xdev -type d -perm -0002 | wc -l"
run_cmd "echo 'setuid files:' && find \"$ROOT_DIR\" -xdev -type f -perm -4000 | wc -l"
run_cmd "echo 'setgid directories:' && find \"$ROOT_DIR\" -xdev -type d -perm -2000 | wc -l"

echo
if [[ "$APPLY" -eq 1 ]]; then
  echo "Normalization applied."
else
  echo "Dry run complete. Re-run with --apply to execute."
fi
