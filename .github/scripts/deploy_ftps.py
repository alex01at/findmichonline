#!/usr/bin/env python3
"""Upload the current directory to the host over FTPS.

Used by .github/workflows/deploy.yml. The host presents a self-signed
certificate, so certificate verification is intentionally disabled
(verified manually against the live host that this is required).

The host's firewall/FTPD stalls once too many data connections pile up
in a short window (verified directly: 0-0.4s delay reproducibly stalls
around 170-230 files; a 1.5s delay uploaded 300/300 files without a
single stall). Since plain FTP opens a fresh data connection per file,
this script paces every actual upload with a delay (default 1.5s,
override via FTP_UPLOAD_DELAY).

Which files need uploading is decided entirely locally, from a JSON
manifest (path -> sha256) left over from the previous successful run
(FTP_MANIFEST_PATH, restored/saved by the workflow via actions/cache).
An earlier version asked the server for every file's remote size
before deciding — one control-channel round trip per file, ~1000 of
them, which is what made every run take minutes even when only a
couple of files had actually changed. Comparing hashes locally means
unchanged files cost nothing over the network at all. The very first
run (no manifest yet) still uploads everything at the throttled pace.
"""

import ftplib
import hashlib
import json
import os
import ssl
import sys
import time

HOST = os.environ["FTP_HOST"]
USER = os.environ["FTP_USER"]
PASSWORD = os.environ["FTP_PASS"]
REMOTE_ROOT = os.environ["FTP_DIR"].strip("/")
UPLOAD_DELAY = float(os.environ.get("FTP_UPLOAD_DELAY", "1.5"))
MANIFEST_PATH = os.environ.get("FTP_MANIFEST_PATH", "").strip()

EXCLUDE_DIR_NAMES = {".git", ".github"}
EXCLUDE_FILE_NAMES = {".env"}


def remote_path(rel: str) -> str:
    return f"{REMOTE_ROOT}/{rel}" if REMOTE_ROOT else rel


def ensure_remote_dir(ftp: ftplib.FTP_TLS, path: str) -> None:
    current = ""
    for part in path.split("/"):
        if not part:
            continue
        current = f"{current}/{part}" if current else part
        try:
            ftp.mkd(current)
        except ftplib.error_perm:
            pass  # already exists


def hash_file(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def load_manifest() -> dict:
    if not MANIFEST_PATH or not os.path.isfile(MANIFEST_PATH):
        return {}
    try:
        with open(MANIFEST_PATH, encoding="utf-8") as fh:
            return json.load(fh)
    except (json.JSONDecodeError, OSError):
        return {}


def save_manifest(manifest: dict) -> None:
    if not MANIFEST_PATH:
        return
    os.makedirs(os.path.dirname(MANIFEST_PATH) or ".", exist_ok=True)
    with open(MANIFEST_PATH, "w", encoding="utf-8") as fh:
        json.dump(manifest, fh)


def main() -> int:
    previous_manifest = load_manifest()
    new_manifest = {}

    to_upload = []
    for root, dirs, files in os.walk("."):
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIR_NAMES]
        rel_root = os.path.relpath(root, ".")
        rel_root = "" if rel_root == "." else rel_root.replace(os.sep, "/")

        for name in files:
            if name in EXCLUDE_FILE_NAMES:
                continue
            rel_file = f"{rel_root}/{name}" if rel_root else name
            local_file = os.path.join(root, name)
            file_hash = hash_file(local_file)
            new_manifest[rel_file] = file_hash

            if previous_manifest.get(rel_file) != file_hash:
                to_upload.append((rel_file, local_file))

    print(f"{len(new_manifest)} files tracked, {len(to_upload)} need uploading.", flush=True)

    if to_upload:
        eta_minutes = round(len(to_upload) * UPLOAD_DELAY / 60, 1)
        print(f"Uploading at {UPLOAD_DELAY}s/file, ~{eta_minutes} min expected.", flush=True)

        context = ssl._create_unverified_context()
        ftp = ftplib.FTP_TLS(context=context, timeout=30)
        ftp.connect(HOST, 21)
        ftp.auth()
        ftp.login(USER, PASSWORD)
        ftp.prot_p()
        ftp.voidcmd("TYPE I")  # binary mode

        if REMOTE_ROOT:
            ensure_remote_dir(ftp, REMOTE_ROOT)

        ensured_dirs = set()
        for i, (rel_file, local_file) in enumerate(to_upload, start=1):
            rel_root = os.path.dirname(rel_file)
            if rel_root and rel_root not in ensured_dirs:
                ensure_remote_dir(ftp, remote_path(rel_root))
                ensured_dirs.add(rel_root)

            with open(local_file, "rb") as fh:
                ftp.storbinary(f"STOR {remote_path(rel_file)}", fh)

            # Printed for every file (not just every N) so a run never goes
            # more than one file without touching the log — the previous
            # version only printed a summary line before/after the whole
            # loop, which (combined with Python buffering stdout when it's
            # not a TTY, as under GitHub Actions) made a slow-but-healthy
            # run look identical to a hung one for the entire ~25 minutes.
            print(f"[{i}/{len(to_upload)}] {rel_file}", flush=True)
            time.sleep(UPLOAD_DELAY)

        ftp.quit()

    save_manifest(new_manifest)
    print(f"Uploaded {len(to_upload)} files, {len(new_manifest) - len(to_upload)} unchanged, to {REMOTE_ROOT or '/'} on {HOST}.", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
