"""Exercise acknowledgment ordering, safe defaults and notification protections."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]

def load(name):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts' / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

worker = load('ses_feedback_worker')
policy = load('ses_feedback_policy')
ACCOUNT = '123456789012'
TOPIC = f'arn:aws:sns:eu-central-1:{ACCOUNT}:synthetic-topic'
QUEUE = f'arn:aws:sqs:eu-central-1:{ACCOUNT}:synthetic-queue'
EVENT = {'eventType': 'Bounce', 'mail': {'messageId': 'synthetic-1', 'destination': ['recipient@example.invalid']},
         'bounce': {'feedbackId': 'synthetic-feedback', 'bounceType': 'Permanent'}}

def envelope(event=EVENT, message_id='sns-first'):
    return json.dumps({'Type': 'Notification', 'TopicArn': TOPIC, 'MessageId': message_id, 'Message': json.dumps(event)})

class Queue:
    def __init__(self, messages, fail_delete=False):
        self.messages = [{'Body': x, 'ReceiptHandle': str(i)} for i, x in enumerate(messages)]
        self.deleted = []
        self.fail_delete = fail_delete
    def receive(self):
        return self.messages
    def acknowledge(self, receipt):
        if self.fail_delete:
            raise worker.FeedbackError('simulated_delete_failure')
        self.deleted.append(receipt)

class FeedbackTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name) / 'events.sqlite'
        self.ledger = worker.Ledger(self.path)
    def tearDown(self):
        self.ledger.close()
        self.tmp.cleanup()
    def count(self):
        return self.ledger.connection.execute('SELECT COUNT(*) FROM events').fetchone()[0]
    def test_republished_sns_envelope_deduplicates_ses_event(self):
        q = Queue([envelope(), envelope(message_id='sns-republished')])
        result = worker.poll(q, self.ledger, TOPIC)
        self.assertEqual((result['new'], result['duplicates'], result['acknowledged']), (1, 1, 2))
        self.assertEqual(self.count(), 1)
    def test_delete_failure_then_replay_does_not_double_count(self):
        first = worker.poll(Queue([envelope()], True), self.ledger, TOPIC)
        self.assertEqual((first['new'], first['failed'], first['acknowledged']), (1, 1, 0))
        second = worker.poll(Queue([envelope()]), self.ledger, TOPIC)
        self.assertEqual((second['new'], second['duplicates'], second['acknowledged']), (0, 1, 1))
    def test_persistence_failure_does_not_acknowledge(self):
        q = Queue([envelope()])
        with patch.object(self.ledger, 'record', side_effect=sqlite3.OperationalError('simulated')):
            result = worker.poll(q, self.ledger, TOPIC)
        self.assertEqual(result['failed'], 1)
        self.assertEqual(q.deleted, [])
    def test_malformed_unknown_and_wrong_topic_never_acknowledge(self):
        other = json.loads(envelope()); other['TopicArn'] += '-other'
        cases = ['{', json.dumps(EVENT), envelope({'eventType': 'Open'}), json.dumps(other), envelope({'eventType':'Bounce','mail':{'messageId':'x'}})]
        q = Queue(cases); result = worker.poll(q, self.ledger, TOPIC)
        self.assertEqual(result['failed'], len(cases)); self.assertEqual(q.deleted, []); self.assertEqual(self.count(), 0)
    def test_pii_not_persisted(self):
        worker.poll(Queue([envelope()]), self.ledger, TOPIC)
        raw = self.path.read_bytes()
        for sensitive in [b'recipient@example.invalid', b'synthetic-1', b'synthetic-feedback', b'destination']:
            self.assertNotIn(sensitive, raw)
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)
    def test_all_configured_event_categories(self):
        for kind in ['Send','Delivery','Bounce','Complaint','Reject']:
            event={'eventType':kind,'mail':{'messageId':'synthetic-'+kind}}
            if kind!='Send':event[kind.lower()]={}
            self.assertEqual(worker.decode(envelope(event), TOPIC)[1], kind)
    def test_legacy_notification_type(self):
        e=copy.deepcopy(EVENT);e['notificationType']=e.pop('eventType')
        self.assertEqual(worker.decode(envelope(e), TOPIC)[1], 'Bounce')
    def test_non_object_config_refused(self):
        for value in [None, [], 'invalid']:
            with self.subTest(value=value), self.assertRaises(worker.FeedbackError):worker.validate_config(value)
    def test_public_ledger_refused(self):
        self.path.chmod(0o644)
        with self.assertRaises(worker.FeedbackError):worker.Ledger(self.path)
        self.path.chmod(0o600)
    def test_config_is_bound_to_account_region_and_queue(self):
        c={'region':'eu-central-1','account':ACCOUNT,'queue_url':f'https://sqs.eu-central-1.amazonaws.com/{ACCOUNT}/synthetic-queue','queue_arn':QUEUE,'topic_arn':TOPIC,'ledger':str(self.path)}
        self.assertEqual(worker.validate_config(c), c)
        for field,value in [('queue_url','http://example.invalid/q'),('queue_arn',QUEUE+'-wrong'),('topic_arn',TOPIC.replace('eu-central-1','us-east-1')),('ledger','relative.sqlite')]:
            with self.subTest(field=field), self.assertRaises(worker.FeedbackError):worker.validate_config({**c,field:value})
    def test_cli_default_inspects_without_receive_or_ledger(self):
        config={'region':'eu-central-1','account':ACCOUNT,'queue_url':f'https://sqs.eu-central-1.amazonaws.com/{ACCOUNT}/synthetic-queue','queue_arn':QUEUE,'topic_arn':TOPIC,'ledger':str(self.path)}
        path=Path(self.tmp.name)/'config.json';path.write_text(json.dumps(config));path.chmod(0o600)
        with patch('sys.argv',['worker','--config',str(path)]), patch.object(worker.AwsQueue,'inspect',return_value={'mode':'inspect'}), patch.object(worker.AwsQueue,'receive') as receive, patch.object(worker,'Ledger') as ledger:
            worker.main();receive.assert_not_called();ledger.assert_not_called()
    def test_queue_grant_scoped_to_sns_topic_account_and_tls(self):
        p=policy.sns_queue_policy(QUEUE,TOPIC,ACCOUNT)
        self.assertEqual(p['Statement'][0]['Principal'],{'Service':'sns.amazonaws.com'})
        self.assertEqual(p['Statement'][0]['Condition']['ArnEquals']['aws:SourceArn'],TOPIC)
        self.assertEqual(p['Statement'][1]['Condition']['Bool']['aws:PrincipalIsAWSService'],'false')
    def test_unexpected_policy_preserved_by_refusal(self):
        p=policy.sns_queue_policy(QUEUE,TOPIC,ACCOUNT);original=copy.deepcopy(p)
        self.assertEqual(policy.protect_existing_queue(p,QUEUE,TOPIC,ACCOUNT),p)
        p['Statement'].append({'Effect':'Allow','Action':'sqs:*','Principal':'*'})
        with self.assertRaises(ValueError):policy.protect_existing_queue(p,QUEUE,TOPIC,ACCOUNT)
        self.assertEqual(original,policy.sns_queue_policy(QUEUE,TOPIC,ACCOUNT))
    def test_dead_letter_queues_have_separate_trust_and_retention(self):
        target=QUEUE+'-dead'
        delivery=policy.dead_letter_attributes(target,TOPIC,QUEUE,ACCOUNT,delivery=True)
        processing=policy.dead_letter_attributes(target,TOPIC,QUEUE,ACCOUNT,delivery=False)
        self.assertEqual(delivery['MessageRetentionPeriod'],'1209600')
        self.assertEqual(processing['SqsManagedSseEnabled'],'true')
        self.assertEqual(json.loads(processing['RedriveAllowPolicy'])['sourceQueueArns'],[QUEUE])
        self.assertNotIn('RedriveAllowPolicy',delivery)
    def test_idle_sparse_metrics_do_not_page_for_missing_data(self):
        a=policy.alarm('LabdooFeedbackAge','AWS/SQS','ApproximateAgeOfOldestMessage',{'QueueName':'synthetic'},900,period=60,periods=3)
        self.assertEqual(a['TreatMissingData'],'notBreaching');self.assertFalse(a['ActionsEnabled'])
    def test_future_consumer_has_no_mail_send_or_queue_purge_permission(self):
        p=policy.consumer_policy(QUEUE)
        self.assertEqual(p['Statement'][0]['Resource'],QUEUE)
        self.assertEqual(set(p['Statement'][0]['Action']),{'sqs:GetQueueAttributes','sqs:ReceiveMessage','sqs:DeleteMessage'})
        self.assertEqual(p['Statement'][1]['Condition']['StringEquals']['cloudwatch:namespace'],'Labdoo/SESFeedback')
    def test_receive_lease_defaults_longer_than_service_timeout(self):
        c={'region':'eu-central-1','account':ACCOUNT,'queue_url':f'https://sqs.eu-central-1.amazonaws.com/{ACCOUNT}/synthetic-queue','queue_arn':QUEUE,'topic_arn':TOPIC,'ledger':str(self.path)}
        q=worker.AwsQueue(c)
        with patch.object(q,'call',return_value={}) as call:
            self.assertEqual(q.receive(),[])
            self.assertEqual(call.call_args.args[1][-2:],['--visibility-timeout','300'])
        for value in [0,43201,True,'300']:
            with self.subTest(value=value),self.assertRaises(worker.FeedbackError):worker.validate_config({**c,'visibility_timeout':value})
    def test_failed_apply_batch_exits_unsuccessfully(self):
        config={'region':'eu-central-1','account':ACCOUNT,'queue_url':f'https://sqs.eu-central-1.amazonaws.com/{ACCOUNT}/synthetic-queue','queue_arn':QUEUE,'topic_arn':TOPIC,'ledger':str(self.path)}
        path=Path(self.tmp.name)/'apply.json';path.write_text(json.dumps(config));path.chmod(0o600)
        with patch('sys.argv',['worker','--config',str(path),'--apply']),patch.object(worker.AwsQueue,'inspect',return_value={'mode':'inspect'}),patch.object(worker,'poll',return_value={'failed':1}),self.assertRaises(worker.FeedbackError):worker.main()
    def test_cross_account_policies_refused(self):
        with self.assertRaises(ValueError):policy.sns_queue_policy(QUEUE,TOPIC, '999999999999')

if __name__=='__main__':unittest.main()
