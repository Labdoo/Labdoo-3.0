#!/usr/bin/env bash
set -euo pipefail

# Read-only snapshot of Drupal database queues and the SES event pipeline.
# Override DRUSH_BIN, AWS_REGION, and SES_EVENTS_QUEUE_URL for another host.
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRUSH_BIN="${DRUSH_BIN:-${PROJECT_ROOT}/vendor/bin/drush}"
AWS_REGION="${AWS_REGION:-eu-central-1}"
SES_EVENTS_QUEUE_URL="${SES_EVENTS_QUEUE_URL:-https://sqs.eu-central-1.amazonaws.com/578955585868/ses-events-queue}"

printf 'Supervisor snapshot: %s\n' "$(date --utc --iso-8601=seconds)"
printf 'AWS account: '
aws sts get-caller-identity --query Account --output text

printf '\nDrupal queues (current backlog, oldest age, active leases):\n'
"${DRUSH_BIN}" --root="${PROJECT_ROOT}/web" scr "${PROJECT_ROOT}/scripts/queue_supervisor.php"

printf '\nSES account counters (account-wide, not attributable to Drupal):\n'
aws sesv2 get-account --region "${AWS_REGION}" \
  --query '{SendingEnabled:SendingEnabled,ProductionAccessEnabled:ProductionAccessEnabled,SentLast24Hours:SendQuota.SentLast24Hours,Max24HourSend:SendQuota.Max24HourSend}' \
  --output table

printf '\nRecent SES aggregate delivery attempts (last 8 datapoints):\n'
aws ses get-send-statistics --region "${AWS_REGION}" \
  --query 'SendDataPoints | sort_by(@, &Timestamp)[-8:]' --output table

printf '\nSES event SQS backlog (SNS event notifications; read-only attributes):\n'
aws sqs get-queue-attributes --queue-url "${SES_EVENTS_QUEUE_URL}" \
  --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible ApproximateNumberOfMessagesDelayed \
  --region "${AWS_REGION}" --output table
