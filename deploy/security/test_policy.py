import copy
from datetime import date
import unittest

from policy import KINDS, PLATFORMS, evaluate


class SecurityPolicyTest(unittest.TestCase):
    def setUp(self):
        self.composer = {"advisories": {}, "lockedVersions": {"symfony/yaml": "v7.4.21"}}
        self.images = [{"artifact": kind, "platform": platform, "digest": "sha256:" + "a" * 64,
                        "report": {"SchemaVersion": 2, "Results": [{"Class": "os-pkgs", "Type": "alpine", "Packages": [{"Name": "synthetic"}]}], "Metadata": {
                            "ImageConfig": {"architecture": platform.split("/")[1]}, "OS": {"Family": "alpine"}}},
                        "runtime": {"php": "8.3.36", "upload_progress": "0", "fpm_upload_progress_off": True,
                                    "missing_extensions": []}} for kind in KINDS for platform in PLATFORMS]

    def check(self, exceptions=None):
        return evaluate(self.composer, self.images, exceptions or [], "8.3.36", date(2026, 10, 26))

    def finding(self, severity="HIGH"):
        self.images[0]["report"]["Results"][0]["Vulnerabilities"] = [{"VulnerabilityID": "CVE-test",
            "PkgName": "libtest", "InstalledVersion": "1.0", "Severity": severity}]

    def exception(self):
        return {"artifact": "php-base", "platform": "linux/amd64", "id": "CVE-test", "package": "libtest",
                "installed": "1.0", "reason": "unreachable optional code", "control": "feature disabled",
                "owner": "maintainer", "created": "2026-10-26", "expires": "2026-11-02"}

    def test_completed_clean_reports_pass(self):
        self.assertEqual("passed", self.check()["status"])

    def test_missing_duplicate_or_wrong_platform_never_passes(self):
        for mutation in (lambda: self.images.pop(), lambda: self.images.append(copy.deepcopy(self.images[0])),
                         lambda: self.images[0]["report"]["Metadata"]["ImageConfig"].update(architecture="arm64")):
            with self.subTest(mutation=mutation):
                self.setUp()
                mutation()
                self.assertEqual("inconclusive", self.check()["status"])

    def test_high_unfixed_findings_block_and_lower_findings_do_not(self):
        for severity in ("HIGH", "CRITICAL", "LOW", "MEDIUM"):
            self.finding(severity)
            self.assertEqual("blocked" if severity in ("HIGH", "CRITICAL") else "passed", self.check()["status"])

    def test_exact_scoped_exception_and_installed_version(self):
        self.finding()
        self.assertEqual("passed", self.check([self.exception()])["status"])
        exception = self.exception()
        exception["installed"] = "0.9"
        self.assertEqual("blocked", self.check([exception])["status"])

    def test_expired_future_overlong_and_incident_exceptions_fail(self):
        for change in ({"expires": "2026-10-25"}, {"created": "2026-10-27"},
                       {"expires": "2026-12-26"}, {"id": "CVE-2026-109618"}, {"owner": ""}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                evaluate(self.composer, self.images, [self.exception() | change], "8.3.36", date(2026, 10, 26))

    def test_production_composer_and_ignored_advisories_block(self):
        for field in ("advisories", "ignored-advisories"):
            self.setUp()
            self.composer[field] = {"symfony/yaml": [{"cve": "CVE-test", "severity": "low"}]}
            self.assertEqual("blocked", self.check()["status"])

    def test_missing_report_metadata_and_digest_are_inconclusive(self):
        for field in ("Metadata", "Results", "SchemaVersion"):
            self.setUp()
            del self.images[0]["report"][field]
            self.assertEqual("inconclusive", self.check()["status"])
        self.setUp()
        del self.images[0]["digest"]
        self.assertEqual("inconclusive", self.check()["status"])
        self.setUp()
        self.images[0]['report']['Results'] = []
        self.assertEqual("inconclusive", self.check()["status"])

    def test_php_patch_floor_upload_configuration_and_new_upstream_release(self):
        for change in ({"php": "8.3.33"}, {"upload_progress": "1"},
                       {"fpm_upload_progress_off": False}, {"missing_extensions": ["pdo_pgsql"]}):
            self.setUp()
            self.images[0]["runtime"].update(change)
            self.assertEqual("inconclusive", self.check()["status"])
        self.setUp()
        self.assertEqual("inconclusive", evaluate(self.composer, self.images, [], "8.3.37")["status"])

    def test_malformed_composer_or_upstream_does_not_pass(self):
        self.assertEqual("inconclusive", evaluate({}, self.images, [], "8.3.36")["status"])
        with self.assertRaises(ValueError):
            evaluate(self.composer, self.images, [], "8.3.33")


if __name__ == "__main__":
    unittest.main()
