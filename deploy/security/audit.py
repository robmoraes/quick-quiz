#!/usr/bin/env python3
"""Audit locked Manager dependencies and exact OCI/registry images via Docker."""

import argparse
from datetime import datetime, timezone, timedelta
import hashlib
import gzip
import io
import json
import os
from pathlib import Path
import re
import subprocess
import shutil
import tarfile
import tempfile
import urllib.request

from policy import KINDS, PLATFORMS, evaluate

ROOT = Path(__file__).resolve().parents[2]
TRIVY = "aquasec/trivy:0.76.1@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c"
COMPOSER = "composer:2.10.1@sha256:7725eb4545c438629ae8bde3ef0bb9a5038ef566126ad878442a69007242d267"
PROBE = '''echo json_encode(['php'=>PHP_VERSION,'upload_progress'=>ini_get('session.upload_progress.enabled'),
'missing_extensions'=>array_values(array_filter(['json','pdo_pgsql','pdo_sqlite','Zend OPcache'],
fn($name)=>!extension_loaded($name)))]);'''


def run(command, log, allowed=(0,)):
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    log.write_text(result.stderr)
    if result.returncode not in allowed:
        raise RuntimeError(f"Command failed ({result.returncode}); see {log.name}")
    return result.stdout, result.returncode


def docker():
    return ["docker", "run", "--rm", "--user", f"{os.getuid()}:{os.getgid()}",
            "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true"]


def defaults():
    text = (ROOT / "deploy/Makefile").read_text()
    def get(name):
        return re.search(rf"^{name} \?= (.+)$", text, re.M)[1]
    release = re.search(r"^## \[(\d+\.\d+\.\d+)\]", (ROOT / "apps/manager/CHANGELOG.md").read_text(), re.M)[1]
    digest = os.environ.get("PHP_BASE_DIGEST", get("PHP_BASE_DIGEST"))
    base = os.environ.get("PHP_BASE_REPOSITORY", get("PHP_BASE_REPOSITORY")) + ":" + os.environ.get("PHP_BASE_TAG", get("PHP_BASE_TAG"))
    if digest:
        base += "@" + digest
    return {
        "php-base": os.environ.get("PHP_BASE_IMAGE") or base,
        "manager-fpm": os.environ.get("DOCKERHUB_MANAGER_FPM_IMAGE") or get("MANAGER_FPM_REPOSITORY"),
        "manager-web": os.environ.get("DOCKERHUB_MANAGER_WEB_IMAGE") or get("MANAGER_WEB_REPOSITORY"),
    }, "v" + release


def select_oci(source, platform, destination):
    """Select one exact platform from a Buildx OCI archive, retaining its blobs."""
    with tarfile.open(source) as archive:
        def read(name):
            return json.load(archive.extractfile(name))
        def visit(descriptors):
            for descriptor in descriptors:
                digest = descriptor["digest"].split(":", 1)[1]
                document = read("blobs/sha256/" + digest)
                if "manifests" in document:
                    yield from visit(document["manifests"])
                elif "config" in document:
                    config = read("blobs/sha256/" + document["config"]["digest"].split(":", 1)[1])
                    if config.get("os", "") + "/" + config.get("architecture", "") == platform:
                        yield descriptor
        matches = list(visit(read("index.json")["manifests"]))
        if len(matches) != 1:
            raise ValueError(f"OCI archive lacks a unique {platform} image")
        manifest = matches[0]
        with tarfile.open(destination, "w") as output:
            for member in archive.getmembers():
                if member.name != "index.json":
                    output.addfile(member, archive.extractfile(member) if member.isfile() else None)
            data = json.dumps({"schemaVersion": 2, "manifests": [manifest]}).encode()
            member = tarfile.TarInfo("index.json")
            member.size = len(data)
            output.addfile(member, io.BytesIO(data))
        image = read("blobs/sha256/" + manifest["digest"].split(":", 1)[1])
        return manifest["digest"], image["config"]["digest"]


