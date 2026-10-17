#!/usr/bin/env bash
# Helpers for the fake Adobe server. Usage: source www.sh <www-dir> <base-url>
WWW=$1
BASE=$2
kb1() { # <last-update> [extra html]
  cat > "$WWW/kbarticles/ka-1.html" <<HTML
<html><head><meta name="last-update" content="$1"></head><body>
<h1>October 2026 isolated security patch</h1>
<a href="$BASE/patch/2-4-8-oct-2026.zip">2-4-8-oct-2026.zip</a>
<a href="$BASE/patch/2-4-7-p10-oct-2026.zip">2-4-7-p10-oct-2026.zip</a>
${2:-}
</body></html>
HTML
}
kb2() {
  cat > "$WWW/kbarticles/ka-2.html" <<HTML
<html><head><meta name="last-update" content="2026-10-16"></head><body>
<a href="$BASE/patch/2-4-8-oct-2026-v2.zip">2-4-8-oct-2026-v2.zip</a>
</body></html>
HTML
}
bulletin995() {
  cat > "$WWW/security/apsb26-995.html" <<HTML
<html><body><h1>Security update available for Adobe Commerce | APSB26-995</h1>
<table><tr><th>Bulletin ID</th><th>Date Published</th><th>Priority</th></tr><tr><td>APSB26-995</td><td>October 13, 2026</td><td>2</td></tr></table>
<table><tr><th>Product</th><th>Version</th><th>Platform</th></tr>
<tr><td>Magento Open Source</td><td>2.4.8-2026-sep and earlier</td><td>All</td></tr></table>
<table><tr><th>Product</th><th>Updated Version</th><th>Platform</th><th>Priority Rating</th><th>Installation Instructions</th></tr>
<tr><td>Magento Open Source</td><td>2.4.8-2026-oct</td><td>All</td><td>2</td><td><a href="$BASE/kbarticles/ka-1.html">KB article</a></td></tr></table>
</body></html>
HTML
}
