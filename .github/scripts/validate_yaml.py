#!/usr/bin/env python3

import sys
from pathlib import Path
from typing import List

import yaml


RESET = '\033[0m'
RED = '\033[31m'
GREEN = '\033[32m'
BLUE = '\033[34m'
YELLOW = '\033[33m'
ALLOWED_ROOTS = (Path('.github/workflows'), *sorted(Path('src').glob('*/.github/workflows')))
ALLOWED_FILES = (Path('.github/carson.yml'), Path('.github/dependabot.yml'), *sorted(Path('src').glob('*/.github/carson.yml')))


def colorise(text: str, colour: str) -> str:
    return f'{colour}{text}{RESET}'


def is_allowed_yaml_file(path: Path) -> bool:
    return (
        path.is_file()
        and path.suffix in {'.yml', '.yaml'}
        and (
            any(root in path.parents or path == root for root in ALLOWED_ROOTS)
            or path in ALLOWED_FILES
        )
    )


def iter_yaml_files() -> List[Path]:
    return sorted(
        path for path in Path('.').rglob('*')
        if is_allowed_yaml_file(path)
    )


def print_header(title: str) -> None:
    line = '=' * 72
    print(colorise(line, BLUE))
    print(colorise(title, BLUE))
    print(colorise(line, BLUE))


def main() -> int:
    yaml_files = iter_yaml_files()

    if not yaml_files:
        print(colorise('[INFO] No YAML files found in configured paths.', YELLOW))
        return 0

    errors: List[str] = []
    print_header('YAML Validation')
    print(f'Checking {len(yaml_files)} YAML file(s) under configured paths...')

    for path in yaml_files:
        try:
            with path.open('r', encoding='utf-8') as handle:
                list(yaml.safe_load_all(handle))
            print(colorise(f'[ OK ] {path}', GREEN))
        except yaml.YAMLError as error:
            errors.append(f'{path}: {error}')

    if errors:
        print('', file=sys.stderr)
        print(colorise('[FAIL] YAML validation failed.', RED), file=sys.stderr)
        for error in errors:
            print(colorise(f'  - {error}', RED), file=sys.stderr)
        return 1

    print(colorise(f'[ OK ] Validated {len(yaml_files)} YAML file(s).', GREEN))
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
