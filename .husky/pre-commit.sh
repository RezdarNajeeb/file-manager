#!/bin/sh
. "$(dirname "$0")/_/husky.sh"

# Run lint-staged
./vendor/bin/sail npx --no-install lint-staged
