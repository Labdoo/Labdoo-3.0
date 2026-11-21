"""Validate emitted exception boundaries without simulating AWS managed detection."""
from copy import deepcopy
import importlib.util
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('waf', ROOT / 'scripts/v3_waf_exceptions.py')
waf = importlib.util.module_from_spec(spec)
spec.loader.exec_module(waf)


def source_acl():
    rules = [{'Name': name, 'Priority': i, 'Action': {'Allow': {}},
              'Statement': {'RegexMatchStatement': {'RegexString': 'legacy'}}}
             for i, name in enumerate(sorted(waf.REMOVED_ALLOWS))]
    for name, priority, group in [
        ('AWSCommonRules', 5, 'AWSManagedRulesCommonRuleSet'),
        ('AWSIPReputation', 6, 'AWSManagedRulesAmazonIpReputationList'),
        ('AWSBotControl', 7, 'AWSManagedRulesBotControlRuleSet'),
    ]:
        rules.append({'Name': name, 'Priority': priority, 'OverrideAction': {'None': {}},
                      'Statement': {'ManagedRuleGroupStatement': {'VendorName': 'AWS', 'Name': group}}})
    for name, priority, limit in [('RateLimitSensitivePaths', 8, 200), ('RateLimitGlobal', 13, 1500)]:
        rules.append({'Name': name, 'Priority': priority, 'Action': {'Block': {}},
                      'Statement': {'RateBasedStatement': {'Limit': limit, 'AggregateKeyType': 'IP'}}})
    return {'DefaultAction': {'Allow': {}}, 'Rules': rules}


def matches(statement, request):
    """Evaluate only our emitted Boolean predicates, never AWS managed signatures."""
    kind, rule = next(iter(statement.items()))
    if kind == 'AndStatement':
        return all(matches(s, request) for s in rule['Statements'])
    if kind == 'OrStatement':
        return any(matches(s, request) for s in rule['Statements'])
    if kind == 'NotStatement':
        return not matches(rule['Statement'], request)
    if kind == 'LabelMatchStatement':
        return rule['Key'] in request.get('labels', [])
    field = rule['FieldToMatch']
    if 'SingleHeader' in field:
        value = request['host']
    else:
        value = request[next(iter(field))]
    if any(t['Type'] == 'LOWERCASE' for t in rule['TextTransformations']):
        value = value.lower()
    if kind == 'RegexMatchStatement':
        return re.search(rule['RegexString'], value) is not None
    if kind == 'ByteMatchStatement':
        return value == rule['SearchString']
    if kind == 'SizeConstraintStatement':
        # No truncated-body assumption: over-limit inputs cannot qualify.
        if 'Body' in field and request.get('oversize', False):
            return field['Body']['OversizeHandling'] == 'MATCH'
        return len(value.encode()) == rule['Size']
    raise AssertionError('Unexpected predicate')


