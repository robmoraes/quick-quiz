#!/usr/bin/env python3
"""Synthetic HTTP acceptance against an explicitly isolated Manager/API pair.

Requires QQ_RUNTIME_SMOKE_DISPOSABLE=yes. It creates and removes only its own
unique theme; never run against a shared development/production catalog.
"""
import json
import os
import time
import uuid
from urllib.error import HTTPError
from urllib.request import Request, urlopen


def request(base, path, method='GET', payload=None, headers=None, expected=200):
    body = None if payload is None else json.dumps(payload).encode()
    request_headers = {'Content-Type': 'application/json', **(headers or {})}
    req = Request(base + path, body, request_headers, method=method)
    try:
        with urlopen(req, timeout=10) as response:
            status, raw = response.status, response.read()
    except HTTPError as error:
        status, raw = error.code, error.read()
    if status != expected:
        raise AssertionError(f'{method} {path}: {status}, expected {expected}: {raw.decode()[:600]}')
    return json.loads(raw) if raw else None


def main():
    if os.environ.get('QQ_RUNTIME_SMOKE_DISPOSABLE') != 'yes':
        raise SystemExit('Set QQ_RUNTIME_SMOKE_DISPOSABLE=yes for an isolated disposable pair only.')
    manager = os.environ['QQ_RUNTIME_SMOKE_MANAGER_URL'].rstrip('/')
    api = os.environ['QQ_RUNTIME_SMOKE_API_URL'].rstrip('/')
    admin = {'Authorization': 'Bearer ' + os.environ['QQ_RUNTIME_SMOKE_ADMIN_TOKEN']}
    theme = 'smoke-literature-' + uuid.uuid4().hex[:10]
    player = {'X-QuickQuiz-Theme': theme, 'X-QuickQuiz-Session-ID': uuid.uuid4().hex, 'X-QuickQuiz-Locale': 'pt-BR'}
    path = '/api/admin/quiz/themes/' + theme
    created = False
    started = time.monotonic()
    try:
        request(api, '/readyz')
        request(manager, '/api/admin/quiz/themes', expected=401)
        result = request(manager, '/api/admin/quiz/themes', 'POST',
                         {'id': theme, 'name': 'Synthetic literature', 'description': 'Disposable acceptance',
                          'active': False, 'weight': 999, 'tags': ['arts']}, admin, 201)
        created = True
        assert result['publication']['backend'] == 'postgres'
        assert result['publication']['apiReloadRequired'] is False
        assert theme not in [item['id'] for item in request(api, '/api/themes')['themes']]
        topic = {'key': 'poetry', 'name': 'Poetry', 'description': 'Synthetic topic', 'active': True,
                 'weight': 10, 'tags': ['private-smoke'],
                 'localizations': {'pt-BR': {'name': 'Poesia', 'description': 'Tópico sintético'}}}
        request(manager, path + '/topics', 'POST', topic, admin, 201)
        translations = {locale: {'prompt': 'Original ' + locale, 'correctOptions': ['Correct'],
                                 'wrongOptions': ['Alternative A', 'Alternative B']}
                        for locale in ('en-US', 'pt-BR')}
        request(manager, path + '/topics/poetry/questions', 'POST',
                {'difficulty': 1, 'questions': [{'id': 'smoke-1', 'translations': translations}]}, admin, 201)
        current = request(manager, path, headers=admin)['theme']
        current['active'] = True
        request(manager, path, 'PUT', current, admin)
        assert theme in [item['id'] for item in request(api, '/api/themes?tag=arts')['themes']]
        assert theme not in [item['id'] for item in request(api, '/api/themes?tag=dev')['themes']]
        catalog = request(api, '/api/catalog', headers=player)
        assert catalog['topics'][0]['label'] == 'Poesia', catalog
        assert 'tags' not in catalog['topics'][0]
        run = request(api, '/api/runs', 'POST', {'topic': 'poetry', 'difficulty': 1}, player, 201)
        assert run['question']['prompt'] == 'Original pt-BR', run
        assert 'correctOptions' not in json.dumps(run) and 'snapshots' not in json.dumps(run).lower()
        translations['pt-BR']['prompt'] = 'Updated pt-BR'
        translations['en-US']['prompt'] = 'Updated en-US'
        request(manager, path + '/topics/poetry/questions/1/smoke-1', 'PUT',
                {'translations': translations}, admin)
        another = dict(player, **{'X-QuickQuiz-Session-ID': uuid.uuid4().hex})
        new_run = request(api, '/api/runs', 'POST', {'topic': 'poetry', 'difficulty': 1}, another, 201)
        assert new_run['question']['prompt'] == 'Updated pt-BR', new_run
        state = request(api, '/api/runs/' + run['runId'] + '/state', headers=player)
        assert state['question'] == run['question'], state
        request(manager, path + '/topics/poetry/questions/1/smoke-1', 'DELETE', headers=admin)
        correct = next(option['id'] for option in state['question']['options'] if option['text'] == 'Correct')
        answered = request(api, '/api/runs/' + run['runId'] + '/answers', 'POST',
                           {'questionId': state['question']['id'], 'optionId': correct}, player)
        assert answered['correct'] is True and answered['finished'] is True, answered
        request(api, '/api/runs/' + run['runId'] + '/result', headers=player)
        request(api, '/api/catalog', headers={'X-QuickQuiz-Theme': 'dslab'})
        request(api, '/api/catalog', headers={'X-QuickQuiz-Theme': 'dev'})
        print(json.dumps({'scenario': 'manager-to-live-api', 'passed': True,
                          'seconds': round(time.monotonic() - started, 3),
                          'checks': ['admin-auth', 'inactive-hidden', 'theme-tag-scope', 'localized-catalog',
                                     'private-topic-tags', 'atomic-publication', 'live-edit-without-restart',
                                     'old-snapshot-after-edit-delete', 'saved-option-grading', 'legacy-spa-requests']}))
    finally:
        if created:
            request(manager, path + '?recursive=true', 'DELETE', headers=admin)


if __name__ == '__main__':
    main()
