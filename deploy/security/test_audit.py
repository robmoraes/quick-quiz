import argparse
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import subprocess
import unittest
from unittest.mock import patch

from audit import scan, select_oci


class AuditFailureTest(unittest.TestCase):
    def test_ci_candidates_reuse_published_base_without_building_it(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            trace = folder / 'commands.jsonl'
            tool = folder / 'docker'
            tool.write_text('#!/usr/bin/env python3\nimport json,os,sys\n'
                            'with open(os.environ["SECURITY_TEST_TRACE"],"a") as out: out.write(json.dumps(sys.argv[1:])+"\\n")\n')
            tool.chmod(0o755)
            subprocess.run(['sh', str(Path(__file__).with_name('build-candidates.sh')), str(folder / 'output'), '--published-base'],
                           env=os.environ | {'PATH': str(folder) + os.pathsep + os.environ['PATH'], 'SECURITY_TEST_TRACE': str(trace)}, check=True)
            commands = [json.loads(line) for line in trace.read_text().splitlines()]
            self.assertEqual(2, len(commands))
            self.assertEqual({'fpm', 'nginx'}, {cmd[cmd.index('--target') + 1] for cmd in commands})
            for cmd in commands:
                self.assertNotIn('deploy/docker/php-base/Dockerfile', cmd)
                self.assertNotIn('--build-context', cmd)

    def test_unavailable_php_feed_produces_inconclusive_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            args = argparse.Namespace(output=Path(directory), cache=None, image=[], archive=[], exceptions=Path('unused'))
            with patch('audit.urllib.request.urlopen', side_effect=OSError('release feed unavailable')):
                self.assertEqual(1, scan(args))
            summary = json.loads((Path(directory) / 'summary.json').read_text())
            self.assertEqual('inconclusive', summary['status'])
            self.assertEqual([], summary['images'])
            self.assertIn('release feed unavailable', summary['errors'])

    def test_composer_or_database_failure_never_produces_a_clean_report(self):
        upstream = {'version': '8.3.36', 'supported_versions': ['8.3']}
        for responses in ([RuntimeError('Composer unavailable')],
                          [(json.dumps({'advisories': []}), 0), RuntimeError('Database unavailable')]):
            with self.subTest(responses=responses), tempfile.TemporaryDirectory() as directory:
                args = argparse.Namespace(output=Path(directory), cache=None, image=[], archive=[], exceptions=Path('unused'))
                with patch('audit.urllib.request.urlopen', return_value=io.BytesIO(json.dumps(upstream).encode())), \
                        patch('audit.run', side_effect=responses):
                    self.assertEqual(1, scan(args))
                report = json.loads((Path(directory) / 'summary.json').read_text())
                self.assertEqual('inconclusive', report['status'])
                self.assertEqual([], report['images'])

    def test_oci_platform_selection_never_substitutes_native_architecture(self):
        with tempfile.TemporaryDirectory() as directory:
            source, selected = Path(directory) / 'source.tar', Path(directory) / 'selected.tar'
            with tarfile.open(source, 'w') as archive:
                def add(name, value):
                    data = json.dumps(value).encode()
                    member = tarfile.TarInfo(name)
                    member.size = len(data)
                    archive.addfile(member, io.BytesIO(data))
                add('index.json', {'manifests': [{'digest': 'sha256:index'}]})
                add('blobs/sha256/index', {'manifests': [{'digest': 'sha256:amd'}, {'digest': 'sha256:arm'}]})
                for architecture, name in [('amd64', 'amd'), ('arm64', 'arm')]:
                    add('blobs/sha256/' + name, {'config': {'digest': 'sha256:config-' + name}})
                    add('blobs/sha256/config-' + name, {'architecture': architecture, 'os': 'linux'})
            digest, config = select_oci(source, 'linux/arm64', selected)
            self.assertEqual('sha256:arm', digest)
            self.assertEqual('sha256:config-arm', config)
            with tarfile.open(selected) as archive:
                self.assertEqual([{'digest': 'sha256:arm'}], json.load(archive.extractfile('index.json'))['manifests'])
            with self.assertRaises(ValueError):
                select_oci(source, 'linux/ppc64le', selected)


if __name__ == '__main__':
    unittest.main()
