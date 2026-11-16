"""Exercise the real admin-post boundary, including WP_List_Table nonce collisions."""
import csv
import html
from html.parser import HTMLParser
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

root = Path(__file__).resolve().parents[1]
wp_path = os.environ['YSM_TEST_WP_PATH']
wp = [os.environ.get('YSM_TEST_WP_CLI', 'wp'), '--path=' + wp_path]
def cli(*args, env=None):
    return subprocess.run(wp + list(args), env=env, capture_output=True, text=True, check=True).stdout.strip()
assert cli('option', 'get', 'ysm_disposable_tests') == '1', 'Disposable opt-in required'
password = str(uuid.uuid4())
env = dict(os.environ, YSM_HTTP_PASSWORD=password)
def fixture(mode):
    return cli('eval-file', str(root / 'tests/integration/http-fixture.php'), env=dict(env, YSM_HTTP_MODE=mode))
class Inputs(HTMLParser):
    def __init__(self, text):
        super().__init__()
        self.values = {}
        self.forms = []
        self.controls = []
        self.current_form = None
        self.feed(text)
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            self.current_form = dict(attrs, identity=attrs.get('id') or 'form-' + str(len(self.forms)))
            self.forms.append(self.current_form)
        if tag == 'input' and attrs.get('name'):
            self.values[attrs['name']] = attrs.get('value', '')
            self.controls.append(dict(attrs, owner=attrs.get('form') or (self.current_form or {}).get('identity')))
    def handle_endtag(self, tag):
        if tag == 'form':
            self.current_form = None
