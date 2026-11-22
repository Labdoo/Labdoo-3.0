"""Build scoped SES feedback queue protections and sparse-traffic alarms."""

import copy
import re


def validate_arns(queue, topic, account):
    if not re.fullmatch(r'\d{12}', account):
        raise ValueError('Invalid account')
    for value, service in [(queue, 'sqs'), (topic, 'sns')]:
        if not re.fullmatch(r'arn:aws:' + service + ':eu-central-1:' + account + r':[A-Za-z0-9_-]+', value):
            raise ValueError('Resource outside reviewed account/region')


def deny_insecure(queue):
    return {'Sid': 'LabdooFeedbackDenyInsecureTransport', 'Effect': 'Deny',
            'Principal': '*', 'Action': 'sqs:*', 'Resource': queue,
            'Condition': {'Bool': {'aws:SecureTransport': 'false', 'aws:PrincipalIsAWSService': 'false'}}}


def sns_queue_policy(queue, topic, account):
    validate_arns(queue, topic, account)
    return {'Version': '2012-10-17', 'Statement': [
        {'Sid': 'AllowSNSToSendMessage', 'Effect': 'Allow',
         'Principal': {'Service': 'sns.amazonaws.com'}, 'Action': 'SQS:SendMessage',
         'Resource': queue, 'Condition': {
             'StringEquals': {'aws:SourceAccount': account},
             'ArnEquals': {'aws:SourceArn': topic}}}, deny_insecure(queue)]}


def protect_existing_queue(policy, queue, topic, account):
    """Refuse unreviewed grants rather than removing unrelated permissions."""
    desired = sns_queue_policy(queue, topic, account)
    old = copy.deepcopy(desired['Statement'][0])
    old['Principal'] = {'AWS': '*'}
    statements = policy.get('Statement', [])
    if statements not in [[old], desired['Statement']]:
        raise ValueError('Unexpected queue policy requires review')
    result = copy.deepcopy(policy)
    result['Statement'] = desired['Statement']
    return result


def dead_letter_attributes(queue, topic, source_queue, account, *, delivery):
    validate_arns(queue, topic, account)
    validate_arns(source_queue, topic, account)
    policy = sns_queue_policy(queue, topic, account) if delivery else {
        'Version': '2012-10-17', 'Statement': [deny_insecure(queue)]}
    import json
    attributes = {'SqsManagedSseEnabled': 'true', 'MessageRetentionPeriod': '1209600',
                  'Policy': json.dumps(policy)}
    if not delivery:
        attributes['RedriveAllowPolicy'] = json.dumps({'redrivePermission': 'byQueue', 'sourceQueueArns': [source_queue]})
    return attributes


def alarm(name, namespace, metric, dimensions, threshold, *, statistic='Maximum',
          period=300, periods=1):
    if not re.fullmatch(r'LabdooFeedback[A-Za-z0-9-]+', name):
        raise ValueError('Unowned alarm name')
    if namespace not in {'AWS/SQS', 'AWS/SNS', 'AWS/SES'}:
        raise ValueError('Unreviewed namespace')
    if not 1 <= periods <= 5 or period not in {60, 300}:
        raise ValueError('Invalid alarm window')
    return {'AlarmName': name, 'Namespace': namespace, 'MetricName': metric,
            'Dimensions': [{'Name': k, 'Value': v} for k, v in sorted(dimensions.items())],
            'Statistic': statistic, 'ComparisonOperator': 'GreaterThanOrEqualToThreshold',
            'Threshold': threshold, 'Period': period, 'EvaluationPeriods': periods,
            'DatapointsToAlarm': periods, 'TreatMissingData': 'notBreaching',
            'ActionsEnabled': False}


def consumer_policy(queue):
    """Additional permissions for a future approved audit poller, not installed."""
    if not re.fullmatch(r'arn:aws:sqs:eu-central-1:\d{12}:[A-Za-z0-9_-]+', queue):
        raise ValueError('Unexpected consumer queue')
    return {'Version': '2012-10-17', 'Statement': [
        {'Sid': 'FeedbackQueueConsumer', 'Effect': 'Allow',
         'Action': ['sqs:GetQueueAttributes', 'sqs:ReceiveMessage', 'sqs:DeleteMessage'],
         'Resource': queue},
        {'Sid': 'FeedbackPollHeartbeat', 'Effect': 'Allow',
         'Action': 'cloudwatch:PutMetricData', 'Resource': '*',
         'Condition': {'StringEquals': {'cloudwatch:namespace': 'Labdoo/SESFeedback'}}}]}
