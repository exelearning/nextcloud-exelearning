#!/usr/bin/env python3
"""
Generate img/mimetype-exelearning.svg — the Files-list icon Nextcloud
shows for .elpx packages.

The icon is a 32x32 SVG: white document silhouette with a folded
top-right corner in the brand blue, and the upstream eXeLearning logo
(centered) embedded as a base64 PNG. Embedding the logo as data URI
keeps the asset self-contained and means there is no separate request
when Files renders the icon.

Usage:
    curl -fsSL https://raw.githubusercontent.com/exelearning/exelearning/main/public/exelearning.png \\
        -o /tmp/exe-logo.png
    python3 tools/gen-mimetype-icon.py /tmp/exe-logo.png
"""
from __future__ import annotations

import base64
import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_LOGO = Path("/tmp/exe-logo.png")
# Nextcloud apps ship MIME icons under img/filetypes/<alias>.svg by
# convention. The Files preview chain we rely on (ElpxPreviewProvider)
# does not need this file directly, but we keep it at the canonical
# path so admins who choose to wire mimetypealiases.json + copy the
# SVG into core/img/filetypes/ have an obvious source.
OUT_PATH = REPO_ROOT / "img" / "filetypes" / "exelearning.svg"

# 32x32 viewBox; document insets and fold size match the preview PNG.
SVG_TEMPLATE = """<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32">
  <!-- Document body: white fill, rounded corners, brand-blue border. -->
  <path fill="#ffffff" stroke="#1a6cb8" stroke-width="1.5"
        d="M5 2.75a2 2 0 0 1 2-2h12.5L27 8.25V27.25a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2z"/>
  <!-- Folded top-right corner in the lighter accent. -->
  <path fill="#7eb6e5" stroke="#1a6cb8" stroke-width="1.5"
        d="M19.5 0.75v5.5a2 2 0 0 0 2 2H27z"/>
  <!-- Upstream eXeLearning logo, centred in the document interior. -->
  <image x="8" y="11" width="16" height="16" preserveAspectRatio="xMidYMid meet"
         href="data:image/png;base64,{LOGO_B64}"/>
</svg>
"""


def main(logo_path: Path) -> None:
    logo_bytes = logo_path.read_bytes()
    encoded = base64.b64encode(logo_bytes).decode("ascii")
    svg = SVG_TEMPLATE.replace("{LOGO_B64}", encoded)
    OUT_PATH.write_text(svg, encoding="utf-8")
    print(f"Wrote {OUT_PATH.relative_to(REPO_ROOT)} ({len(svg)} bytes)")


if __name__ == "__main__":
    logo = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_LOGO
    if not logo.exists():
        sys.exit(f"Logo not found at {logo}; pass the path as an argument or download it first.")
    main(logo)
