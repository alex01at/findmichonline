#!/usr/bin/env python3
"""Upload the current directory to the host over FTPS.

Used by .github/workflows/deploy.yml. The host presents a self-signed
certificate, so certificate verification is intentionally disabled
(verified manually against the live host that this is required).
"""

import ftplib
import os
import ssl
import sys

HOST = os.environ["FTP_HOST"]
USER = os.environ["FTP_USER"]
PASSWORD = os.environ["FTP_PASS"]
REMOTE_ROOT = os.environ["FTP_DIR"].strip("/")

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


def main() -> int:
    context = ssl._create_unverified_context()
    ftp = ftplib.FTP_TLS(context=context, timeout=30)
    ftp.connect(HOST, 21)
    ftp.auth()
    ftp.login(USER, PASSWORD)
    ftp.prot_p()

    if REMOTE_ROOT:
        ensure_remote_dir(ftp, REMOTE_ROOT)

    uploaded = 0
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
            with open(local_file, "rb") as fh:
                ftp.storbinary(f"STOR {remote_path(rel_file)}", fh)
            uploaded += 1

    ftp.quit()
    print(f"Uploaded {uploaded} files to {REMOTE_ROOT or '/'} on {HOST}.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
