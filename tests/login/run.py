#!/usr/bin/env python3
"""Ejecuta ajustes, almacenamiento, esquema y HTTP de login en un sitio desechable."""

import argparse
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'support'))
from environment import run, web_site


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, type=Path)
    parser.add_argument('--http-host', default='wpdev4.local', help='Host .local que resuelva a 127.0.0.1')
    arguments = parser.parse_args()
    project = Path(__file__).resolve().parents[2]
    with web_site(arguments.wp_path, arguments.http_host, project, project / 'tests/login/fixture.php') as site:
        for script in ('modules.php', 'lifecycle.php', 'admin-ui.php', 'login/settings.php', 'login/store.php',
                       'login/store-contract.php', 'login/migrations.php'):
            print(site.wp('eval-file', str(project / 'tests' / script)))
        print(run(['python3', '-B', str(project / 'tests/login/integration.py'),
                   '--wp-path', str(site.root), '--url', site.url]))


if __name__ == '__main__':
    main()
