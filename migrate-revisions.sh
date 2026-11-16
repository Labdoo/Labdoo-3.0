#!/bin/bash

# Migrate revisions for content types
if [ -z "$1" ]; then
  echo "Usage: ./migrate-revisions.sh <content_type> [options]"
  echo "Example: ./migrate-revisions.sh page --limit=10"
  exit 1
fi

CONTENT_TYPE=$1
shift

echo "Migrating $CONTENT_TYPE revisions..."
vendor/bin/drush labdoo-sync-revisions "$CONTENT_TYPE" "$@"
