#!/usr/bin/env python3
"""Build the generic web ZIP and optional DSM 7.2+ SPK from identical PHP sources."""
from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import re
import tarfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PACK = ROOT / "packaging" / "dsm7"
OUT = ROOT / "dist"
FOLDERS = ("public", "src", "agent", "var")
SCRIPTS = ("preinst", "postinst", "preuninst", "postuninst", "preupgrade", "postupgrade", "start-stop-status")
EXTENSIONS = {".php", ".js", ".css", ".png", ".htaccess"}
REQUIRED = {"public/index.php", "public/assets/style.css", "public/assets/app.js",
            "public/assets/mcconfig.png", "src/App.php", "src/ServerTypes.php",
            "src/BedrockPlayersView.php", "src/Manager.php", "src/ManagerView.php",
            "src/Console.php", "src/ConsoleView.php", "src/Uploads.php",
            "src/Plugins.php", "src/PluginsView.php",
            "agent/worker.php", "agent/JavaRuntime.php", "agent/Status.php",
            "agent/Console.php", "agent/Rcon.php", "agent/Plugins.php",
            "agent/BackupRestore.php", "agent/Recovery.php",
            "agent/Supervisor.php", "agent/native-supervisor.php",
            "var/.htaccess", "var/index.php"}


def web_files() -> dict[str, bytes]:
    result: dict[str, bytes] = {}
    for folder in FOLDERS:
        root = ROOT / folder
        if not root.is_dir() or root.is_symlink():
            raise RuntimeError(f"Missing generic application directory: {root}")
        # Private runtime state must never be included, even after a local worker ran.
        candidates = [root / ".htaccess", root / "index.php"] if folder == "var" else sorted(root.rglob("*"))
        for file in candidates:
            relative = file.relative_to(ROOT).as_posix()
            if file.is_symlink():
                raise RuntimeError(f"Symlink cannot be packaged: {relative}")
            if file.is_dir():
                continue
            if not file.is_file() or (file.name != ".htaccess" and file.suffix.lower() not in EXTENSIONS):
                raise RuntimeError(f"Unexpected file (possible secret): {relative}")
            if file.name.startswith(".") and file.name != ".htaccess":
                raise RuntimeError(f"Hidden file not permitted: {relative}")
            if file.name == "config.php":
                raise RuntimeError("Private configuration must never ship in a release.")
            result[relative] = file.read_bytes()
    if not REQUIRED.issubset(result):
        raise RuntimeError(f"Missing application files: {sorted(REQUIRED - result.keys())}")
    return result


def package_version() -> str:
    info = (PACK / "INFO").read_text(encoding="utf-8")
    match = re.search(r'^version="(\d+\.\d+\.\d+)-\d{4}"$', info, re.MULTILINE)
    if not match:
        raise RuntimeError("Invalid DSM version in INFO.")
    return match.group(1)


def add_bytes(tar: tarfile.TarFile, name: str, body: bytes, mode: int = 0o644) -> None:
    member = tarfile.TarInfo(name)
    member.uid = member.gid = 0
    member.uname = member.gname = "root"
    member.mtime = 0
    member.mode = mode
    member.size = len(body)
    tar.addfile(member, io.BytesIO(body))


def add_directory(tar: tarfile.TarFile, name: str) -> None:
    member = tarfile.TarInfo(name.rstrip("/") + "/")
    member.mode = 0o755
    member.type = tarfile.DIRTYPE
    member.mtime = 0
    tar.addfile(member)


def png_dimension(data: bytes) -> tuple[int, int]:
    if data[:8] != b"\x89PNG\r\n\x1a\n":
        raise RuntimeError("Invalid PNG artwork")
    return int.from_bytes(data[16:20], "big"), int.from_bytes(data[20:24], "big")


def validate_dsm() -> None:
    info = (PACK / "INFO").read_text(encoding="utf-8")
    if ('package="mcconfig"' not in info or 'install_dep_packages="WebStation:PHP8.2"' not in info
            or 'os_min_ver="7.2-' not in info):
        raise RuntimeError("Unsupported DSM package metadata.")
    resources = json.loads((PACK / "conf" / "resource").read_text(encoding="utf-8"))["webservice"]
    services = resources["services"]
    service = services[0] if len(services) == 1 else {}
    php = service.get("php", {})
    if (service.get("type") != "nginx_php" or service.get("root") != "mcconfig/public"
            or php.get("backend") != 11 or php.get("profile_name") != "mcconfig PHP 8.2"
            or php.get("user") != "mcconfig" or php.get("group") != "http"):
        raise RuntimeError("Web Station must use the isolated PHP 8.2 profile (backend 11) with public document root.")
    required = {"session", "zip", "zlib"}
    enabled = php.get("extensions", [])
    if not isinstance(enabled, list) or not required.issubset(set(enabled)) or len(enabled) != len(set(enabled)):
        raise RuntimeError("DSM PHP profile missing required session, zip or zlib extensions.")
    dirs = resources["pkg_dir_prepare"]
    if (len(dirs) != 1 or dirs[0]["source"] != "/var/packages/mcconfig/target/webapp"
            or dirs[0]["target"] != "mcconfig"
            or dirs[0]["user"] != "mcconfig" or dirs[0]["group"] != "http"):
        raise RuntimeError("Invalid web service directory mapping.")
    if len(resources["portals"]) != 1 or resources["portals"][0].get("alias") != "mcconfig":
        raise RuntimeError("Invalid DSM portal.")
    privilege = json.loads((PACK / "conf" / "privilege").read_text(encoding="utf-8"))
    if privilege.get("defaults", {}).get("run-as") != "package":
        raise RuntimeError("Package privileges must be unprivileged.")
    for file, size in (("PACKAGE_ICON.PNG", 64), ("PACKAGE_ICON_256.PNG", 256)):
        if png_dimension((PACK / file).read_bytes()) != (size, size):
            raise RuntimeError(f"{file} must be {size}x{size}.")
    for script in SCRIPTS:
        # Git on Windows may check out POSIX shell scripts with CRLF; package them as LF.
        data = (PACK / "scripts" / script).read_bytes().replace(b"\r\n", b"\n")
        if not data.startswith(b"#!/bin/sh\n"): 
            raise RuntimeError(f"Invalid shell script: {script}")


