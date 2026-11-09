#!/usr/bin/env python3
# coding: utf-8
"""Prepare a secure unsigned external-upgrade-patch/1.3 ZIP for *manual* UME upload.

No HMAC secret is read, inferred or stored. Upgrade Manager 3.7.0+ signs the
validated ZIP automatically after the site owner uploads it in the admin UI.
API/MCP workflows must use the server's patch-draft/signature tools instead.
"""
import argparse
import hashlib
import json
import re
import uuid
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, BadZipFile


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def read_json(zf, filename):
    return json.loads(zf.read(filename).decode("utf-8"))


def clean_target(raw):
    if not isinstance(raw, str) or not raw or "\\" in raw or "\x00" in raw or raw.startswith("/") or ":" in raw:
        raise ValueError("Invalid relative path: {!r}".format(raw))
    if any(p in ("", ".", "..") for p in raw.split("/")):
        raise ValueError("Unsafe relative path: {!r}".format(raw))
    return raw


def protected_target(path, profile):
    protected = ["upgrade", ".git", ".env"]
    protected += [str(x).rstrip("/") for x in profile.get("forbidden_targets", []) if isinstance(x, str)]
    for base in protected:
        if base and (path == base or path.startswith(base + "/")):
            return True
    return False


def canonical_json(obj):
    # PHP json_decode(..., true) maps an empty object {} to an empty array [];
    # UMEUtil::canonicalJson therefore encodes BOTH as []. Match it exactly.
    def php_assoc(value):
        if isinstance(value, dict):
            if not value:
                return []
            return {key: php_assoc(item) for key, item in sorted(value.items())}
        if isinstance(value, list):
            return [php_assoc(item) for item in value]
        return value
    return json.dumps(php_assoc(obj), ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def payload_bytes(manifest):
    lines = [
        "BASE_SITE_VERSION:{}\n".format(manifest["base_site_version"]),
        "TARGET_SITE_VERSION:{}\n".format(manifest["version"]),
    ]
    for row in manifest["files"]:
        lines.append("{}:{}\n".format(row["target"], row["sha256"]))
    for path in manifest["delete"]:
        lines.append("DELETE:{}\n".format(path))
    for migration in manifest["migrations"]:
        lines.append("SQL:{}:{}\n".format(migration["id"], migration["sha256"]))
        lines.append("TABLES:{}:{}\n".format(migration["id"], sha256(canonical_json(migration["tables"]))))
    lines.append("ACTIONS:{}\n".format(sha256(canonical_json(manifest["actions"]))))
    return "".join(lines).encode("utf-8")


def build(args):
    backup = Path(args.backup)
    if not backup.is_file():
        raise ValueError("Backup ZIP does not exist: {}".format(backup))
    with ZipFile(backup, "r") as zf:
        profile = read_json(zf, "patch-profile.json")
        context = read_json(zf, "manifest.json")
        current = context.get("site_version")
        next_version = context.get("next_patch_version")
        if not isinstance(current, str) or not isinstance(next_version, str) or not current or not next_version:
            raise ValueError("Backup does not provide site_version and next_patch_version")
        if str(context.get("site_id")) != str(profile.get("site_id")):
            raise ValueError("Site ID differs between backup manifest and patch-profile")
        if str(context.get("project")) != str(profile.get("project")):
            raise ValueError("Project differs between backup manifest and patch-profile")
        allowed_domains = profile.get("domains") or []
        domain = str(profile.get("current_domain") or context.get("domain") or "").lower()
        if allowed_domains and domain not in [str(d).lower() for d in allowed_domains]:
            raise ValueError("Backup domain is not in allowed domains; choose a compatible backup")
        if context.get("public_sanitized") and not ("upgrade/BUILD_SHA256SUMS.txt" in zf.namelist()):
            raise ValueError("Public backup lacks verified Upgrade Manager source code; use a new 3.7.1+ backup")
        site_paths = set()
        if args.delete and "site-files.zip" in zf.namelist():
            # Only read entry names, not site files or sensitive content.
            from io import BytesIO
            with ZipFile(BytesIO(zf.read("site-files.zip")), "r") as site_zip:
                site_paths = set(site_zip.namelist())

    manifest = {
        "format": "external-upgrade-patch/1.3",
        "patch_id": args.patch_id or "patch-" + uuid.uuid4().hex[:16],
        "name": args.name,
        "base_site_version": current,
        "version": next_version,
        "project": str(profile["project"]),
        "domain": domain,
        "site_id": str(profile["site_id"]),
        "php_min": args.php_min,
        "files": [],
        "delete": [],
        "migrations": [],
        "actions": {"clear_cache": True} if not args.no_clear_cache and "clear_cache" in profile.get("allowed_actions", ["clear_cache"]) else {},
    }
    if not re.fullmatch(r"[A-Za-z0-9._-]{1,160}", manifest["patch_id"]):
        raise ValueError("patch_id must contain only a-z A-Z 0-9 . _ - (max 160)")
    if not manifest["name"].strip():
        raise ValueError("Patch name may not be empty")
    files = {}
    if args.changes:
        changes = Path(args.changes)
        if not changes.is_dir():
            raise ValueError("Changes directory does not exist: {}".format(changes))
        for source in sorted(changes.rglob("*")):
            if source.is_symlink():
                raise ValueError("Symlinks are not permitted: {}".format(source))
            if not source.is_file():
                continue
            target = clean_target(source.relative_to(changes).as_posix())
            if protected_target(target, profile):
                raise ValueError("Upgrade Manager / secrets / protected target: {}".format(target))
            content = source.read_bytes()
            zip_path = "payload/" + target
            files[zip_path] = content
            manifest["files"].append({"source": zip_path, "target": target, "sha256": sha256(content)})

    for item in args.delete:
        target = clean_target(item)
        if protected_target(target, profile):
            raise ValueError("Protected delete target: {}".format(target))
        if target in manifest["delete"] or any(f["target"] == target for f in manifest["files"]):
            raise ValueError("Duplicate/conflicting deleted file: {}".format(target))
        if any(p.startswith(target + "/") for p in site_paths):
            raise ValueError("Deleting entire directory is forbidden: {}".format(target))
        manifest["delete"].append(target)

    if args.migrations:
        entries = json.loads(Path(args.migrations).read_text(encoding="utf-8"))
        if not isinstance(entries, list):
            raise ValueError("--migrations must point to JSON array of {id, sql_file, tables}")
        for row in entries:
            mid = str(row["id"])
            if not re.fullmatch(r"[A-Za-z0-9._-]{1,160}", mid):
                raise ValueError("Invalid migration id: {}".format(mid))
            if any(m["id"] == mid for m in manifest["migrations"]):
                raise ValueError("Duplicate migration ID: {}".format(mid))
            tables = row["tables"]
            if not isinstance(tables, list) or not tables or len(set(tables)) != len(tables):
                raise ValueError("Each migration requires a nonempty, unique tables list")
            for table in tables:
                if not isinstance(table, str) or not re.fullmatch(r"(?:\{\{DB_PREFIX\}\})?[A-Za-z0-9_$.-]{1,128}", table):
                    raise ValueError("Invalid migration table: {!r}".format(table))
            sql_path = Path(args.migrations).parent / row["sql_file"]
            if not sql_path.is_file() or sql_path.is_symlink():
                raise ValueError("Migration SQL file is absent or unsafe: {}".format(sql_path))
            content = sql_path.read_bytes()
            zip_path = "migrations/" + mid + ".sql"
            files[zip_path] = content
            manifest["migrations"].append({"id": mid, "source": zip_path, "sha256": sha256(content), "tables": tables})

    # Do not put either field in the uploaded manifest. The manager must generate BOTH
    # real values using the server-only HMAC key after verifying ZIP contents.
    expected_hash = sha256(payload_bytes(manifest))
    help_text = (
        "PATCH FOR MANUAL UPLOAD TO UPGRADE MANAGER 3.7.0+\n"
        "Project: {project}\nSite ID: {site_id}\nTarget version: {version}\n"
        "The uploaded manifest intentionally contains neither payload_hash nor signature.\n"
        "The authorized Upgrade Manager will calculate both and sign with its private HMAC key.\n"
        "DO NOT upload this unsigned ZIP through the legacy MCP/API chunk uploader.\n"
        "For MCP/API use begin_patch_draft -> put_patch_file -> finalize_patch_draft instead.\n"
    ).format(**manifest)
    output = Path(args.out)
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.resolve() == backup.resolve() or (args.changes and (output.resolve() == Path(args.changes).resolve() or Path(args.changes).resolve() in output.resolve().parents)):
        raise ValueError("Output ZIP must not overwrite backup or be inside changes directory")
    with ZipFile(output, "w", ZIP_DEFLATED, compresslevel=9) as z:
        z.writestr("manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
        z.writestr("PATCH_UPLOAD_README.txt", help_text)
        for name, content in files.items():
            z.writestr(name, content)
    with ZipFile(output, "r") as z:
        m = read_json(z, "manifest.json")
        if "signature" in m or "payload_hash" in m:
            raise ValueError("Unsigned manual manifest must not contain half-signed fields")
        for row in m["files"] + m["migrations"]:
            if sha256(z.read(row["source"])) != row["sha256"]:
                raise ValueError("SHA-256 mismatch: {}".format(row["source"]))
        if sha256(payload_bytes(m)) != expected_hash:
            raise ValueError("Payload hash mismatch after ZIP serialization")
    print("OK: {}".format(output))
    print("site_id={} project={} version={} format=1.3".format(manifest["site_id"], manifest["project"], manifest["version"]))
    print("files={} migrations={} deletes={}".format(len(manifest["files"]), len(manifest["migrations"]), len(manifest["delete"])))
    print("server_expected_payload_hash={}".format(expected_hash))
    print("signature=will_be_added_by_upgrade_manager_after_authenticated_manual_upload")


def main(argv=None):
    p = argparse.ArgumentParser(description="Build an unsigned patch 1.3 for authenticated manual Upgrade Manager upload")
    p.add_argument("--backup", required=True, help="Original site backup ZIP (private or sanitized public)")
    p.add_argument("--changes", help="Directory with changed site files in site-root relative paths")
    p.add_argument("--out", required=True, help="Output patch ZIP")
    p.add_argument("--name", required=True, help="Human-readable name")
    p.add_argument("--patch-id", help="Unique patch ID; generated automatically by default")
    p.add_argument("--delete", action="append", default=[], help="Single file path to delete, repeat flag as needed")
    p.add_argument("--migrations", help="JSON array of {id, sql_file, tables}; SQL file relative to this JSON")
    p.add_argument("--php-min", default="5.6.0", help="Minimum PHP version (default 5.6.0)")
    p.add_argument("--no-clear-cache", action="store_true", help="Do not request cache clearance")
    args = p.parse_args(argv)
    try:
        build(args)
    except (ValueError, KeyError, OSError, json.JSONDecodeError, BadZipFile) as e:
        p.exit(2, "ERROR: {}\n".format(e))


if __name__ == "__main__":
    main()
