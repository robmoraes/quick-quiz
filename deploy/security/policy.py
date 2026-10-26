"""Fail-closed policy for completed Manager audit reports (standard library only)."""

from datetime import date, datetime, timedelta, timezone
import re

PLATFORMS = ("linux/amd64", "linux/arm64")
KINDS = ("php-base", "manager-fpm", "manager-web")
INCIDENTS = {"CVE-2026-109618", "CVE-2026-109619"}


def version(value):
    if not isinstance(value, str) or not re.fullmatch(r"8\.3\.\d+", value):
        raise ValueError("Expected a supported PHP 8.3 patch version")
    return tuple(map(int, value.split(".")))


def evaluate(composer, images, exceptions, latest_php, today=None):
    today = today or datetime.now(timezone.utc).date()
    errors, findings, waived = [], [], []
    if version(latest_php) < version("8.3.36"):
        raise ValueError("Invalid upstream PHP release response")
    if not isinstance(exceptions, list):
        raise ValueError("Exceptions must be a list")
    keys = ("artifact", "platform", "id", "package", "installed")
    for item in exceptions:
        required = (*keys, "reason", "control", "owner", "created", "expires")
        if not isinstance(item, dict) or any(not isinstance(item.get(k), str) or not item[k].strip() for k in required):
            raise ValueError("Malformed security exception")
        start, end = date.fromisoformat(item["created"]), date.fromisoformat(item["expires"])
        if not start <= today <= end or not timedelta(0) <= end - start <= timedelta(days=30):
            raise ValueError("Expired, future or overlong security exception")
        if item["id"] in INCIDENTS:
            raise ValueError("Incident PHP vulnerabilities cannot be excepted")

    def finding(artifact, platform, advisory, package, installed, severity):
        entry = dict(zip(keys, (artifact, platform, advisory, package, installed)))
        entry["severity"] = severity
        if any(all(entry[k] == e[k] for k in keys) for e in exceptions):
            waived.append(entry)
        else:
            findings.append(entry)

    if not isinstance(composer, dict) or not isinstance(composer.get("advisories"), (dict, list)):
        errors.append("Missing or malformed Composer audit")
    else:
        for field in ("advisories", "ignored-advisories"):
            packages = composer.get(field, {})
            if packages == []:
                continue
            if not isinstance(packages, dict):
                errors.append("Invalid Composer advisories")
                continue
            for package, advisories in packages.items():
                for advisory in advisories.values() if isinstance(advisories, dict) else advisories:
                    finding("composer", "all", advisory.get("cve") or advisory["advisoryId"], package,
                            composer["lockedVersions"][package], advisory.get("severity") or "UNKNOWN")

    expected = {(kind, platform) for kind in KINDS for platform in PLATFORMS}
    seen = set()
    for image in images:
        identity = image["artifact"], image["platform"]
        if identity not in expected or identity in seen:
            errors.append(f"Unexpected/duplicate image coverage: {identity}")
        seen.add(identity)
        report = image["report"]
        if not re.fullmatch(r"sha256:[a-f0-9]{64}", image.get("digest", "")):
            errors.append(f"Missing immutable digest: {identity}")
        metadata = report.get("Metadata", {})
        if (report.get("SchemaVersion") != 2 or not isinstance(report.get("Results"), list)
                or metadata.get("ImageConfig", {}).get("architecture") != identity[1].split("/")[1]
                or metadata.get("OS", {}).get("Family") != "alpine"
                or metadata.get("OS", {}).get("EOSL", False)
                or not any(r.get("Class") == "os-pkgs" and r.get("Type") == "alpine" and r.get("Packages")
                           for r in report.get("Results", []))):
            errors.append(f"Incomplete/unsupported image report: {identity}")
        if identity[0] != "manager-web":
            runtime = image.get("runtime", {})
            if (version(runtime.get("php")) < max(version("8.3.36"), version(latest_php))
                    or runtime.get("upload_progress") != "0" or not runtime.get("fpm_upload_progress_off")
                    or runtime.get("missing_extensions") != []):
                errors.append(f"Unpatched PHP or invalid startup configuration: {identity}")
        for result in report.get("Results", []):
            for item in result.get("Vulnerabilities") or []:
                if item.get("Severity") in ("HIGH", "CRITICAL"):
                    finding(*identity, item["VulnerabilityID"], item["PkgName"],
                            item["InstalledVersion"], item["Severity"])
    for identity in sorted(expected - seen):
        errors.append(f"Missing image coverage: {identity}")
    return {"status": "inconclusive" if errors else "blocked" if findings else "passed",
            "errors": errors, "findings": findings, "exceptions": waived}
