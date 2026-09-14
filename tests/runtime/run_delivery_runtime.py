#!/usr/bin/env python3
"""Isolated localhost proof for current SDK, Redis, and PHP-FPM."""

import concurrent.futures
import hashlib
import http.server
import io
import json
import os
from pathlib import Path
import shutil
import socket
import struct
import subprocess
import sys
import tarfile
import tempfile
import threading
import time
import urllib.parse


ROOT = Path(__file__).resolve().parents[2]
REPO = ROOT.parents[1]
PROBE = Path(__file__).resolve().with_name('delivery_probe.php')
ROLLBACK_PACKAGE = Path(__file__).resolve().with_name('rollback-package.json')
BODY_V1 = '<main><h1>Legacy Blog</h1><img src="/cover.jpg"><ul><li>Item</li></ul><a href="/blog/next">Next</a></main>'
BODY_V2 = BODY_V1.replace('Legacy Blog', 'Versioned Blog')


def check(condition, message):
    if not condition:
        raise AssertionError(message)


def free_port():
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        return listener.getsockname()[1]


def wait_port(port, process):
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if process.poll() is not None:
            raise RuntimeError(f'process exited early: {process.returncode}')
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                return
        except OSError:
            time.sleep(0.05)
    raise RuntimeError(f'port {port} was not ready')


class Origin(http.server.BaseHTTPRequestHandler):
    requests = []
    lock = threading.Lock()

    def log_message(self, *_):
        pass

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        with self.lock:
            self.requests.append(parsed.path)
        now = time.time()
        if parsed.path == '/api/v1/public/page':
            payload = {'status': 'ready', 'page': {'slug': 'article', 'bodyHtml': BODY_V1}}
            status = 200
        elif parsed.path == '/api/v2/public/cms-page':
            payload = {
                'status': 'ready',
                'page': {'slug': 'article', 'bodyHtml': BODY_V2},
                'delivery': {
                    'contract': '2',
                    'content_version': 'sha256:' + ('b' * 64),
                    'validated_at': iso(now),
                    'fresh_until': iso(now + 300),
                    'usable_until': iso(now + 3600),
                },
            }
            status = 200
        else:
            payload, status = {'error': 'unexpected_request'}, 500
        encoded = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(encoded)))
        self.end_headers()
        self.wfile.write(encoded)


class OriginServer(http.server.ThreadingHTTPServer):
    daemon_threads = True


def iso(seconds):
    return time.strftime('%Y-%m-%dT%H:%M:%S.000Z', time.gmtime(seconds))


def record(kind, data=b''):
    return struct.pack('!BBHHBB', 1, kind, 1, len(data), 0, 0) + data


def length(number):
    return bytes([number]) if number < 128 else struct.pack('!I', number | 0x80000000)


def read_exact(stream, count):
    output = b''
    while len(output) < count:
        chunk = stream.recv(count - len(output))
        if not chunk:
            raise RuntimeError('truncated FastCGI response')
        output += chunk
    return output


def fastcgi(port, action):
    query = urllib.parse.urlencode({'action': action})
    params = {
        'GATEWAY_INTERFACE': 'CGI/1.1',
        'SERVER_PROTOCOL': 'HTTP/1.1',
        'REQUEST_METHOD': 'GET',
        'SCRIPT_FILENAME': str(PROBE),
        'SCRIPT_NAME': '/delivery_probe.php',
        'REQUEST_URI': '/delivery_probe.php?' + query,
        'DOCUMENT_URI': '/delivery_probe.php',
        'QUERY_STRING': query,
        'SERVER_NAME': 'shop.runtime.test',
        'SERVER_PORT': '80',
        'HTTP_HOST': 'shop.runtime.test',
        'REMOTE_ADDR': '127.0.0.1',
        'REMOTE_PORT': '12345',
        'REDIRECT_STATUS': '200',
    }
    encoded = b''.join(
        length(len(key)) + length(len(value)) + key.encode() + value.encode()
        for key, value in params.items()
    )
    started = time.monotonic()
    output, errors = b'', b''
    with socket.create_connection(('127.0.0.1', port), timeout=5) as stream:
        stream.sendall(record(1, struct.pack('!HB5x', 1, 0)) + record(4, encoded) + record(4) + record(5))
        while True:
            version, kind, request_id, size, padding, _ = struct.unpack('!BBHHBB', read_exact(stream, 8))
            check(version == 1 and request_id == 1, 'unexpected FastCGI framing')
            data = read_exact(stream, size)
            read_exact(stream, padding)
            if kind == 6:
                output += data
            elif kind == 7:
                errors += data
            elif kind == 3:
                break
    check(not errors, errors.decode(errors='replace')[:1000])
    headers, body = output.split(b'\r\n\r\n', 1)
    status = 200
    for header in headers.decode().split('\r\n'):
        if header.lower().startswith('status:'):
            status = int(header.split()[1])
    return {'status': status, 'body': body.decode(), 'seconds': time.monotonic() - started}