def request(client, url, data=None):
    try:
        response = client.open(url, urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.read().decode()
def session(login, base):
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    request(client, base + '/wp-login.php')
    status, body = request(client, base + '/wp-login.php', {'log': login, 'pwd': password, 'wp-submit': 'Log In', 'redirect_to': base + '/wp-admin/', 'testcookie': '1'})
    assert status == 200 and 'login_error' not in body, 'Fixture login failed'
    return client

old_home = cli('option', 'get', 'home')
old_site = cli('option', 'get', 'siteurl')
with socket.socket() as port_socket:
    port_socket.bind(('127.0.0.1', 0))
    port = port_socket.getsockname()[1]
base = f'http://127.0.0.1:{port}'
server = None
data = None
try:
    data = json.loads(fixture('setup'))
    cli('option', 'update', 'home', base)
    cli('option', 'update', 'siteurl', base)
    with tempfile.TemporaryFile() as log:
        server = subprocess.Popen([os.environ.get('YSM_TEST_PHP_BIN', 'php'), '-d', 'memory_limit=512M', '-d', 'error_reporting=24567', '-S', f'127.0.0.1:{port}', '-t', wp_path], stdout=log, stderr=log)
        for attempt in range(50):
            try:
                urllib.request.urlopen(base + '/wp-login.php', timeout=1).close()
                break
            except (OSError, urllib.error.URLError):
                time.sleep(0.1)
        admin = session(data['users'][0]['login'], base)
        subscriber = session(data['users'][1]['login'], base)
        cap_only = session(data['users'][2]['login'], base)
        status, body = request(admin, base + '/wp-admin/admin.php?page=yoohw-stock-manager')
        assert status == 200
        parsed = Inputs(body)
        fields = parsed.values
        bulk_form = next(form for form in parsed.forms if form['identity'] == 'ysm-bulk-adjustment')
        assert bulk_form['method'] == 'post' and bulk_form['action'].endswith('/admin-post.php')
        row_controls = [control for control in parsed.controls if control['name'] == 'product_ids[]']
        assert row_controls and all(control['owner'] == 'ysm-bulk-adjustment' for control in row_controls), 'Row selection is not associated with the bulk form'
        table_form = next(form for form in parsed.forms if form.get('method') == 'get' and form.get('action', '').endswith('/admin.php'))
        table_fields = {control['name']: control.get('value', '') for control in parsed.controls if control['owner'] == table_form['identity']}
        assert 'action' not in table_fields and 'ysm_nonce' not in table_fields and 'quantity' not in table_fields, 'Read-only table owns stock mutation fields'
        page_status, page_body = request(admin, table_form['action'] + '?' + urllib.parse.urlencode(dict(table_fields, paged='2')))
        assert page_status == 200 and 'successful,' not in page_body, 'Pagination dispatched adjustment'
        assert fields['ysm_nonce'] != fields['_wpnonce'], 'Table nonce collided with action nonce'
        payload = {'action': 'ysm_adjust', 'ysm_nonce': fields['ysm_nonce'], '_wpnonce': fields['_wpnonce'],
                   'operation_uuid': fields['operation_uuid'], 'product_ids[]': data['products'],
                   'operation': 'increase', 'quantity': '1', 'reason': 'goods_received', 'note': '=SUM(A1)'}
        status, body = request(admin, base + '/wp-admin/admin-post.php', payload)
        assert status == 200 and '1 successful, 1 failed, 0 skipped' in body, 'Mixed bulk result failed'
        status, body = request(admin, base + '/wp-admin/admin-post.php', payload)
        assert status == 200 and '0 successful, 1 failed, 1 skipped' in body, 'HTTP replay failed'
        denied = dict(payload)
        denied.pop('ysm_nonce')
        assert request(admin, base + '/wp-admin/admin-post.php', denied)[0] == 403, 'Missing nonce accepted'
        assert request(subscriber, base + '/wp-admin/admin-post.php', payload)[0] == 403, 'Subscriber write accepted'
        assert request(subscriber, base + '/wp-admin/admin.php?page=ysm-history')[0] == 403, 'Subscriber history accepted'
        cap_status, cap_body = request(cap_only, base + '/wp-admin/admin.php?page=yoohw-stock-manager')
        assert cap_status == 200, 'manage_woocommerce read access rejected'
        cap_fields = Inputs(cap_body).values
        cap_payload = dict(payload, ysm_nonce=cap_fields['ysm_nonce'], operation_uuid=str(uuid.uuid4()))
        cap_status, cap_body = request(cap_only, base + '/wp-admin/admin-post.php', cap_payload)
        assert cap_status == 200 and '0 successful, 2 failed' in cap_body, 'Product edit permission bypassed'
        shared_payload = dict(payload, operation_uuid=str(uuid.uuid4()))
        shared_payload['product_ids[]'] = data['shared_variations'] + [data['shared_parent']]
        shared_status, shared_body = request(admin, base + '/wp-admin/admin-post.php', shared_payload)
        assert shared_status == 200 and '1 successful, 0 failed, 2 skipped' in shared_body, 'Shared owner not deduplicated'
        shared_status, shared_body = request(admin, base + '/wp-admin/admin-post.php', shared_payload)
        assert shared_status == 200 and '0 successful, 0 failed, 3 skipped' in shared_body, 'Shared-owner replay failed'
        status, body = request(admin, base + '/wp-admin/admin.php?page=ysm-history&product_id=' + str(data['products'][0]))
        export = html.unescape(next(link for link in re.findall(r'href="([^"]+)"', body) if 'action=ysm_export' in link))
        status, content = request(admin, export)
        rows = list(csv.reader(io.StringIO(content)))
        assert status == 200 and len(rows) == 2 and rows[1][-1] == "'=SUM(A1)", 'Scoped CSV/formula failed'
        assert request(subscriber, export)[0] == 403, 'Subscriber export accepted'
        fixture('verify')
        print('PASS HTTP read-only pagination/form ownership, nonce, mixed bulk, replay, denied capabilities and scoped CSV')
finally:
    if server:
        server.terminate()
        server.wait(timeout=10)
    cli('option', 'update', 'home', old_home)
    cli('option', 'update', 'siteurl', old_site)
    if data:
        fixture('cleanup')
