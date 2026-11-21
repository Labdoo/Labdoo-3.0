"""Check protection scope, retention and failure/activity alarm semantics."""
import importlib.util
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('policy', ROOT / 'scripts/v3_backup_policy.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)
ROLE = 'arn:aws:iam::111111111111:role/service-role/RecoveryTest'
TOPIC = 'arn:aws:sns:eu-central-1:111111111111:isolated-test-topic'
POLICY = 'policy-11111111111111111'


class RecoveryPolicyTest(unittest.TestCase):
    def test_only_the_explicit_v3_instance_tag_is_targeted(self):
        details = policy.lifecycle_policy(ROLE, '00:42')['PolicyDetails']
        self.assertEqual(details['ResourceTypes'], ['INSTANCE'])
        self.assertEqual(details['TargetTags'], [{'Key': 'LabdooRecoveryPolicy', 'Value': 'v3-daily'}])
        self.assertFalse(details['Parameters']['ExcludeBootVolume'])

    def test_daily_pair_has_local_and_encrypted_eu_retention(self):
        schedule = policy.lifecycle_policy(ROLE, '00:42')['PolicyDetails']['Schedules'][0]
        self.assertEqual(schedule['CreateRule'], {'Interval': 24, 'IntervalUnit': 'HOURS', 'Times': ['00:42']})
        self.assertEqual(schedule['RetainRule'], {'Count': 7})
        self.assertTrue(schedule['CopyTags'])
        copy = schedule['CrossRegionCopyRules'][0]
        self.assertEqual(copy['TargetRegion'], 'eu-west-1')
        self.assertTrue(copy['Encrypted'])
        self.assertEqual(copy['RetainRule'], {'Interval': 14, 'IntervalUnit': 'DAYS'})

    def test_invalid_roles_times_and_destinations_fail_closed(self):
        for role, time, region in [('*', '00:42', 'eu-west-1'), (ROLE, '25:00', 'eu-west-1'),
                                   (ROLE, '00:42', 'eu-central-1')]:
            with self.subTest(role=role, time=time, region=region), self.assertRaises(ValueError):
                policy.lifecycle_policy(role, time, region)

    def test_failure_counters_do_not_alarm_for_missing_zero_events(self):
        failures = policy.alarm_specs(POLICY, TOPIC)[:4]
        self.assertEqual(len(failures), 4)
        for alarm in failures:
            self.assertEqual(alarm['ComparisonOperator'], 'GreaterThanThreshold')
            self.assertEqual(alarm['Threshold'], 0)
            self.assertEqual(alarm['TreatMissingData'], 'notBreaching')

    def test_absence_of_success_is_breaching_across_all_six_buckets(self):
        for alarm in policy.alarm_specs(POLICY, TOPIC)[4:]:
            self.assertEqual(alarm['ComparisonOperator'], 'LessThanThreshold')
            self.assertEqual(alarm['Threshold'], 1)
            self.assertEqual(alarm['TreatMissingData'], 'breaching')
            self.assertEqual((alarm['Period'], alarm['EvaluationPeriods'], alarm['DatapointsToAlarm']), (14400, 6, 6))

    def test_actions_are_disabled_until_observed_state_is_verified(self):
        alarms = policy.alarm_specs(POLICY, TOPIC)
        self.assertEqual(len({a['AlarmName'] for a in alarms}), 6)
        for alarm in alarms:
            self.assertFalse(alarm['ActionsEnabled'])
            self.assertEqual(alarm['AlarmActions'], [TOPIC])
            self.assertEqual(alarm['Dimensions'], [{'Name': 'DLMPolicyId', 'Value': POLICY}])
        self.assertTrue(all(a['ActionsEnabled'] for a in policy.alarm_specs(POLICY, TOPIC, True)))

    def test_wildcard_policy_or_external_topic_is_rejected(self):
        for identifier, topic in [('*', TOPIC), (POLICY, 'arn:aws:sns:us-east-1:111111111111:test')]:
            with self.subTest(identifier=identifier, topic=topic), self.assertRaises(ValueError):
                policy.alarm_specs(identifier, topic)

    def test_native_metric_unit_is_not_guessed(self):
        for alarm in policy.alarm_specs(POLICY, TOPIC):
            self.assertNotIn('Unit', alarm)


if __name__ == '__main__':
    unittest.main(verbosity=2)
