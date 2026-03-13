#!/usr/bin/env python3

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


WORKFLOW_PATH = Path('.github/workflows/yaml-validation.yml')
RELEASE_API = 'https://api.github.com/repos/rhysd/actionlint/releases/latest'
RESET = '\033[0m'
RED = '\033[31m'
GREEN = '\033[32m'
BLUE = '\033[34m'
YELLOW = '\033[33m'
URL_PATTERN = re.compile(
    r'https://github\.com/rhysd/actionlint/releases/download/v\d+\.\d+\.\d+/actionlint_\d+\.\d+\.\d+_linux_amd64\.tar\.gz'
)
CHECKSUM_PATTERN = re.compile(r"echo '[0-9a-f]{64}  actionlint\.tar\.gz' \| sha256sum --check --strict")


def colorise(text: str, colour: str) -> str:
    return f'{colour}{text}{RESET}'


def http_get(url: str) -> str:
    request = Request(
        url,
        headers={
            'Accept': 'application/vnd.github+json',
            'User-Agent': 'mcp-sdk-actionlint-updater',
        },
    )
    with urlopen(request) as response:  # noqa: S310 - trusted GitHub URL
        return response.read().decode('utf-8')


def get_latest_release_tag() -> str:
    payload = json.loads(http_get(RELEASE_API))
    tag_name = payload.get('tag_name')
    if not isinstance(tag_name, str) or not tag_name.startswith('v'):
        raise RuntimeError('Unable to resolve latest actionlint release tag.')

    return tag_name


def get_checksum(tag: str, asset_filename: str) -> str:
    checksum_url = (
        f'https://github.com/rhysd/actionlint/releases/download/{tag}/'
        f'actionlint_{tag.removeprefix("v")}_checksums.txt'
    )
    checksums_text = http_get(checksum_url)

    for line in checksums_text.splitlines():
        columns = line.split()
        if len(columns) == 2 and columns[1] == asset_filename:
            return columns[0]

    raise RuntimeError(f'Checksum for {asset_filename} not found in {checksum_url}.')


def update_workflow_file(tag: str, checksum: str) -> bool:
    version = tag.removeprefix('v')
    asset_filename = f'actionlint_{version}_linux_amd64.tar.gz'
    download_url = f'https://github.com/rhysd/actionlint/releases/download/{tag}/{asset_filename}'
    checksum_line = f"echo '{checksum}  actionlint.tar.gz' | sha256sum --check --strict"

    content = WORKFLOW_PATH.read_text(encoding='utf-8')
    updated = URL_PATTERN.sub(download_url, content, count=1)
    updated = CHECKSUM_PATTERN.sub(checksum_line, updated, count=1)

    if updated == content:
        return False

    WORKFLOW_PATH.write_text(updated, encoding='utf-8')
    return True


def main() -> int:
    print(colorise('[INFO] Checking latest actionlint release...', BLUE))

    try:
        tag = get_latest_release_tag()
        version = tag.removeprefix('v')
        asset_filename = f'actionlint_{version}_linux_amd64.tar.gz'
        checksum = get_checksum(tag, asset_filename)
        changed = update_workflow_file(tag, checksum)
    except (HTTPError, URLError, RuntimeError, OSError, json.JSONDecodeError) as error:
        print(colorise(f'[FAIL] Failed to update actionlint fallback pins: {error}', RED), file=sys.stderr)
        return 1

    if changed:
        print(colorise(f'[ OK ] Updated fallback actionlint pin to {tag}.', GREEN))
    else:
        print(colorise('[INFO] No fallback actionlint update required.', YELLOW))

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