class WafExceptionsTest(unittest.TestCase):
    def setUp(self):
        self.original = source_acl()
        self.request = {'UriPath': '/.well-known/acme-challenge/' + 'a' * 43,
                        'Method': 'GET', 'host': 'v3.labdoo.org', 'QueryString': '', 'Body': ''}

    def test_all_five_terminal_bypasses_are_removed_without_mutating_source(self):
        before = deepcopy(self.original)
        rules = waf.narrow_rules(self.original)
        self.assertEqual(self.original, before)
        self.assertEqual(len(rules), 6)
        self.assertTrue(waf.REMOVED_ALLOWS.isdisjoint(r['Name'] for r in rules))
        self.assertFalse(any('Allow' in r.get('Action', {}) for r in rules))

    def test_common_reputation_and_both_rate_rules_are_byte_equivalent(self):
        result = {r['Name']: r for r in waf.narrow_rules(self.original)}
        for rule in self.original['Rules']:
            if rule['Name'] in waf.PROTECTED - {'AWSBotControl'}:
                self.assertEqual(result[rule['Name']], rule)

    def test_only_two_bot_signals_are_counted_and_have_a_following_block(self):
        rules = waf.narrow_rules(self.original)
        bot = next(r for r in rules if r['Name'] == 'AWSBotControl')
        self.assertEqual(bot['OverrideAction'], {'None': {}})
        group = bot['Statement']['ManagedRuleGroupStatement']
        self.assertNotIn('ScopeDownStatement', group)
        self.assertEqual({r['Name'] for r in group['RuleActionOverrides']}, set(waf.BOT_EXCEPTIONS))
        self.assertTrue(all(r['ActionToUse'] == {'Count': {}} for r in group['RuleActionOverrides']))
        self.assertNotIn('SignalAutomatedBrowser', waf.BOT_EXCEPTIONS)
        block = next(r for r in rules if r['Name'] == 'BlockNonAcmeBotSignals')
        self.assertLess(bot['Priority'], block['Priority'])
        self.assertEqual(block['Action'], {'Block': {}})

    def test_empty_get_and_head_challenges_match(self):
        for method in ['GET', 'HEAD']:
            self.request['Method'] = method
            self.assertTrue(matches(waf.acme_request(), self.request))

    def test_wrong_method_host_query_body_and_oversize_do_not_match(self):
        for key, value in [('Method', 'POST'), ('Method', 'PUT'), ('host', 'other.example'),
                           ('host', 'v3.labdoo.org.evil.example'), ('QueryString', 'ajax_form=1'),
                           ('Body', 'x'), ('oversize', True)]:
            request = dict(self.request, **{key: value})
            with self.subTest(key=key, value=value):
                self.assertFalse(matches(waf.acme_request(), request))

    def test_short_nested_traversal_encoded_and_long_tokens_do_not_match(self):
        for suffix in ['', 'short', '../admin', '%2e%2e%2fadmin', 'a' * 43 + '/extra', 'a' * 129]:
            request = dict(self.request, UriPath='/.well-known/acme-challenge/' + suffix)
            with self.subTest(suffix=suffix):
                self.assertFalse(matches(waf.acme_request(), request))

    def test_ajax_media_and_form_parameters_cannot_bypass_bot_labels(self):
        block = next(r for r in waf.narrow_rules(self.original) if r['Name'] == 'BlockNonAcmeBotSignals')
        for label in waf.BOT_EXCEPTIONS.values():
            for path in ['/en/node/1/edit', '/en/media-library', '/en/media/1/edit', '/en/file/1', '/en']:
                request = dict(self.request, UriPath=path, Method='POST', QueryString='ajax_form=1', labels=[label])
                with self.subTest(label=label, path=path):
                    self.assertTrue(matches(block['Statement'], request))
            self.assertFalse(matches(block['Statement'], dict(self.request, labels=[label])))

    def test_canonical_acme_with_no_bot_label_does_not_trigger_custom_block(self):
        block = next(r for r in waf.narrow_rules(self.original) if r['Name'] == 'BlockNonAcmeBotSignals')
        self.assertFalse(matches(block['Statement'], self.request))

    def test_unknown_inventory_existing_bot_exceptions_and_disabled_groups_fail_closed(self):
        changes = []
        inventory = deepcopy(self.original);inventory['Rules'].pop();changes.append(inventory)
        duplicate = deepcopy(self.original);duplicate['Rules'].append(duplicate['Rules'][0]);changes.append(duplicate)
        disabled = deepcopy(self.original);disabled['Rules'][5]['OverrideAction'] = {'Count': {}};changes.append(disabled)
        bot = deepcopy(self.original);bot['Rules'][7]['Statement']['ManagedRuleGroupStatement']['ScopeDownStatement'] = {};changes.append(bot)
        for acl in changes:
            with self.subTest(acl=acl), self.assertRaises(ValueError):
                waf.narrow_rules(acl)


if __name__ == '__main__':
    unittest.main(verbosity=2)
