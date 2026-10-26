#!/usr/bin/env python3
"""Exercise built Manager images in a self-owned, disposable Compose project."""

import argparse
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[2]
TOKEN = "synthetic-security-token-0000000000000000"


def main(summary_file):
    summary = json.loads(summary_file.read_text())
    if summary["status"] != "passed":
        raise ValueError("Only successfully audited images may run acceptance")
    for architecture in ("amd64", "arm64"):
        images = {item["artifact"]: item["runtime_image"] for item in summary["images"]
                  if item["platform"] == "linux/" + architecture}
        project = "quickquiz-security-smoke-" + uuid.uuid4().hex[:10]
        environment = os.environ | {"SECURITY_FPM_IMAGE": images["manager-fpm"],
                                    "SECURITY_WEB_IMAGE": images["manager-web"],
                                    "SECURITY_PLATFORM": "linux/" + architecture}
        command = ["docker", "compose", "--project-name", project, "--file", str(ROOT / "deploy/security/compose.test.yml")]

        def compose(*args, input=None):
            result = subprocess.run(command + list(args), env=environment, text=True, capture_output=True, input=input)
            if result.returncode:
                raise RuntimeError(f"Disposable Compose command failed: {' '.join(args[:3])}: {result.stderr[-1500:]}")
            return result.stdout

        cookies = http.cookiejar.CookieJar()
        browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
        try:
            compose("up", "-d", "--wait", "--wait-timeout", "90")
            runtime = json.loads(compose('exec', '-T', 'manager-fpm', 'php', input=(ROOT / 'deploy/security/fpm-probe.php').read_text()))
            assert runtime['upload_progress'] == '0'
            assert runtime['memory_limit'] not in ('-1', '0', '')
            assert runtime['post_max_size'] == '2M' and runtime['upload_max_filesize'] == '2M'
            compose("exec", "-T", "manager-fpm", "php", "bin/console", "manager:database:migrate", "--no-interaction")
            # This brand-new, empty fixture has no legacy catalog to cut over.
            initialized = compose("exec", "-T", "db", "psql", "-U", "postgres", "-d", "quickquiz_manager_runtime_test", "-Atc",
                                  "UPDATE quiz_catalog_state SET publication_backend='postgres',fallback_locale='en-US',supported_locales=ARRAY['en-US','pt-BR'] WHERE singleton=1 AND current_revision=0 AND published_revision=0 RETURNING publication_backend")
            assert initialized.strip().startswith('postgres'), 'Expected an empty disposable catalog'
            compose("exec", "-T", "manager-fpm", "php", "bin/console", "manager:admin:create", "security@example.invalid", "synthetic-password")
            base = "http://" + compose("port", "manager-web", "8080").strip()

            def request(path, data=None, headers=None, expected=200, client=None, method=None):
                req = urllib.request.Request(base + path, data, headers or {}, method=method)
                try:
                    response = (client or browser).open(req, timeout=30)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    body = response.read().decode()
                    if response.status != expected:
                        # This self-owned project contains only synthetic fixtures.
                        detail = json.loads(body).get('error', {}) if path.startswith('/api/') else {}
                        raise AssertionError(f"{path}: {response.status}, expected {expected}: {detail}")
                    return body, response.url, response.headers

            def form(path, values):
                return request(path, urllib.parse.urlencode(values).encode(), {"Content-Type": "application/x-www-form-urlencoded"})

            def csrf(body):
                return re.search(r'name="_csrf" value="([a-f0-9]+)"', body)[1]

            html, _, _ = request("/login")
            token = csrf(html)
            before = [cookie.value for cookie in cookies]
            html, _, _ = form("/login", {"_csrf": token, "email": "security@example.invalid", "password": "wrong"})
            assert "Invalid email or password" in html
            html, url, _ = form("/login", {"_csrf": token, "email": "security@example.invalid", "password": "synthetic-password"})
            assert "/login" not in url and "security@example.invalid" in html
            assert before != [cookie.value for cookie in cookies], "Login did not renew the session"
            admin = {"Content-Type": "application/json", "Authorization": "Bearer " + TOKEN}
            request("/api/admin/quiz/themes", headers={"Authorization": "Bearer invalid"}, expected=401)
            request("/api/admin/quiz/themes", headers={}, expected=401)
            payload = {"id": "security-smoke", "name": "Synthetic security", "description": "Disposable fixture", "active": False, "weight": 10}
            request("/api/admin/quiz/themes", json.dumps(payload).encode(), admin, 201)
            html, _, _ = request("/themes")
            token = csrf(html)
            html, _, _ = form("/themes/save", {"_csrf": "invalid", "id": "security-smoke", "name": "Must not persist"})
            assert "Invalid CSRF token" in html
            body, _, _ = request("/api/admin/quiz/themes/security-smoke", headers=admin)
            assert json.loads(body)["theme"]["name"] == "Synthetic security"
            html, _, _ = form("/themes/save", payload | {"_csrf": token, "active": "0", "name": "Browser updated"})
            body, _, _ = request("/api/admin/quiz/themes/security-smoke", headers=admin)
            assert json.loads(body)["theme"]["name"] == "Browser updated"
            html, _, _ = form("/themes/select", {"_csrf": token, "theme": "security-smoke"})
            token = csrf(html)
            package = json.dumps({"theme": "security-smoke", "prompts": [{"key": "question_solution", "text": "Synthetic imported prompt"}]})
            boundary = "security" + uuid.uuid4().hex
            multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n{token}\r\n'
                         f'--{boundary}\r\nContent-Disposition: form-data; name="promptFile"; filename="fixture.json"\r\n'
                         f'Content-Type: application/json\r\n\r\n{package}\r\n--{boundary}--\r\n').encode()
            html, url, _ = request("/ai-prompts/import", multipart, {"Content-Type": "multipart/form-data; boundary=" + boundary})
            assert "imported=1" in url
            html, _, _ = request("/ai-prompts/question_solution/edit")
            assert "Synthetic imported prompt" in html
            compose("up", "-d", "--no-deps", "--force-recreate", "manager-fpm")
            # Refresh Nginx's resolved service address after container replacement.
            compose("exec", "-T", "manager-web", "nginx", "-s", "reload")
            for attempt in range(30):
                try:
                    html, url, _ = request("/themes")
                    assert "/login" not in url and "security@example.invalid" in html
                    break
                except (AssertionError, urllib.error.URLError):
                    if attempt == 29:
                        raise
                    time.sleep(1)
            html, _, _ = form("/logout", {"_csrf": csrf(html)})
            _, url, _ = request("/themes")
            assert "/login" in url
            _, _, headers = request("/login", headers={"X-Forwarded-Proto": "https"}, client=urllib.request.build_opener())
            cookie = headers.get("Set-Cookie", "").lower()
            assert "secure" in cookie and "httponly" in cookie and "samesite=lax" in cookie
            print(json.dumps({"platform": "linux/" + architecture, "passed": True,
                              "runtime": runtime,
                              "checks": ["effective-fpm-pool-settings", "login-rejection", "session-renewal", "bearer-auth", "csrf", "browser-crud",
                                         "multipart-import", "redis-session-after-fpm-replacement", "logout", "secure-cookie"]}), flush=True)
        finally:
            compose("down", "--volumes", "--remove-orphans")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("summary", type=Path)
    main(parser.parse_args().summary)
