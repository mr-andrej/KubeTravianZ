#!/usr/bin/env python3
"""Headless driver for the TravianZ /install wizard.

Replays the browser flow with plain HTTP so a fresh stack can be installed
without manual clicks (local docker compose, CI, later: K8s Job).

Steps:
  1. GET  /install/?s=1          -> parse the rendered config form (defaults
                                    come from .env via config.tpl)
  2. POST /install/process.php   -> subconst  (writes GameEngine/config.php)
  3. POST /install/process.php   -> substruc  (creates DB structure)
  4. POST /install/process.php   -> subwdata  (populates world data)
  5. GET  /install/ajax_croppers.php         (SSE stream until pct>=100)
  6. POST /install/include/accounts.php      (Multihunter/Support/Admin)
  7. GET  /install/?s=5          -> end.tpl: touches var/installed and
                                    renames install/ to installed_<ts>

Usage: python scripts/install_headless.py [--base http://localhost:8080]
"""

import argparse
import html
import http.cookiejar
import re
import sys
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def parse_form(page: str) -> dict:
    """Extract name->value from all inputs/selects, honoring defaults."""
    fields = {}
    for tag in re.findall(r"<input\b[^>]*>", page, re.IGNORECASE):
        attrs = dict(
            (m.group(1).lower(), html.unescape(m.group(2)))
            for m in re.finditer(r"(\w+)=[\"']([^\"']*)[\"']", tag)
        )
        name = attrs.get("name")
        if not name or re.search(r"\bdisabled\b", tag, re.IGNORECASE):
            continue
        t = attrs.get("type", "text").lower()
        if t in ("submit", "button"):
            continue
        if t in ("checkbox", "radio") and "checked" not in tag.lower():
            continue
        fields[name] = attrs.get("value", "")
    for m in re.finditer(
        r"<select\b[^>]*name=[\"']([^\"']+)[\"'][^>]*>(.*?)</select>",
        page,
        re.IGNORECASE | re.DOTALL,
    ):
        name, body = m.group(1), m.group(2)
        options = re.findall(r"<option\b[^>]*>", body, re.IGNORECASE)
        values = []
        selected = None
        for opt in options:
            vm = re.search(r"value=[\"']([^\"']*)[\"']", opt)
            val = html.unescape(vm.group(1)) if vm else ""
            values.append(val)
            if re.search(r"\bselected\b", opt, re.IGNORECASE):
                selected = val
        if values:
            fields[name] = selected if selected is not None else values[0]
    return fields


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://localhost:8080")
    ap.add_argument("--admin-name", default="admin")
    ap.add_argument("--admin-email", default="admin@example.com")
    ap.add_argument("--admin-password", default="adminpass")
    ap.add_argument("--mh-password", default="multihunterpass")
    ap.add_argument("--support-password", default="supportpass")
    args = ap.parse_args()

    base = args.base.rstrip("/") + "/install/"
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(cj), NoRedirect()
    )

    def get(url):
        return opener.open(url, timeout=600)

    def post(url, data):
        body = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(url, data=body, method="POST")
        try:
            resp = opener.open(req, timeout=3600)
            return resp.status, resp.headers.get("Location", "")
        except urllib.error.HTTPError as e:
            if e.code in (301, 302, 303):
                return e.code, e.headers.get("Location", "")
            raise

    # 1-2. config form
    print("[1/5] Fetching config form...", flush=True)
    page = get(base + "?s=1&t=8").read().decode("utf-8", "replace")
    fields = parse_form(page)
    assert "subconst" in fields, "subconst hidden field not found on s=1 page"
    print(f"      {len(fields)} fields, DB host={fields.get('sserver')}, "
          f"prefix={fields.get('prefix')}", flush=True)
    code, loc = post(base + "process.php", fields)
    assert "s=2" in loc, f"config step failed: HTTP {code} Location={loc}"
    print("[2/5] config.php written -> s=2", flush=True)

    # 3. DB structure
    print("[3/5] Creating DB structure (can take a while)...", flush=True)
    code, loc = post(base + "process.php", {"substruc": "1"})
    assert "s=3" in loc and "err" not in loc, f"structure step failed: HTTP {code} Location={loc}"
    print("      DB structure created -> s=3", flush=True)

    # 4. world data + croppers SSE
    print("[4/5] Populating world data...", flush=True)
    code, loc = post(base + "process.php", {"subwdata": "1"})
    assert "startCroppers=1" in loc, f"wdata step failed: HTTP {code} Location={loc}"
    print("      World data done, building croppers (SSE)...", flush=True)
    resp = get(base + "ajax_croppers.php")
    pct = -1
    for raw in resp:
        line = raw.decode("utf-8", "replace").strip()
        if not line.startswith("data:"):
            continue
        import json
        d = json.loads(line[5:].strip())
        if d.get("msg"):
            print(f"      {d['msg']}", flush=True)
        if d.get("error"):
            print("      CROPPERS FAILED", flush=True)
            return 1
        new_pct = int(d.get("pct", 0))
        if new_pct != pct and new_pct % 10 == 0:
            print(f"      {new_pct}% ({d.get('done')}/{d.get('total')})", flush=True)
            pct = new_pct
        if new_pct >= 100:
            break

    # 5. accounts
    print("[5/5] Creating accounts (Multihunter/Support/Admin)...", flush=True)
    code, loc = post(base + "include/accounts.php", {
        "mhpw": args.mh_password,
        "spw": args.support_password,
        "aname": args.admin_name,
        "aemail": args.admin_email,
        "apass": args.admin_password,
        "atribe": "1",
        "admin_rank": "false",
        "admin_support_msgs": "true",
        "admin_raidable": "true",
    })
    assert "s=5" in loc, f"accounts step failed: HTTP {code} Location={loc}"
    # trigger end step: touches var/installed, renames install/ -> installed_<ts>
    get(base + "?s=5").read()
    print("      Install finalized (var/installed created, install/ renamed)", flush=True)
    print("DONE - game should be live at " + args.base, flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
