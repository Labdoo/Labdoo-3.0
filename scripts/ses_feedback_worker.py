"""Audit SES notifications from a trusted SQS subscription, without storing mail PII.

The default command only inspects attributes. Receiving and acknowledging events
requires --apply. This worker never sends mail or changes recipient preferences.
"""

import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess


class FeedbackError(Exception):
    """Safe diagnostic code; never include a message body or provider stderr."""


def validate_config(config):
    """Bind the consumer to one account, region, queue, topic and private ledger."""
    if not isinstance(config, dict):
        raise FeedbackError('invalid_config')
    region = config.get('region', '')
    account = config.get('account', '')
    if not re.fullmatch(r'[a-z]{2}-[a-z]+-\d', region):
        raise FeedbackError('invalid_region')
    if not re.fullmatch(r'\d{12}', account):
        raise FeedbackError('invalid_account')
    base = f'https://sqs.{region}.amazonaws.com/{account}/'
    url = config.get('queue_url', '')
    if not url.startswith(base) or not re.fullmatch(r'[A-Za-z0-9_-]{1,80}', url[len(base):]):
        raise FeedbackError('invalid_queue')
    expected_arn = f'arn:aws:sqs:{region}:{account}:{url[len(base):]}'
    if config.get('queue_arn') != expected_arn:
        raise FeedbackError('queue_arn_mismatch')
    topic = config.get('topic_arn', '')
    if not re.fullmatch(r'arn:aws:sns:' + re.escape(region + ':' + account + ':') + r'[A-Za-z0-9_-]{1,256}', topic):
        raise FeedbackError('invalid_topic')
    ledger = Path(config.get('ledger', ''))
    if not ledger.is_absolute():
        raise FeedbackError('invalid_ledger')
    return dict(config)


def decode(body, expected_topic):
    """Accept SNS envelopes only; queue policy is the transport trust boundary."""
    try:
        wrapper = json.loads(body)
        if not isinstance(wrapper, dict) or wrapper.get('Type') != 'Notification':
            raise FeedbackError('invalid_envelope')
        if wrapper.get('TopicArn') != expected_topic:
            raise FeedbackError('unexpected_topic')
        event = json.loads(wrapper['Message'])
        if not isinstance(event, dict):
            raise FeedbackError('invalid_event')
        kind = event.get('eventType', event.get('notificationType'))
        if kind not in {'Send', 'Delivery', 'Bounce', 'Complaint', 'Reject'}:
            raise FeedbackError('unsupported_event')
        mail = event.get('mail')
        if not isinstance(mail, dict) or not isinstance(mail.get('messageId'), str) or not mail['messageId']:
            raise FeedbackError('missing_mail_id')
        if kind != 'Send' and not isinstance(event.get(kind.lower()), dict):
            raise FeedbackError('missing_event_detail')
        # SNS MessageId changes on republishing. Hash only the SES event so an
        # identical delivery cannot increment the audit projection twice.
        canonical = json.dumps(event, sort_keys=True, separators=(',', ':'), ensure_ascii=True)
        return hashlib.sha256(canonical.encode()).hexdigest(), kind
    except (ValueError, KeyError, TypeError) as error:
        raise FeedbackError('malformed_json') from error


class Ledger:
    """Persist only a fingerprint, event category and first-seen timestamp."""

    def __init__(self, path):
        path = Path(path)
        if not path.parent.is_dir() or path.parent.is_symlink():
            raise FeedbackError('ledger_parent_missing_or_symlink')
        if path.is_symlink() or (path.exists() and path.stat().st_mode & 0o077):
            raise FeedbackError('ledger_not_private')
        if path.parent.stat().st_mode & 0o077:
            raise FeedbackError('ledger_parent_not_private')
        self.connection = sqlite3.connect(path, timeout=10)
        os.chmod(path, 0o600)
        with self.connection:
            self.connection.execute('CREATE TABLE IF NOT EXISTS events (fingerprint TEXT PRIMARY KEY, kind TEXT NOT NULL, first_seen TEXT NOT NULL)')

    def record(self, fingerprint, kind):
        # A successful commit precedes acknowledgment. A crash after commit and
        # before DeleteMessage therefore retries as a duplicate, not a new event.
        with self.connection:
            result = self.connection.execute(
                'INSERT OR IGNORE INTO events VALUES (?, ?, ?)',
                (fingerprint, kind, datetime.datetime.now(datetime.timezone.utc).isoformat()),
            )
        return result.rowcount == 1

    def close(self):
        self.connection.close()


class AwsQueue:
    def __init__(self, config):
        self.config = validate_config(config)

    def call(self, operation, extra):
        result = subprocess.run(
            ['aws', '--region', self.config['region'], '--output', 'json',
             '--cli-read-timeout', '35', 'sqs', operation,
             '--queue-url', self.config['queue_url'], *extra],
            capture_output=True, text=True, timeout=45,
            env=dict(os.environ, AWS_PAGER='', PYTHONWARNINGS='ignore'),
        )
        if result.returncode:
            raise FeedbackError('sqs_api_failed')
        try:
            return json.loads(result.stdout) if result.stdout.strip() else {}
        except ValueError as error:
            raise FeedbackError('invalid_api_response') from error

    def inspect(self):
        result = self.call('get-queue-attributes', ['--attribute-names', 'QueueArn', 'ApproximateNumberOfMessages'])
        attrs = result['Attributes']
        if attrs['QueueArn'] != self.config['queue_arn']:
            raise FeedbackError('queue_identity_mismatch')
        return {'mode': 'inspect', 'visible_approximate': int(attrs['ApproximateNumberOfMessages'])}

    def receive(self):
        return self.call('receive-message', ['--max-number-of-messages', '10', '--wait-time-seconds', '20']).get('Messages', [])

    def acknowledge(self, receipt):
        self.call('delete-message', ['--receipt-handle', receipt])


def poll(queue, ledger, topic):
    """Process one bounded batch; failed parsing/persistence never acknowledges."""
    result = {'received': 0, 'new': 0, 'duplicates': 0, 'acknowledged': 0, 'failed': 0}
    for message in queue.receive():
        result['received'] += 1
        try:
            fingerprint, kind = decode(message['Body'], topic)
            is_new = ledger.record(fingerprint, kind)
            result['new' if is_new else 'duplicates'] += 1
            queue.acknowledge(message['ReceiptHandle'])
            result['acknowledged'] += 1
        except (FeedbackError, sqlite3.Error, KeyError):
            result['failed'] += 1
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--config', required=True)
    parser.add_argument('--apply', action='store_true', help='Receive and acknowledge real queue messages after committing the audit ledger')
    args = parser.parse_args()
    os.umask(0o077)
    config_path = Path(args.config)
    if config_path.is_symlink() or config_path.stat().st_mode & 0o077:
        raise FeedbackError('config_not_private')
    config = validate_config(json.loads(config_path.read_text()))
    queue = AwsQueue(config)
    print(json.dumps(queue.inspect()), flush=True)
    if args.apply:
        ledger = Ledger(config['ledger'])
        try:
            print(json.dumps(poll(queue, ledger, config['topic_arn'])), flush=True)
        finally:
            ledger.close()


if __name__ == '__main__':
    try:
        main()
    except (FeedbackError, OSError, sqlite3.Error, subprocess.TimeoutExpired, ValueError):
        print(json.dumps({'error': 'feedback_worker_failed'}), flush=True)
        raise SystemExit(1)
