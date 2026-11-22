"""Build reviewed observability policies without calling AWS or reading secrets."""

import copy
import re


def metric_alarm(name, namespace, metric, dimensions, threshold, *,
                 comparison='GreaterThanThreshold', statistic='Average',
                 periods=5, datapoints=3, period=300, missing='missing'):
    """Keep capacity alarms separate from no-data alarms and recovery actions."""
    if not re.fullmatch(r'LabdooObs[A-Za-z0-9-]+', name):
        raise ValueError('Expected a task-owned alarm name')
    if namespace not in {'AWS/EC2', 'AWS/RDS', 'AWS/ElastiCache', 'CWAgent',
                         'Labdoo/Health', 'Labdoo/ObservabilityTest'}:
        raise ValueError('Unreviewed metric namespace')
    if not 1 <= datapoints <= periods or period < 60 or period % 60:
        raise ValueError('Invalid sustained alarm window')
    if missing not in {'missing', 'breaching', 'notBreaching'}:
        raise ValueError('Unreviewed missing-data policy')
    return {
        'AlarmName': name, 'Namespace': namespace, 'MetricName': metric,
        'Dimensions': [{'Name': k, 'Value': v} for k, v in sorted(dimensions.items())],
        'ComparisonOperator': comparison, 'Threshold': threshold,
        'Statistic': statistic, 'Period': period, 'EvaluationPeriods': periods,
        'DatapointsToAlarm': datapoints, 'TreatMissingData': missing,
        'ActionsEnabled': False,
    }


def missing_alarm(name, namespace, metric, dimensions):
    """Observed nonnegative metrics cannot breach -1; missing periods can."""
    result = metric_alarm(name, namespace, metric, dimensions, -1,
                          comparison='LessThanThreshold', periods=3,
                          datapoints=3, missing='breaching')
    result['AlarmDescription'] = (
        'Telemetry absent for three five-minute periods; metric values are nonnegative. '
        'CloudWatch may reuse older real datapoints during evaluation.'
    )
    return result


def cloudwatch_publish_statement(account, region):
    if not re.fullmatch(r'\d{12}', account) or region != 'eu-central-1':
        raise ValueError('Expected the reviewed account and alarm region')
    return {
        'Sid': 'LabdooCloudWatchAlarmPublish', 'Effect': 'Allow',
        'Principal': {'Service': 'cloudwatch.amazonaws.com'},
        'Action': 'SNS:Publish',
        'Condition': {
            'StringEquals': {'aws:SourceAccount': account},
            'ArnLike': {'aws:SourceArn': f'arn:aws:cloudwatch:{region}:{account}:alarm:*'},
        },
    }


def extend_topic_policy(policy, topic, account, region='eu-central-1'):
    if topic.split(':')[:5] != ['arn', 'aws', 'sns', region, account]:
        raise ValueError('Topic must belong to the reviewed account and region')
    result = copy.deepcopy(policy)
    statements = result.setdefault('Statement', [])
    statement = cloudwatch_publish_statement(account, region)
    statement['Resource'] = topic
    existing = [s for s in statements if s.get('Sid') == statement['Sid']]
    if existing and existing != [statement]:
        raise ValueError('Refusing to overwrite an unexpected existing statement')
    if not existing:
        statements.append(statement)
    return result


def trail_bucket_policy(bucket, account, trail_arn):
    if not re.fullmatch(r'[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]', bucket):
        raise ValueError('Invalid private trail bucket')
    if not re.fullmatch(r'\d{12}', account) or not trail_arn.startswith(
            f'arn:aws:cloudtrail:eu-central-1:{account}:trail/'):
        raise ValueError('Trail must belong to the reviewed account and region')
    resource = 'arn:aws:s3:::' + bucket
    return {'Version': '2012-10-17', 'Statement': [
        {'Sid': 'DenyInsecureTransport', 'Effect': 'Deny', 'Principal': '*',
         'Action': 's3:*', 'Resource': [resource, resource + '/*'],
         'Condition': {'Bool': {'aws:SecureTransport': 'false'}}},
        {'Sid': 'CloudTrailAcl', 'Effect': 'Allow',
         'Principal': {'Service': 'cloudtrail.amazonaws.com'},
         'Action': 's3:GetBucketAcl', 'Resource': resource,
         'Condition': {'StringEquals': {'aws:SourceArn': trail_arn}}},
        {'Sid': 'CloudTrailWrite', 'Effect': 'Allow',
         'Principal': {'Service': 'cloudtrail.amazonaws.com'},
         'Action': 's3:PutObject', 'Resource': resource + '/AWSLogs/' + account + '/*',
         'Condition': {'StringEquals': {
             'aws:SourceArn': trail_arn, 's3:x-amz-acl': 'bucket-owner-full-control'}}},
    ]}


def blocked_waf_logging(acl_arn, log_arn):
    if ':wafv2:us-east-1:' not in acl_arn or ':global/webacl/' not in acl_arn:
        raise ValueError('Expected the reviewed global ACL')
    if ':logs:us-east-1:' not in log_arn or ':log-group:aws-waf-logs-' not in log_arn:
        raise ValueError('Expected a regional WAF log destination')
    if acl_arn.split(':')[4] != log_arn.split(':')[4]:
        raise ValueError('Destination account differs')
    return {
        'ResourceArn': acl_arn, 'LogDestinationConfigs': [log_arn],
        'RedactedFields': [
            {'SingleHeader': {'Name': 'authorization'}},
            {'SingleHeader': {'Name': 'cookie'}},
            {'SingleHeader': {'Name': 'x-origin-verify'}},
            {'QueryString': {}},
        ],
        'LoggingFilter': {'DefaultBehavior': 'DROP', 'Filters': [{
            'Behavior': 'KEEP', 'Requirement': 'MEETS_ANY',
            'Conditions': [{'ActionCondition': {'Action': 'BLOCK'}}],
        }]},
    }
