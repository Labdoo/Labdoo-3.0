"""Build narrowly scoped DLM protection and CloudWatch alarm specifications."""
import re


def lifecycle_policy(role_arn, utc_time, target_region='eu-west-1'):
    if not re.fullmatch(r'arn:aws:iam::\d{12}:role/[A-Za-z0-9_+=,.@/-]+', role_arn):
        raise ValueError('Expected an IAM service role ARN')
    if not re.fullmatch(r'(?:[01]\d|2[0-3]):[0-5]\d', utc_time):
        raise ValueError('Expected an explicit UTC daily time')
    if target_region != 'eu-west-1':
        raise ValueError('This recovery plan requires the reviewed EU destination')
    return {
        'ExecutionRoleArn': role_arn,
        'Description': 'Labdoo v3 daily coordinated recovery with encrypted EU copy',
        'State': 'ENABLED',
        'Tags': {'Purpose': 'v3-volume-recovery', 'Task': '6j3vfF9VjvCgJ483'},
        'PolicyDetails': {
            'PolicyType': 'EBS_SNAPSHOT_MANAGEMENT',
            'ResourceTypes': ['INSTANCE'],
            'TargetTags': [{'Key': 'LabdooRecoveryPolicy', 'Value': 'v3-daily'}],
            'Parameters': {'ExcludeBootVolume': False},
            'Schedules': [{
                'Name': 'V3DailyRootAndData', 'CopyTags': True,
                'CreateRule': {'Interval': 24, 'IntervalUnit': 'HOURS', 'Times': [utc_time]},
                'RetainRule': {'Count': 7},
                'TagsToAdd': [{'Key': 'RecoverySet', 'Value': 'v3-root-and-data'}],
                'CrossRegionCopyRules': [{
                    'TargetRegion': target_region, 'Encrypted': True, 'CopyTags': True,
                    'RetainRule': {'Interval': 14, 'IntervalUnit': 'DAYS'},
                }],
            }],
        },
    }


def alarm_specs(policy_id, topic_arn, enabled=False):
    if not re.fullmatch(r'policy-[0-9a-f]{17}', policy_id):
        raise ValueError('Expected one specific DLM policy ID')
    if not re.fullmatch(r'arn:aws:sns:eu-central-1:\d{12}:[A-Za-z0-9_-]+', topic_arn):
        raise ValueError('Expected the reviewed regional SNS destination')
    alarms = []
    failures = {
        'CreateFailed': 'SnapshotsCreateFailed',
        'CopyFailed': 'SnapshotsCopiedRegionFailed',
        'DeleteFailed': 'SnapshotsDeleteFailed',
        'CopyDeleteFailed': 'SnapshotsCopiedRegionDeleteFailed',
    }
    for suffix, metric in failures.items():
        alarms.append({
            'AlarmName': 'LabdooV3Backup' + suffix,
            'AlarmDescription': 'Failed DLM backup action for the scoped v3 recovery policy',
            'MetricName': metric, 'Period': 3600, 'EvaluationPeriods': 1,
            'DatapointsToAlarm': 1, 'Threshold': 0,
            'ComparisonOperator': 'GreaterThanThreshold', 'TreatMissingData': 'notBreaching',
        })
    for suffix, metric in [('NoLocalActivity', 'SnapshotsCreateCompleted'),
                           ('NoRegionalCopyActivity', 'SnapshotsCopiedRegionCompleted')]:
        alarms.append({
            'AlarmName': 'LabdooV3Backup' + suffix,
            'AlarmDescription': 'No successful activity in six consecutive four-hour buckets; not a per-volume RPO guarantee',
            'MetricName': metric, 'Period': 14400, 'EvaluationPeriods': 6,
            'DatapointsToAlarm': 6, 'Threshold': 1,
            'ComparisonOperator': 'LessThanThreshold', 'TreatMissingData': 'breaching',
        })
    for alarm in alarms:
        alarm.update({
            'ActionsEnabled': enabled, 'AlarmActions': [topic_arn],
            # DLM publishes these count-like metrics with provider unit None.
            # Omitting Unit avoids filtering out the actual native series.
            'Namespace': 'AWS/EBS', 'Statistic': 'Sum',
            'Dimensions': [{'Name': 'DLMPolicyId', 'Value': policy_id}],
        })
    return alarms
