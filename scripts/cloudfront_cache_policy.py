"""Build a narrow static cache change without AWS calls or private configuration."""

import copy
import re


STATIC_PATTERNS = tuple(
    ['/themes/*.' + ext for ext in ('css', 'js', 'webp', 'svg', 'png', 'jpg', 'woff2')]
    + [f'/{root}/*.{ext}' for root in ('core', 'modules') for ext in ('css', 'js')]
    + ['/sites/default/files/css/*.css', '/sites/default/files/js/*.js']
)


def equivalent_config(left, right):
    """Normalize AWS set ordering while preserving cache behavior precedence."""
    def normalize(value, field=None):
        if isinstance(value, list):
            return [normalize(item) for item in value]
        if not isinstance(value, dict):
            return value
        result = {key: normalize(item, key) for key, item in value.items()}
        if 'Items' in result:
            if field == 'Origins':
                result['Items'].sort(key=lambda item: item['Id'])
            elif field == 'CustomHeaders':
                result['Items'].sort(key=lambda item: item['HeaderName'].lower())
            elif field in {'AllowedMethods', 'CachedMethods'}:
                result['Items'].sort()
        return result
    return normalize(left) == normalize(right)


def cache_policy(name):
    """Separate Basic Auth credentials/hosts/versions and never force caching."""
    if not re.fullmatch(r'LabdooStaticAssets[A-Za-z0-9-]+', name):
        raise ValueError('Expected a task-owned cache policy name')
    return {
        'Name': name, 'Comment': 'AWS-A08 reviewed static extensions; private responses remain uncached',
        'MinTTL': 0, 'DefaultTTL': 300, 'MaxTTL': 300,
        'ParametersInCacheKeyAndForwardedToOrigin': {
            'EnableAcceptEncodingGzip': True, 'EnableAcceptEncodingBrotli': True,
            'HeadersConfig': {'HeaderBehavior': 'whitelist',
                              'Headers': {'Quantity': 2, 'Items': ['Authorization', 'Host']}},
            'CookiesConfig': {'CookieBehavior': 'none'},
            'QueryStringsConfig': {'QueryStringBehavior': 'all'},
        },
    }


def distribution_config(original, policy_id):
    """Prepend explicit static behaviors; retain the existing dynamic configuration."""
    if not re.fullmatch(r'[a-f0-9-]{36}', policy_id):
        raise ValueError('Expected one reviewed cache policy identifier')
    if original.get('Aliases', {}).get('Items') != ['v3.labdoo.org']:
        raise ValueError('Only the reviewed v3 distribution is in scope')
    old = original.get('CacheBehaviors', {}).get('Items', [])
    if original.get('CacheBehaviors', {}).get('Quantity') != len(old):
        raise ValueError('Inconsistent behavior inventory')
    if any(b['PathPattern'] in STATIC_PATTERNS for b in old):
        raise ValueError('Existing overlapping task behaviors need explicit review')
    default = original['DefaultCacheBehavior']
    if not default.get('CachePolicyId') or 'ForwardedValues' in default:
        raise ValueError('Expected the reviewed modern cache configuration')
    if default.get('FunctionAssociations', {}).get('Quantity', 0) or default.get(
            'LambdaFunctionAssociations', {}).get('Quantity', 0):
        raise ValueError('Unexpected edge code requires separate review')
    result = copy.deepcopy(original)
    source_origin = next(o for o in result['Origins']['Items']
                         if o['Id'] == default['TargetOriginId'])
    static_origin = copy.deepcopy(source_origin)
    static_origin['Id'] += '-static-assets-v1'
    if any(o['Id'] == static_origin['Id'] for o in result['Origins']['Items']):
        raise ValueError('An existing static origin requires explicit review')
    headers = static_origin.setdefault('CustomHeaders', {'Quantity': 0}).setdefault('Items', [])
    if any(h['HeaderName'].lower() == 'x-labdoo-static-cache' for h in headers):
        raise ValueError('Unexpected preexisting static marker')
    headers.append({'HeaderName': 'X-Labdoo-Static-Cache', 'HeaderValue': '1'})
    static_origin['CustomHeaders']['Quantity'] = len(headers)
    result['Origins']['Items'].append(static_origin)
    result['Origins']['Quantity'] = len(result['Origins']['Items'])
    added = []
    for pattern in STATIC_PATTERNS:
        behavior = copy.deepcopy(default)
        behavior.update({
            'PathPattern': pattern, 'CachePolicyId': policy_id, 'Compress': True,
            'TargetOriginId': static_origin['Id'],
            'AllowedMethods': {'Quantity': 3, 'Items': ['GET', 'HEAD', 'OPTIONS'],
                               'CachedMethods': {'Quantity': 2, 'Items': ['GET', 'HEAD']}},
        })
        # Cache-key fields are forwarded automatically. AllViewer would also
        # forward session cookies, which these public static handlers do not need.
        behavior.pop('OriginRequestPolicyId', None)
        added.append(behavior)
    if len(added) + len(old) > 25:
        raise ValueError('Reviewed behavior budget exceeded')
    result['CacheBehaviors'] = {'Quantity': len(added) + len(old),
                                'Items': added + copy.deepcopy(old)}
    errors = result.setdefault('CustomErrorResponses', {'Quantity': 0})
    entries = errors.setdefault('Items', [])
    # CloudFront's cacheable origin errors must not acquire a default ten-second
    # lifetime when an early Apache failure cannot run the response header guard.
    for code in (400, 403, 404, 405, 414, 500, 501, 502, 503, 504):
        entry = next((e for e in entries if e['ErrorCode'] == code), None)
        if entry is None:
            # Explicit empty elements preserve the native error status/body on
            # CloudFront's XML API while setting only its error-cache lifetime.
            entries.append({'ErrorCode': code, 'ErrorCachingMinTTL': 0,
                            'ResponsePagePath': '', 'ResponseCode': ''})
        else:
            entry['ErrorCachingMinTTL'] = 0
    errors['Quantity'] = len(entries)
    return result
