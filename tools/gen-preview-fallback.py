#!/usr/bin/env python3
"""
Generate img/elpx-preview-fallback.png — the bitmap returned by the
Nextcloud preview provider when the .elpx package has no screenshot.png
inside.

The output is a 512x512 PNG with a transparent background, a white
document silhouette (folded top-right corner, soft border in the same
blue as img/mimetype-exelearning.svg) and the upstream eXeLearning logo
centred at ~60% of the document width.

Usage:
    # one-time setup
    pip install Pillow

    # fetch the upstream logo and regenerate the asset
    curl -fsSL https://raw.githubusercontent.com/exelearning/exelearning/main/public/exelearning.png \\
        -o /tmp/exe-logo.png
    python3 tools/gen-preview-fallback.py /tmp/exe-logo.png

The script is intentionally Pillow-only (no ImageMagick / cairosvg
dependency) so contributors on macOS, Linux and Windows can rebuild the
asset without leaving the standard Python toolchain.
"""
from __future__ import annotations

import sys
from pathlib import Path

from PIL import Image, ImageDraw

REPO_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_LOGO = Path("/tmp/exe-logo.png")
OUT_PATH = REPO_ROOT / "img" / "elpx-preview-fallback.png"

SIZE = 512
MARGIN = 48
FOLD = 96  # size of the folded top-right corner triangle
RADIUS = 18
BORDER = "#1a6cb8"  # same blue as the existing mimetype icon
FILL = "#ffffff"
SHADOW_FILL = "#7eb6e5"  # lighter blue for the fold triangle


def main(logo_path: Path) -> None:
    canvas = Image.new("RGBA", (SIZE, SIZE), (0, 0, 0, 0))
    draw = ImageDraw.Draw(canvas)

    # Document body: rounded rectangle with the top-right corner cut.
    left, top, right, bottom = MARGIN, MARGIN, SIZE - MARGIN, SIZE - MARGIN
    draw.rounded_rectangle(
        (left, top, right, bottom),
        radius=RADIUS,
        fill=FILL,
        outline=BORDER,
        width=4,
    )

    # Bevel the corner: erase a triangle to transparent, then draw the
    # folded-paper triangle on top in the lighter accent colour and
    # outline the new edges so they line up with the document border.
    draw.polygon(
        [(right - FOLD, top), (right, top), (right, top + FOLD)],
        fill=(0, 0, 0, 0),
    )
    draw.polygon(
        [(right - FOLD, top), (right, top + FOLD), (right - FOLD, top + FOLD)],
        fill=SHADOW_FILL,
    )
    draw.line([(right - FOLD, top), (right - FOLD, top + FOLD)], fill=BORDER, width=4)
    draw.line([(right - FOLD, top + FOLD), (right, top + FOLD)], fill=BORDER, width=4)
    draw.line([(right - FOLD, top), (right, top + FOLD)], fill=BORDER, width=4)

    # Embed the eXeLearning logo at ~60% of the document interior width.
    logo = Image.open(logo_path).convert("RGBA")
    target_w = int((right - left) * 0.6)
    ratio = target_w / logo.width
    target_h = int(logo.height * ratio)
    logo = logo.resize((target_w, target_h), Image.LANCZOS)
    logo_x = (SIZE - target_w) // 2
    logo_y = (SIZE - target_h) // 2 + 12  # nudge below centre to balance the fold
    canvas.paste(logo, (logo_x, logo_y), logo)

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    canvas.save(OUT_PATH, "PNG", optimize=True)
    print(f"Wrote {OUT_PATH.relative_to(REPO_ROOT)} ({canvas.size[0]}x{canvas.size[1]})")


if __name__ == "__main__":
    logo = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_LOGO
    if not logo.exists():
        sys.exit(f"Logo not found at {logo}; pass the path as an argument or download it first.")
    main(logo)
