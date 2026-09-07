#!/usr/bin/env python3
"""Upload the current directory to the host over FTPS.

Used by .github/workflows/deploy.yml. The host presents a self-signed
certificate, so certificate verification is intentionally disabled
(verified manually against the live host that this is required).

The host's firewall/FTPD stalls once too many data connections pile up
in a short window (verified directly: 0-0.4s delay reproducibly stalls
around 170-230 files; a 1.5s delay uploaded 300/300 files without a
single stall). Since plain FTP opens a fresh data connection per file,
this script paces uploads with a delay (default 1.5s, override via
FTP_UPLOAD_DELAY), and skips files whose remote size already matches
the local size (a cheap control-channel-only check) so that day-to-day
deploys, which only change a handful of files, stay fast. Only the
very first full deploy uploads everything and takes a while (roughly
1.5s per file).
"""

import ftplib
import os
import ssl
import sys
import time

HOST = os.environ["FTP_HOST"]
USER = os.environ["FTP_USER"]
PASSWORD = os.environ["FTP_PASS"]
REMOTE_ROOT = os.environ["FTP_DIR"].strip("/")
UPLOAD_DELAY = float(os.environ.get("FTP_UPLOAD_DELAY", "1.5"))

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


def remote_size(ftp: ftplib.FTP_TLS, path: str) -> int | None:
    try:
        return ftp.size(path)
    except ftplib.error_perm:
        return None


def main() -> int:
    context = ssl._create_unverified_context()
    ftp = ftplib.FTP_TLS(context=context, timeout=30)
    ftp.connect(HOST, 21)
    ftp.auth()
    ftp.login(USER, PASSWORD)
    ftp.prot_p()
    ftp.voidcmd("TYPE I")  # binary mode; also required by some servers for SIZE

    if REMOTE_ROOT:
        ensure_remote_dir(ftp, REMOTE_ROOT)

    uploaded = 0
    skipped = 0
    for root, dirs, files in os.walk("."):
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIR_NAMES]
        rel_root = os.path.relpath(root, ".")
        rel_root = "" if rel_root == "." else rel_root.replace(os.sep, "/")

        if rel_root:
            ensure_remote_dir(ftp, remote_path(rel_root))

        for name in files:
            if name in EXCLUDE_FILE_NAMES:
                continue
            rel_file = f"{rel_root}/{name}" if rel_root else name
            local_file = os.path.join(root, name)
            r_path = remote_path(rel_file)

            local_size = os.path.getsize(local_file)
            if remote_size(ftp, r_path) == local_size:
                skipped += 1
                continue

            with open(local_file, "rb") as fh:
                ftp.storbinary(f"STOR {r_path}", fh)
            uploaded += 1
            time.sleep(UPLOAD_DELAY)

    ftp.quit()
    print(f"Uploaded {uploaded} files, skipped {skipped} unchanged, to {REMOTE_ROOT or '/'} on {HOST}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