def stop(process):
    if process is None or process.poll() is not None:
        return
    process.terminate()
    try:
        process.wait(timeout=5)
    except subprocess.TimeoutExpired:
        process.kill()
        process.wait(timeout=5)


def main():
    fixed_package = sys.argv[1:] == ['--fixed-package']
    check(not sys.argv[1:] or fixed_package, 'usage: run_delivery_runtime.py [--fixed-package]')
    redis_binary = shutil.which('redis-server')
    fpm_binary = shutil.which('php84-fpm') or shutil.which('php-fpm')
    php_binary = shutil.which('php')
    check(redis_binary and fpm_binary and php_binary, 'php, php-fpm, and redis-server are required')

    redis_port, origin_port, fpm_port = free_port(), free_port(), free_port()
    redis_process = fpm_process = None
    origin = OriginServer(('127.0.0.1', origin_port), Origin)
    origin_thread = threading.Thread(target=origin.serve_forever, daemon=True)
    origin_thread.start()

    with tempfile.TemporaryDirectory(prefix='smking-delivery-runtime-') as temporary:
        temporary_path = Path(temporary)
        try:
            package_evidence = None
            runtime_vendor = ROOT / 'vendor'
            runtime_package = None
            if fixed_package:
                package_evidence = json.loads(ROLLBACK_PACKAGE.read_text())
                commit = package_evidence['monorepoCommit']
                tree = package_evidence['tree']
                actual_tree = subprocess.run(
                    ['git', 'rev-parse', f'{commit}:packages/smking-laravel'],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    timeout=10,
                    check=True,
                ).stdout.strip()
                check(actual_tree == tree, 'fixed package tree differs from manifest')
                archive = subprocess.run(
                    ['git', 'archive', '--format=tar', '--mtime=1970-01-01T00:00:00Z', tree],
                    cwd=REPO,
                    capture_output=True,
                    timeout=15,
                    check=True,
                ).stdout
                check(hashlib.sha256(archive).hexdigest() == package_evidence['archiveSha256'], 'fixed package archive differs')
                lock = ROOT / 'compat/laravel10/composer.lock'
                check(hashlib.sha256(lock.read_bytes()).hexdigest() == package_evidence['laravel10LockSha256'], 'Laravel 10 lock differs')
                runtime_package = temporary_path / 'rollback-package'
                runtime_package.mkdir()
                with tarfile.open(fileobj=io.BytesIO(archive), mode='r:') as package_archive:
                    members = package_archive.getmembers()
                    check(all(
                        not member.name.startswith('/')
                        and '..' not in Path(member.name).parts
                        and (member.isdir() or member.isfile())
                        for member in members
                    ), 'fixed package archive contains unsupported paths')
                    package_archive.extractall(runtime_package, members=members, filter='data')
                runtime_vendor = ROOT / 'compat/laravel10/vendor'

            environment = {
                'PATH': os.environ.get('PATH', '/usr/bin:/bin'),
                'SMKING_RUNTIME_REDIS_PORT': str(redis_port),
                'SMKING_RUNTIME_ORIGIN_PORT': str(origin_port),
                'SMKING_RUNTIME_VENDOR': str(runtime_vendor),
                **({'SMKING_RUNTIME_PACKAGE': str(runtime_package)} if runtime_package else {}),
            }
            redis_process = subprocess.Popen([
                redis_binary,
                '--bind', '127.0.0.1',
                '--port', str(redis_port),
                '--protected-mode', 'yes',
                '--save', '',
                '--appendonly', 'no',
                '--dir', temporary,
            ], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, text=True)
            wait_port(redis_port, redis_process)

            seed = subprocess.run(
                [php_binary, str(PROBE), 'legacy-seed'],
                cwd=ROOT,
                env=environment,
                text=True,
                capture_output=True,
                timeout=15,
                check=True,
            )
            check(json.loads(seed.stdout)['body'] == BODY_V1, 'legacy seed did not use the real CMS client')
            prepared = subprocess.run(
                [php_binary, str(PROBE), 'prepare'],
                cwd=ROOT,
                env=environment,
                text=True,
                capture_output=True,
                timeout=15,
                check=True,
            )
            prepared_payload = json.loads(prepared.stdout)
            check(prepared_payload['summary']['ready'] == 1, 'prewarm did not prepare the reader cache')
            check(Origin.requests == ['/api/v1/public/page', '/api/v2/public/cms-page'], 'prewarm used unexpected HTTP')

            fpm_config = temporary_path / 'fpm.conf'
            fpm_config.write_text('\n'.join([
                '[global]',
                'daemonize = no',
                f'error_log = {temporary_path / "fpm-error.log"}',
                '[www]',
                f'listen = 127.0.0.1:{fpm_port}',
                'pm = static',
                'pm.max_children = 4',
                'clear_env = no',
                'catch_workers_output = yes',
                'security.limit_extensions = .php',
            ]))
            fpm_process = subprocess.Popen(
                [fpm_binary, '--nodaemonize', '--fpm-config', str(fpm_config)],
                cwd=ROOT,
                env=environment,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.PIPE,
                text=True,
            )
            wait_port(fpm_port, fpm_process)

            identity = json.loads(fastcgi(fpm_port, 'identity')['body'])
            if runtime_package:
                expected_source = runtime_package / 'src/Delivery/OnDemandDelivery.php'
                check(identity['source'] == str(expected_source.resolve()), 'FPM did not load the fixed package')
                check(identity['sha256'] == hashlib.sha256(expected_source.read_bytes()).hexdigest(), 'loaded package source differs')

            with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
                reads = list(pool.map(lambda _: fastcgi(fpm_port, 'read'), range(50)))
            check(all(item['status'] == 200 and item['body'] == BODY_V2 for item in reads), 'warm Redis reads were not exact 200 responses')
            check(Origin.requests == ['/api/v1/public/page', '/api/v2/public/cms-page'], 'warm FPM reads used HTTP')

            rollback = fastcgi(fpm_port, 'legacy-read')
            check(rollback['status'] == 200 and rollback['body'] == BODY_V1, 'legacy rollback cache was not preserved')
            check(len(Origin.requests) == 2, 'rollback unexpectedly used HTTP')

            with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
                tracked = list(pool.map(lambda _: fastcgi(fpm_port, 'track'), range(20)))
            check(all(item['status'] == 200 for item in tracked), 'crawler tracking did not release FPM workers')
            check(not any(path.endswith('/sdk/report') or path.endswith('/crawler-hit') for path in Origin.requests), 'visitor tracking sent HTTP')

            stop(redis_process)
            redis_process = None
            unavailable = fastcgi(fpm_port, 'read')
            check(unavailable['status'] == 503 and unavailable['body'] == 'Service unavailable', 'Redis outage was not an explicit non-empty 503')
            check(len(Origin.requests) == 2, 'Redis outage fell back to visitor HTTP')

            evidence = {
                'runtime': 'php-fpm + redis',
                'package': ({
                    'kind': 'fixed-rollback-candidate',
                    'commit': package_evidence['monorepoCommit'],
                    'tree': package_evidence['tree'],
                    'archive_sha256': package_evidence['archiveSha256'],
                    'laravel': '10.50.2',
                } if package_evidence else {'kind': 'current-worktree', 'laravel': '12'}),
                'prewarm': prepared_payload['summary'],
                'warm_reads': len(reads),
                'warm_http_statuses': sorted(set(item['status'] for item in reads)),
                'warm_body_bytes': len(BODY_V2.encode()),
                'origin_gets': Origin.requests,
                'rollback_status': rollback['status'],
                'tracking_requests': len(tracked),
                'tracking_max_seconds': round(max(item['seconds'] for item in tracked), 3),
                'redis_outage': {'status': unavailable['status'], 'body_bytes': len(unavailable['body'])},
            }
            print(json.dumps(evidence, separators=(',', ':')))
        finally:
            stop(fpm_process)
            stop(redis_process)
            origin.shutdown()
            origin.server_close()


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'error': type(error).__name__, 'message': str(error)}), file=sys.stderr)
        raise