def docker_archive(source, destination, tag):
    """Docker's classic image store needs a Docker archive, not an OCI archive."""
    with tarfile.open(source) as archive:
        def blob(digest):
            return archive.extractfile("blobs/sha256/" + digest.split(":", 1)[1])
        descriptor = json.load(archive.extractfile("index.json"))["manifests"][0]
        manifest = json.load(blob(descriptor["digest"]))
        config = manifest["config"]["digest"].split(":", 1)[1] + ".json"
        with tarfile.open(destination, "w") as output:
            def add(name, data):
                member = tarfile.TarInfo(name)
                member.size = len(data)
                output.addfile(member, io.BytesIO(data))
            add(config, blob(manifest["config"]["digest"]).read())
            layers = []
            for number, layer in enumerate(manifest["layers"]):
                name = f"{number}/layer.tar"
                layers.append(name)
                stream = blob(layer["digest"])
                if layer["mediaType"].endswith("+gzip"):
                    stream = gzip.GzipFile(fileobj=stream)
                elif not layer["mediaType"].endswith(".tar"):
                    raise ValueError("Unsupported OCI layer compression")
                with tempfile.TemporaryFile() as unpacked:
                    shutil.copyfileobj(stream, unpacked)
                    member = tarfile.TarInfo(name)
                    member.size = unpacked.tell()
                    unpacked.seek(0)
                    output.addfile(member, unpacked)
            add("manifest.json", json.dumps([{"Config": config, "RepoTags": [tag], "Layers": layers}]).encode())


