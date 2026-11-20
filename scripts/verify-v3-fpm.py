#!/usr/bin/env python3
"""Read-only checks through PHP-FPM, using the configured public theme contract."""
import argparse
import hashlib
import json
import re
import socket
import struct
from pathlib import Path
from urllib.parse import urljoin, urlsplit

ROUTES = [
    '/en?palette=ocean&theme=labdoo_next',
    '/en?palette=ocean&theme=labdoo_next',
    '/es/content/dootronics-dashboard?palette=ocean&theme=labdoo_next',
    '/en',
    '/es/content/dootronics-dashboard',
    '/es/content/edoovillages-dashboard',
    '/es/content/dootrips-dashboard',
    '/es/content/hubs-dashboard',
    '/es/actions',
    '/en/api/map-points/dootronic?_map=clusters&zoom=0',
    '/es/api/map-points/edoovillage?_map=clusters&zoom=0',
    '/en/labdoo/education-quote/en?exclude=mandela',
]


def record(kind, data=b''):
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data


def length(value):
    return bytes([value]) if value < 128 else struct.pack('!I', value | 0x80000000)


def request(uri, root, socket_path, host, scheme, depth=0):
    query = urlsplit(uri)
    env = {
        'GATEWAY_INTERFACE': 'CGI/1.1', 'SERVER_PROTOCOL': 'HTTP/1.1',
        'REQUEST_METHOD': 'GET', 'SCRIPT_FILENAME': str(root / 'index.php'),
        'SCRIPT_NAME': '/index.php', 'PHP_SELF': '/index.php',
        'DOCUMENT_ROOT': str(root), 'REQUEST_URI': uri,
        'QUERY_STRING': query.query, 'SERVER_NAME': host.split(':')[0],
        'HTTP_HOST': host, 'SERVER_PORT': str(urlsplit(scheme + '://' + host).port or (443 if scheme == 'https' else 80)),
        'HTTPS': 'on' if scheme == 'https' else 'off', 'REQUEST_SCHEME': scheme,
        'REMOTE_ADDR': '127.0.0.1', 'REMOTE_PORT': '12345',
        'SERVER_ADDR': '127.0.0.1', 'HTTP_ACCEPT': 'text/html,application/json',
        'HTTP_USER_AGENT': 'LabdooDeploymentVerification/2.0', 'CONTENT_LENGTH': '0',
    }
    params = b''.join(length(len(key.encode())) + length(len(value.encode())) + key.encode() + value.encode() for key, value in env.items())
    output, errors = bytearray(), bytearray()
    with socket.socket(socket.AF_UNIX) as connection:
        connection.settimeout(90)
        connection.connect(str(socket_path))
        connection.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, params) + record(4) + record(5))

        def read(size):
            data = bytearray()
            while len(data) < size:
                part = connection.recv(size - len(data))
                if not part:
                    raise RuntimeError('Premature FPM close')
                data.extend(part)
            return bytes(data)

        while True:
            _, kind, _, size, padding, _ = struct.unpack('!BBHHBB', read(8))
            data = read(size) if size else b''
            if padding:
                read(padding)
            if kind == 6:
                output.extend(data)
            elif kind == 7:
                errors.extend(data)
            elif kind == 3:
                break
    raw, _, body = bytes(output).partition(b'\r\n\r\n')
    headers = {key.lower(): value.strip() for line in raw.decode().splitlines() if ':' in line for key, value in [line.split(':', 1)]}
    status = int(headers.get('status', '200').split()[0])
    if status in [301, 302, 303, 307, 308]:
        assert not errors, 'PHP-FPM emitted stderr'
        assert depth < 5, 'Too many canonical redirects'
        destination = urlsplit(urljoin(scheme + '://' + host + uri, headers['location']))
        assert destination.netloc == host and destination.scheme == scheme, 'Unexpected redirect origin'
        suffix = destination.path + ('?' + destination.query if destination.query else '')
        return request(suffix, root, socket_path, host, scheme, depth + 1)
    return headers, body.decode(errors='replace'), bytes(errors)


def verify_response(uri, headers, text, errors, expected_theme='labdoo_next'):
    status = int(headers.get('status', '200').split()[0])
    result = {'uri': uri, 'status': status, 'page_cache': headers.get('x-drupal-cache'), 'dpc': headers.get('x-drupal-dynamic-cache'), 'fpm_errors': len(errors)}
    assert status == 200, result
    assert not errors, 'PHP-FPM emitted stderr'
    if '/api/map-points/' in uri:
        data = json.loads(text)
        assert isinstance(data, dict) and isinstance(data.get('points'), list), 'Missing clustered map response'
        assert all(isinstance(point.get('count'), int) and point['count'] > 0 for point in data['points']), 'Invalid map counts'
        assert sum(point['count'] for point in data['points']) == data['visible'] <= data['total'], 'Map counts do not match'
        result['map_points'] = data['total']
        result['map_clusters'] = len(data['points'])
    elif 'education-quote' in uri:
        data = json.loads(text)
        assert data['language'] == 'en' and data['quote']['id'] != 'mandela', 'Fresh quote validation failed'
        assert 'no-store' in headers.get('cache-control', '') and 'private' in headers.get('cache-control', ''), 'Fresh quotes must remain private and uncached'
        result['fresh_quote'] = True
    else:
        classes = re.search(r'<body[^>]*class="([^"]*)', text)
        assert classes, 'The response must contain a rendered page'
        result['next_body'] = 'labdoo-next' in classes.group(1).split()
        assert result['next_body'] == (expected_theme == 'labdoo_next'), result
        if expected_theme == 'labdoo_next':
            assert re.search(r'/themes/custom/labdoo_next/images/labdoo-wordmark(?:-\d+)?\.webp', text), 'Missing Next wordmark'
            assert 'src=""' not in text, 'Empty image source'
    return result


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('/mnt/data/labdoo/web'))
    parser.add_argument('--socket', type=Path, default=Path('/run/php/php8.3-fpm.sock'))
    parser.add_argument('--host', default='v3.labdoo.org')
    parser.add_argument('--scheme', choices=['http', 'https'], default='https')
    parser.add_argument('--expect-theme', choices=['labdoo_next', 'labdoo'], default='labdoo_next')
    parser.add_argument('--isolated', action='store_true', help='Require this checkout’s E2E response identity')
    args = parser.parse_args()
    root = args.root.resolve()
    assert (root / 'index.php').is_file(), 'Missing Drupal root'
    for uri in ROUTES:
        headers, text, errors = request(uri, root, args.socket, args.host, args.scheme)
        if args.isolated:
            assert headers.get('x-labdoo-e2e') == 'integration', 'Not an isolated test site'
            assert headers.get('x-labdoo-e2e-root') == hashlib.sha256(str(root.parent).encode()).hexdigest(), 'Different test checkout'
        print(json.dumps(verify_response(uri, headers, text, errors, args.expect_theme)), flush=True)


if __name__ == '__main__':
    main()
