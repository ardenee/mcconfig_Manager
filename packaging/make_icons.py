#!/usr/bin/env python3
"""Resize the supplied unaltered square photo crop for mcconfig Manager icons.

No image generation, retouching or upscaling from an existing tiny icon.
Requires Pillow only for packaging development, not for the deployed app.
"""
from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
MASTER = ROOT / "packaging" / "mcconfig-original-photo-crop.png"

def build() -> None:
    with Image.open(MASTER) as image:
        if image.size != (383, 383):
            raise RuntimeError(f"Unexpected photograph crop {image.size}")
        original = image.convert("RGBA")
        for name, size in (
            ("public/assets/mcconfig.png", 256),
            ("packaging/dsm7/PACKAGE_ICON.PNG", 64),
            ("packaging/dsm7/PACKAGE_ICON_256.PNG", 256),
        ):
            path = ROOT / name
            resized = original.resize((size, size), Image.Resampling.LANCZOS)
            resized.save(path, format="PNG", optimize=True)
            with Image.open(path) as check:
                if check.size != (size, size) or check.mode != "RGBA":
                    raise RuntimeError(f"Failed PNG verification: {name}")
            print(f"PASS {name}: {size}x{size}")

if __name__ == "__main__":
    build()
