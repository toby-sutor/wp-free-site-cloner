#!/usr/bin/env python3
"""Write a small solid-color PNG with no external dependencies (stdlib only:
zlib + struct). Used by seed.sh to create real, valid media library images
without needing ImageMagick or Pillow in the sandbox.

Usage: gen-test-image.py <out.png> <width> <height> <r> <g> <b>
"""
import struct
import sys
import zlib


def chunk(tag, data):
    return (
        struct.pack(">I", len(data))
        + tag
        + data
        + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF)
    )


def main():
    out, w, h, r, g, b = sys.argv[1:7]
    w, h, r, g, b = int(w), int(h), int(r), int(g), int(b)

    sig = b"\x89PNG\r\n\x1a\n"
    ihdr = struct.pack(">IIBBBBB", w, h, 8, 2, 0, 0, 0)  # 8-bit RGB, no interlace

    raw = bytearray()
    for _y in range(h):
        raw.append(0)  # filter type: none
        raw.extend(bytes([r, g, b]) * w)
    idat = zlib.compress(bytes(raw), 9)

    with open(out, "wb") as f:
        f.write(sig)
        f.write(chunk(b"IHDR", ihdr))
        f.write(chunk(b"IDAT", idat))
        f.write(chunk(b"IEND", b""))


if __name__ == "__main__":
    main()
