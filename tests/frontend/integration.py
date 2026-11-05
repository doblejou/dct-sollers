#!/usr/bin/env python3
"""Prueba Frontend y su coexistencia con CDN en un WordPress desechable propio."""

import argparse
from pathlib import Path
import sys

from frontend_scenarios import scenarios
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'support'))
from environment import web_site


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--wp-path', required=True, type=Path)
    parser.add_argument('--http-host', default='wpdev4.local', help='Host .local que resuelva a 127.0.0.1')
    arguments = parser.parse_args()
    project = Path(__file__).resolve().parents[2]
    with web_site(arguments.wp_path, arguments.http_host, project, project / 'tests/frontend/fixture.php') as site:
        for script in ('modules.php', 'admin-ui.php', 'frontend/settings.php'):
            print(site.wp('eval-file', str(project / 'tests' / script)))
        scenarios(site.wp, site.request, site.admin, site.subscriber, site.post, site.url)


if __name__ == '__main__':
    main()
