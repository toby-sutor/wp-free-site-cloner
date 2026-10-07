#!/usr/bin/env python3
"""Drive a real All-in-One WP Migration export over admin-ajax.php.

Usage: samples_ai1wm.py <site-url> <secret-key> [excluded,paths]

The optional third argument is a comma-separated list of paths relative to
wp-content that the export skips (the plugin's own "exclude files" option).

Replays the plugin's own AJAX state machine: POST action=ai1wm_export with
the secret key and ai1wm_manual_export=1, so each step answers with the
updated params as JSON instead of self-requesting over HTTP. The params are
posted back until the chain ends (a non-JSON / empty response or a response
without "priority"). Prints the final archive file name on stdout.
"""
import json
import sys
import time
import urllib.parse
import urllib.request


def flatten(value, prefix=""):
    """PHP-style form encoding of nested dicts/lists (a[b][c]=v)."""
    out = []
    if isinstance(value, dict):
        items = value.items()
    elif isinstance(value, list):
        items = enumerate(value)
    else:
        return [(prefix, "" if value is None else
                 ("1" if value is True else ("" if value is False else str(value))))]
    for k, v in items:
        key = f"{prefix}[{k}]" if prefix else str(k)
        out.extend(flatten(v, key))
    return out


def post(url, params):
    data = urllib.parse.urlencode(flatten(params)).encode()
    req = urllib.request.Request(url, data=data, method="POST")
    with urllib.request.urlopen(req, timeout=300) as resp:
        return resp.read().decode("utf-8", "replace")


def main():
    site, secret = sys.argv[1], sys.argv[2]
    url = site.rstrip("/") + "/wp-admin/admin-ajax.php?action=ai1wm_export&ai1wm_import=1"
    params = {"secret_key": secret, "ai1wm_manual_export": 1, "priority": 5}
    if len(sys.argv) > 3 and sys.argv[3]:
        params["options"] = {"exclude_files": 1}
        params["excluded_files"] = sys.argv[3]
    archive = None
    for step in range(1, 2001):
        body = post(url, params)
        try:
            resp = json.loads(body)
        except ValueError:
            resp = None
        if not isinstance(resp, dict):
            print(f"[ai1wm] step {step}: chain ended (non-JSON response: {body[:200]!r})",
                  file=sys.stderr)
            break
        if "errors" in resp:
            print(f"[ai1wm] error: {resp['errors']}", file=sys.stderr)
            return 1
        archive = resp.get("archive", archive)
        print(f"[ai1wm] step {step}: priority={resp.get('priority')} "
              f"completed={resp.get('completed')}", file=sys.stderr)
        params = resp
        params["secret_key"] = secret
        params["ai1wm_manual_export"] = 1
        time.sleep(0.1)
    else:
        print("[ai1wm] too many steps", file=sys.stderr)
        return 1
    if not archive:
        print("[ai1wm] no archive name seen", file=sys.stderr)
        return 1
    print(archive)
    return 0


if __name__ == "__main__":
    sys.exit(main())
