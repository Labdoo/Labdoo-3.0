"""Exercise the monitoring policy's notification and confidentiality boundaries."""

import importlib.util
from pathlib import Path
import unittest

SPEC = importlib.util.spec_from_file_location(
    'observability', Path(__file__).resolve().parents[2] / 'scripts/aws_observability_policy.py')
policy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(policy)
ACCOUNT = '123456789012'
TOPIC = f'arn:aws:sns:eu-central-1:{ACCOUNT}:test'
TRAIL = f'arn:aws:cloudtrail:eu-central-1:{ACCOUNT}:trail/test'
ACL = f'arn:aws:wafv2:us-east-1:{ACCOUNT}:global/webacl/test/example'
LOG = f'arn:aws:logs:us-east-1:{ACCOUNT}:log-group:aws-waf-logs-test'


class ObservabilityPolicyTest(unittest.TestCase):

    def test_new_alarms_cannot_notify_before_validation(self):
        alarm = policy.metric_alarm('LabdooObsTest', 'AWS/RDS', 'FreeableMemory',
                                    {'DBInstanceIdentifier': 'test'}, 256 * 1024 ** 2)
        self.assertFalse(alarm['ActionsEnabled'])
        self.assertNotIn('AlarmActions', alarm)
        self.assertNotIn('Unit', alarm)

    def test_no_data_is_distinct_from_capacity(self):
        alarm = policy.missing_alarm('LabdooObsNoData', 'CWAgent', 'disk_used_percent',
                                     {'path': '/mnt/data', 'InstanceId': 'test'})
        self.assertEqual(alarm['TreatMissingData'], 'breaching')
        self.assertEqual(alarm['Threshold'], -1)
        self.assertEqual(alarm['ComparisonOperator'], 'LessThanThreshold')
        self.assertEqual(alarm['DatapointsToAlarm'], alarm['EvaluationPeriods'])

    def test_reject_unowned_alarm_or_namespace(self):
        for name, namespace in [('ExistingAlarm', 'AWS/RDS'), ('LabdooObsTest', 'unreviewed')]:
            with self.assertRaises(ValueError):
                policy.metric_alarm(name, namespace, 'Test', {}, 1)

    def test_reject_invalid_evaluation_windows(self):
        for options in [{'datapoints': 6}, {'period': 10}, {'missing': 'ignore'}]:
            with self.assertRaises(ValueError):
                policy.metric_alarm('LabdooObsTest', 'AWS/RDS', 'Test', {}, 1, **options)

    def test_existing_topic_grants_preserved_and_idempotent(self):
        original = {'Version': '2012-10-17', 'Statement': [{'Sid': 'Existing', 'Effect': 'Allow'}]}
        result = policy.extend_topic_policy(original, TOPIC, ACCOUNT)
        self.assertEqual(original['Statement'], [{'Sid': 'Existing', 'Effect': 'Allow'}])
        self.assertEqual(result['Statement'][0], original['Statement'][0])
        self.assertEqual(policy.extend_topic_policy(result, TOPIC, ACCOUNT), result)
        statement = result['Statement'][-1]
        self.assertEqual(statement['Principal'], {'Service': 'cloudwatch.amazonaws.com'})
        self.assertEqual(statement['Condition']['StringEquals']['aws:SourceAccount'], ACCOUNT)
        self.assertEqual(statement['Resource'], TOPIC)

    def test_refuse_overwriting_unexpected_topic_statement(self):
        with self.assertRaises(ValueError):
            policy.extend_topic_policy({'Statement': [{'Sid': 'LabdooCloudWatchAlarmPublish'}]},
                                       TOPIC, ACCOUNT)

    def test_refuse_cross_account_or_region_topic(self):
        for topic in [TOPIC.replace(ACCOUNT, '999999999999'), TOPIC.replace('eu-central-1', 'us-east-1')]:
            with self.assertRaises(ValueError):
                policy.extend_topic_policy({}, topic, ACCOUNT)

    def test_trail_delivery_scope_and_transport(self):
        result = policy.trail_bucket_policy('example-private-test', ACCOUNT, TRAIL)
        statements = result['Statement']
        self.assertEqual(statements[0]['Effect'], 'Deny')
        self.assertEqual(statements[0]['Condition'], {'Bool': {'aws:SecureTransport': 'false'}})
        for statement in statements[1:]:
            self.assertEqual(statement['Principal'], {'Service': 'cloudtrail.amazonaws.com'})
            self.assertEqual(statement['Condition']['StringEquals']['aws:SourceArn'], TRAIL)
        self.assertTrue(statements[2]['Resource'].endswith('/AWSLogs/' + ACCOUNT + '/*'))

    def test_trail_refuses_other_account(self):
        with self.assertRaises(ValueError):
            policy.trail_bucket_policy('example-private-test', ACCOUNT, TRAIL.replace(ACCOUNT, '999999999999'))

    def test_waf_only_blocks_and_redacts_credentials(self):
        config = policy.blocked_waf_logging(ACL, LOG)
        self.assertEqual(config['LoggingFilter']['DefaultBehavior'], 'DROP')
        self.assertEqual(config['LoggingFilter']['Filters'][0]['Conditions'],
                         [{'ActionCondition': {'Action': 'BLOCK'}}])
        for name in ['authorization', 'cookie', 'x-origin-verify']:
            self.assertIn({'SingleHeader': {'Name': name}}, config['RedactedFields'])
        self.assertIn({'QueryString': {}}, config['RedactedFields'])

    def test_waf_refuses_wrong_region_account_or_destination(self):
        for acl, log in [(ACL.replace('us-east-1', 'eu-central-1'), LOG),
                         (ACL, LOG.replace(ACCOUNT, '999999999999')),
                         (ACL, LOG.replace('aws-waf-logs-', 'general-logs-'))]:
            with self.assertRaises(ValueError):
                policy.blocked_waf_logging(acl, log)


if __name__ == '__main__':
    unittest.main()
