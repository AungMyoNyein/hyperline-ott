#!/usr/bin/env python3
"""Build a committed viewer candidate and record image-only rollback references.

Never starts/stops containers, changes live tags, or invokes compose up.
Only tracked apps/web files enter the secret-free Docker build context.
"""
import datetime
import io
import json
import pathlib
import subprocess
import tarfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
SERVICES = ("web", "cms", "api", "worker", "scheduler", "gateway", "postgres", "redis")


def run(*args):
    return subprocess.check_output(args, cwd=ROOT, text=True).strip()


def inventory():
    result = {}
    for service in SERVICES:
        # Select only public runtime identifiers; never dump container Env.
        values = run("docker", "inspect", "--format", "{{.Id}} {{.Image}} {{.State.StartedAt}}", f"hyperline-{service}-1").split()
        result[service] = dict(zip(("container_id", "image_id", "started_at"), values))
    return result


def main():
    if run("git", "diff", "HEAD", "--", "apps/web") or run("git", "ls-files", "--others", "--exclude-standard", "apps/web"):
        raise SystemExit("Commit viewer changes before preparing a candidate.")
    commit = run("git", "rev-parse", "HEAD")
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    release = f"uat-compact-{stamp}-{commit[:12]}"
    target = ROOT / "deploy/releases" / release
    target.mkdir(mode=0o700)
    before = inventory()
    (target / "runtime-before.json").write_text(json.dumps(before, indent=2) + "\n")
    archive = subprocess.check_output(["git", "archive", commit, "apps/web"], cwd=ROOT)
    with tarfile.open(fileobj=io.BytesIO(archive)) as source:
        source.extractall(target / "source", filter="data")
    tag = f"hyperline-web:{release}"
    rollback_tag = f"hyperline-web:rollback-{release}"
    for reference in (tag, rollback_tag):
        if subprocess.run(["docker", "image", "inspect", reference], capture_output=True).returncode == 0:
            raise SystemExit("Release tag already exists; refuse overwrite.")
    subprocess.run(["docker", "image", "tag", before["web"]["image_id"], rollback_tag], check=True)
    api_url = "https://api.zentunnel.net/api/v1"
    command = ["docker", "build", "--label", f"org.opencontainers.image.revision={commit}", "--build-arg", f"NEXT_PUBLIC_API_URL={api_url}", "--tag", tag, str(target / "source/apps/web")]
    with (target / "build.log").open("w") as output:
        subprocess.run(command, cwd=ROOT, stdout=output, stderr=subprocess.STDOUT, check=True)
    image = run("docker", "image", "inspect", "--format", "{{.Id}}", tag)
    after = inventory()
    if before != after:
        raise SystemExit("Runtime identifiers changed during the build; investigate before review.")
    manifest = {
        "release": release, "source_commit": commit,
        "viewer_tree": run("git", "rev-parse", f"{commit}:apps/web"),
        "candidate": {"tag": tag, "image_id": image, "build_api_url": api_url},
        "rollback": {"web_image_id": before["web"]["image_id"], "tag": rollback_tag, "strategy": "Viewer image only; retain existing runtime configuration and all data."},
        "runtime_before": before, "runtime_unchanged_after_build": before == after,
        "deployed": False, "smoke_test": "pending",
    }
    for name, value in {
        "manifest.json": manifest,
        "candidate.compose.json": {"services": {"web": {"image": image, "pull_policy": "never"}}},
        "rollback.compose.json": {"services": {"web": {"image": before["web"]["image_id"], "pull_policy": "never"}}},
    }.items():
        (target / name).write_text(json.dumps(value, indent=2) + "\n")
    print(json.dumps({"directory": str(target), "manifest": manifest}, indent=2))


if __name__ == "__main__":
    main()
