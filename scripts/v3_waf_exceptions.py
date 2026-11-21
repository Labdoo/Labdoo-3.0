"""Remove terminal WAF bypasses and preserve only narrow ACME bot exceptions."""
from copy import deepcopy

REMOVED_ALLOWS = frozenset({
    'AllowAcmeChallenge', 'AllowLoadTestIP', 'AllowDrupalAjaxNodeEdit',
    'AllowDrupalMediaAjaxUpload', 'AllowDrupalMedia',
})
PROTECTED = frozenset({
    'AWSCommonRules', 'AWSIPReputation', 'AWSBotControl',
    'RateLimitSensitivePaths', 'RateLimitGlobal',
})
BOT_EXCEPTIONS = {
    'CategoryHttpLibrary': 'awswaf:managed:aws:bot-control:bot:category:http_library',
    'SignalNonBrowserUserAgent': 'awswaf:managed:aws:bot-control:signal:non_browser_user_agent',
}


def acme_request():
    """Match only empty GET/HEAD requests for a canonical v3 challenge token."""
    transform = [{'Priority': 0, 'Type': 'NONE'}]
    return {'AndStatement': {'Statements': [
        {'RegexMatchStatement': {
            'RegexString': r'^/\.well-known/acme-challenge/[A-Za-z0-9_-]{20,128}$',
            'FieldToMatch': {'UriPath': {}}, 'TextTransformations': deepcopy(transform),
        }},
        {'RegexMatchStatement': {
            'RegexString': '^(GET|HEAD)$', 'FieldToMatch': {'Method': {}},
            'TextTransformations': deepcopy(transform),
        }},
        {'RegexMatchStatement': {
            'RegexString': r'^v3\.labdoo\.org$',
            'FieldToMatch': {'SingleHeader': {'Name': 'host'}},
            'TextTransformations': [{'Priority': 0, 'Type': 'LOWERCASE'}],
        }},
        {'SizeConstraintStatement': {
            'ComparisonOperator': 'EQ', 'Size': 0, 'FieldToMatch': {'QueryString': {}},
            'TextTransformations': deepcopy(transform),
        }},
        {'SizeConstraintStatement': {
            'ComparisonOperator': 'EQ', 'Size': 0,
            'FieldToMatch': {'Body': {'OversizeHandling': 'NO_MATCH'}},
            'TextTransformations': deepcopy(transform),
        }},
    ]}}


def narrow_rules(web_acl):
    """Build a reviewed replacement; perform no AWS calls or source mutations."""
    rules = deepcopy(web_acl['Rules'])
    names = [rule['Name'] for rule in rules]
    if len(names) != len(set(names)) or set(names) != REMOVED_ALLOWS | PROTECTED:
        raise ValueError('Unexpected ACL rule inventory; review before changing it')
    if web_acl['DefaultAction'] != {'Allow': {}}:
        raise ValueError('Unexpected default action')
    by_name = {rule['Name']: rule for rule in rules}
    if any(by_name[name].get('Action') != {'Allow': {}} for name in REMOVED_ALLOWS):
        raise ValueError('Expected the five reviewed terminal Allow rules')
    if any(by_name[name].get('OverrideAction') != {'None': {}}
           for name in ['AWSCommonRules', 'AWSIPReputation', 'AWSBotControl']):
        raise ValueError('Managed group enforcement must remain enabled')
    bot = by_name['AWSBotControl']['Statement']['ManagedRuleGroupStatement']
    if bot.get('VendorName') != 'AWS' or bot.get('Name') != 'AWSManagedRulesBotControlRuleSet':
        raise ValueError('Unexpected bot group')
    if any(key in bot for key in ['RuleActionOverrides', 'ExcludedRules', 'ScopeDownStatement']):
        raise ValueError('Existing bot exceptions require a separate review')
    # Only these two signals continue to a compensating Block rule. Every other
    # Bot Control rule retains its native action, including automated browsers.
    bot['RuleActionOverrides'] = [
        {'Name': name, 'ActionToUse': {'Count': {}}} for name in BOT_EXCEPTIONS
    ]
    result = [rule for rule in rules if rule['Name'] not in REMOVED_ALLOWS]
    priorities = {rule['Name']: rule['Priority'] for rule in result}
    if not (priorities['AWSCommonRules'] < priorities['AWSBotControl'] < 9
            and priorities['AWSIPReputation'] < priorities['AWSBotControl']
            and priorities['RateLimitSensitivePaths'] < 9 < priorities['RateLimitGlobal']):
        raise ValueError('Unexpected rule ordering')
    result.append({
        'Name': 'BlockNonAcmeBotSignals', 'Priority': 9, 'Action': {'Block': {}},
        'Statement': {'AndStatement': {'Statements': [
            {'OrStatement': {'Statements': [
                {'LabelMatchStatement': {'Scope': 'LABEL', 'Key': label}}
                for label in BOT_EXCEPTIONS.values()
            ]}},
            {'NotStatement': {'Statement': acme_request()}},
        ]}},
        'VisibilityConfig': {
            'SampledRequestsEnabled': True, 'CloudWatchMetricsEnabled': True,
            'MetricName': 'block-non-acme-bot-signals',
        },
    })
    return sorted(result, key=lambda rule: rule['Priority'])