def scan(args):
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    cache = (args.cache or output / "cache").resolve()
    cache.mkdir(parents=True, exist_ok=True)
    summary = {"status": "inconclusive", "checked_at": datetime.now(timezone.utc).isoformat(),
               "scanner": TRIVY, "composer_tool": COMPOSER, "images": [], "errors": []}
    try:
        summary["source_revision"] = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
        summary["source_dirty"] = bool(subprocess.check_output(["git", "status", "--porcelain"], cwd=ROOT, text=True).strip())
        with urllib.request.urlopen("https://www.php.net/releases/index.php?json&version=8.3", timeout=30) as response:
            upstream = json.load(response)
        summary["latest_php"] = upstream["version"]
        if "8.3" not in upstream["supported_versions"]:
            raise ValueError("PHP 8.3 is no longer supported upstream")
        command = docker() + ["-v", f"{ROOT / 'apps/manager'}:/app:ro", "-w", "/app", COMPOSER,
                              "--no-plugins", "--no-scripts", "audit", "--locked", "--no-dev",
                              "--abandoned=report", "--format=json"]
        raw, code = run(command, output / "composer.stderr", (0, 1))
        composer = json.loads(raw)
        if code and not composer.get("advisories"):
            raise ValueError("Composer failed without completed advisory results")
        lock = json.loads((ROOT / "apps/manager/composer.lock").read_text())
        composer["lockedVersions"] = {p["name"]: p["version"] for p in lock["packages"]}
        (output / "composer.json").write_text(json.dumps(composer, indent=2) + "\n")
        summary["lock_sha256"] = hashlib.sha256((ROOT / "apps/manager/composer.lock").read_bytes()).hexdigest()
        prefix = docker() + ["-v", f"{cache}:/cache", "-v", f"{output}:/reports", TRIVY]
        run(prefix + ["image", "--cache-dir", "/cache", "--quiet", "--download-db-only"], output / "database.stderr")
        database = json.loads((cache / "db/metadata.json").read_text())
        updated = datetime.fromisoformat(database["UpdatedAt"].replace("Z", "+00:00"))
        if datetime.now(timezone.utc) - updated > timedelta(hours=72):
            raise ValueError("Vulnerability database is more than 72 hours old")
        summary["database"] = database
        refs, release = defaults()
        supplied = dict(item.split("=", 1) for item in args.image)
        refs.update(supplied)
        archives = dict(item.split("=", 1) for item in args.archive)
        if set(supplied) - set(KINDS) or set(archives) - set(KINDS):
            raise ValueError("Unknown image kind")
        for kind in KINDS:
            reference = refs[kind]
            if kind != "php-base" and kind not in supplied:
                reference += ":" + release
            index_reference = None
            if kind not in archives:
                index_digest, _ = run(["docker", "buildx", "imagetools", "inspect", reference, "--format", "{{.Manifest.Digest}}"],
                                      output / (kind + "-index.stderr"))
                index_reference = reference.split("@")[0] + "@" + index_digest.strip()
                raw, _ = run(["docker", "buildx", "imagetools", "inspect", index_reference, "--raw"],
                             output / (kind + "-resolve.stderr"))
                index = json.loads(raw)
            for platform in PLATFORMS:
                name = kind + "-" + platform.split("/")[1]
                report_path = output / (name + ".json")
                if kind in archives:
                    selected = output / (name + ".oci.tar")
                    digest, _ = select_oci(Path(archives[kind]), platform, selected)
                    # Named imports work across Docker storage backends; a bare
                    # config digest is not always addressable after docker load.
                    runtime_ref = "quickquiz-security/" + kind + ":" + platform.split("/")[1] + "-" + digest.split(":", 1)[1]
                    converted = output / (name + ".docker.tar")
                    docker_archive(selected, converted, runtime_ref)
                    selected.unlink()
                    run(["docker", "load", "-i", str(converted)], output / (name + "-load.stderr"))
                    architecture, _ = run(["docker", "image", "inspect", runtime_ref, "--format", "{{.Architecture}}"],
                                          output / (name + "-import.stderr"))
                    if architecture.strip() != platform.split("/")[1]:
                        raise ValueError("Imported runtime image architecture does not match the scanned archive")
                    scan_command = prefix + ["image", "--input", "/reports/" + converted.name]
                    reference = "oci:" + Path(archives[kind]).name
                else:
                    descriptors = [m for m in index["manifests"] if
                                   m.get("platform", {}).get("os", "") + "/" + m.get("platform", {}).get("architecture", "") == platform]
                    if len(descriptors) != 1:
                        raise ValueError(f"Missing or duplicate platform: {kind} {platform}")
                    digest = descriptors[0]["digest"]
                    runtime_ref = reference.split("@")[0] + "@" + digest
                    scan_command = prefix + ["image", "--image-src", "remote", runtime_ref]
                run(scan_command + ["--cache-dir", "/cache", "--quiet", "--skip-db-update", "--scanners", "vuln",
                                    "--platform", platform, "--format", "json", "--output", "/reports/" + report_path.name],
                    output / (name + "-scan.stderr"))
                record = {"artifact": kind, "platform": platform, "reference": reference, "digest": digest,
                          "index_reference": index_reference,
                          "runtime_image": runtime_ref,
                          "report": json.loads(report_path.read_text())}
                if kind != "manager-web":
                    cmd = docker() + ["--network", "none", "--platform", platform, "--entrypoint", "php", runtime_ref, "-r", PROBE]
                    raw, _ = run(cmd, output / (name + "-runtime.stderr"))
                    record["runtime"] = json.loads(raw)
                    raw, _ = run(docker() + ["--network", "none", "--platform", platform, "--entrypoint", "php-fpm", runtime_ref, "-i"],
                                 output / (name + "-fpm.stderr"))
                    record["runtime"]["fpm_upload_progress_off"] = bool(re.search(r"session\.upload_progress\.enabled\s*=>\s*(?:Off|0)\s*=>", raw))
                summary["images"].append(record)
                print(f"Scanned {kind} {platform} {digest}", flush=True)
                if kind in archives:
                    converted.unlink()
        summary.update(evaluate(composer, summary["images"], json.loads(args.exceptions.read_text()), summary["latest_php"]))
    except Exception as error:
        summary["status"] = "inconclusive"
        summary["errors"].append(str(error))
    (output / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    lines = ["# Manager security audit", "", f"Status: **{summary['status']}**", "",
             f"Checked: {summary['checked_at']}", "", f"Platforms completed: {len(summary['images'])}/6", ""]
    lines += ["- " + str(item) for item in summary.get("errors", []) + summary.get("findings", [])]
    lines += ["", "Resolved artifact digests are in summary.json. This does not attest to a deployed environment."]
    (output / "summary.md").write_text("\n".join(lines) + "\n")
    print("Manager audit: " + summary["status"], flush=True)
    return 0 if summary["status"] == "passed" else 1


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--cache", type=Path)
    parser.add_argument("--image", action="append", default=[], metavar="KIND=REF")
    parser.add_argument("--archive", action="append", default=[], metavar="KIND=OCI_TAR")
    parser.add_argument("--exceptions", type=Path, default=Path(__file__).with_name("exceptions.json"))
    raise SystemExit(scan(parser.parse_args()))