def zip_web(files: dict[str, bytes], out: Path) -> Path:
    path = out / f"mcmanager-web-{package_version()}.zip"
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, body in sorted(files.items()):
            entry = zipfile.ZipInfo(name, (2020, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o644 << 16
            archive.writestr(entry, body)
    with zipfile.ZipFile(path) as archive:
        if set(archive.namelist()) != set(files):
            raise RuntimeError("Generic ZIP does not match the source tree.")
        for name, body in files.items():
            if archive.read(name) != body:
                raise RuntimeError(f"Generic ZIP content mismatch: {name}")
    return path


def spk(files: dict[str, bytes], out: Path) -> Path:
    validate_dsm()
    app = {"webapp/" + name: data for name, data in files.items()}
    app["ui/mcconfig_64.png"] = (PACK / "PACKAGE_ICON.PNG").read_bytes()
    app["ui/mcconfig_256.png"] = (PACK / "PACKAGE_ICON_256.PNG").read_bytes()
    compressed = io.BytesIO()
    with gzip.GzipFile(fileobj=compressed, filename="", mode="wb", mtime=0) as compressed_file:
        with tarfile.open(fileobj=compressed_file, mode="w", format=tarfile.USTAR_FORMAT) as inner:
            for directory in ("webapp", "webapp/public", "webapp/public/assets", "webapp/src", "webapp/agent", "webapp/var", "ui"):
                add_directory(inner, directory)
            for name, body in sorted(app.items()):
                add_bytes(inner, name, body)

    outer: dict[str, tuple[bytes, int]] = {
        "INFO": ((PACK / "INFO").read_bytes(), 0o644),
        "LICENSE": ((PACK / "LICENSE").read_bytes(), 0o644),
        "package.tgz": (compressed.getvalue(), 0o644),
        "PACKAGE_ICON.PNG": ((PACK / "PACKAGE_ICON.PNG").read_bytes(), 0o644),
        "PACKAGE_ICON_256.PNG": ((PACK / "PACKAGE_ICON_256.PNG").read_bytes(), 0o644),
        "conf/privilege": ((PACK / "conf/privilege").read_bytes(), 0o644),
        "conf/resource": ((PACK / "conf/resource").read_bytes(), 0o644),
    }
    for name in SCRIPTS:
        outer["scripts/" + name] = ((PACK / "scripts" / name).read_bytes().replace(b"\r\n", b"\n"), 0o755)
    path = out / f"mcmanager-DSM7-{package_version()}.spk"
    with tarfile.open(path, "w", format=tarfile.USTAR_FORMAT) as archive:
        for name in ("INFO", "package.tgz"):
            body, mode = outer[name]
            add_bytes(archive, name, body, mode)
        for directory in ("scripts", "conf"):
            add_directory(archive, directory)
        for name, (body, mode) in sorted(outer.items()):
            if name not in ("INFO", "package.tgz"):
                add_bytes(archive, name, body, mode)
    verify_spk(path, files, outer, app)
    return path


def verify_spk(path: Path, files: dict[str, bytes], outer: dict[str, tuple[bytes, int]],
               app: dict[str, bytes]) -> None:
    with tarfile.open(path, "r:") as archive:
        items = archive.getmembers()
        if any(not (item.isdir() or item.isfile()) for item in items):
            raise RuntimeError("Unexpected link/device in SPK.")
        if {item.name for item in items if item.isfile()} != set(outer):
            raise RuntimeError("SPK outer files differ from manifest.")
        for name, (body, mode) in outer.items():
            member = archive.getmember(name)
            if member.mode & 0o777 != mode or archive.extractfile(member).read() != body:
                raise RuntimeError(f"SPK file differs: {name}")
        with tarfile.open(fileobj=io.BytesIO(archive.extractfile("package.tgz").read()), mode="r:gz") as inner:
            items = inner.getmembers()
            if any(not (item.isdir() or item.isfile()) for item in items):
                raise RuntimeError("Unexpected link/device in SPK payload.")
            if {item.name for item in items if item.isfile()} != set(app):
                raise RuntimeError("SPK app payload differs from generic web application.")
            for name, body in app.items():
                if inner.extractfile(name).read() != body:
                    raise RuntimeError(f"SPK payload mismatch: {name}")
    if set(files) != {key.removeprefix("webapp/") for key in app if key.startswith("webapp/")}:
        raise RuntimeError("Generic application and DSM application differ.")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--format", choices=("all", "web", "spk"), default="all")
    parser.add_argument("--output-dir", type=Path, default=OUT)
    args = parser.parse_args()
    files = web_files()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    results = []
    if args.format in ("all", "web"):
        results.append(zip_web(files, args.output_dir))
    if args.format in ("all", "spk"):
        results.append(spk(files, args.output_dir))
    for path in results:
        print(f"PASS {path} ({path.stat().st_size:,} bytes) SHA256={hashlib.sha256(path.read_bytes()).hexdigest()}")
    print(f"PASS {len(files)} generic application files, no private config, both archives built from one source")


if __name__ == "__main__":
    main()
