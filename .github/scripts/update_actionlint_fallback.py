#!/usr/bin/env python3

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


INSTALL_SCRIPT_PATH = Path('.github/scripts/install-actionlint.sh')
RELEASE_API = 'https://api.github.com/repos/rhysd/actionlint/releases/latest'
RESET = '\033[0m'
RED = '\033[31m'
GREEN = '\033[32m'
BLUE = '\033[34m'
YELLOW = '\033[33m'
VERSION_PATTERN = re.compile(r'VERSION="\$\{2:-\$\{ACTIONLINT_VERSION:-\d+\.\d+\.\d+\}\}"')


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


def update_install_script(tag: str) -> bool:
    version = tag.removeprefix('v')
    new_version_line = f'VERSION="${{2:-${{ACTIONLINT_VERSION:-{version}}}}}"'

    content = INSTALL_SCRIPT_PATH.read_text(encoding='utf-8')
    updated = VERSION_PATTERN.sub(new_version_line, content, count=1)

    if updated == content:
        return False

    INSTALL_SCRIPT_PATH.write_text(updated, encoding='utf-8')
    return True


def main() -> int:
    print(colorise('[INFO] Checking latest actionlint release...', BLUE))

    try:
        tag = get_latest_release_tag()
        changed = update_install_script(tag)
    except (HTTPError, URLError, RuntimeError, OSError, json.JSONDecodeError) as error:
        print(colorise(f'[FAIL] Failed to update actionlint version: {error}', RED), file=sys.stderr)
        return 1

    if changed:
        print(colorise(f'[ OK ] Updated actionlint version to {tag}.', GREEN))
    else:
        print(colorise('[INFO] No actionlint version update required.', YELLOW))

    return 0


if __name__ == '__main__':
    raise SystemExit(main())
