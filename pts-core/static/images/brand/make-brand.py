#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-3.0-or-later
"""Build the Benchy brand files (direction E, "workbench", teal).

Two steps, run by hand; the outputs are committed, nothing runs at build time.

  make-brand.py svg --fonts DIR [--out DIR]
      Writes the SVG masters into <out>/svg/. Needs the Barlow Semi Condensed
      SemiBold and Regular TTFs in DIR (SIL OFL 1.1, from github.com/google/fonts,
      ofl/barlowsemicondensed). The wordmark is shaped with HarfBuzz and written
      as outlined <path>s, so no SVG depends on an installed font.
      Python packages: fonttools, uharfbuzz.

  make-brand.py raster [--out DIR]
      Renders <out>/svg/*.svg to <out>/png/ and <out>/ico/ with rsvg-convert,
      and packs the ICOs with Pillow. Python package: pillow.

<out> defaults to the folder above this script.
"""
import argparse
import os
import shutil
import struct
import subprocess
import sys

# ---------------------------------------------------------------- colors
TEAL = "#0b7480"          # the mark, tiles, light UI (white on it: 5.5:1)
TEAL_ON_DARK = "#138896"  # the bare mark on dark UI surfaces (on #191c25: 4.1:1)
INK, MUTED = "#1f2430", "#626c7a"          # Phoromatic --ph-text / --ph-text-muted, light
INK_D, MUTED_D = "#e6e8ec", "#99a1b1"      # the same tokens, dark
WHITE = "#ffffff"
ONE_INK = "#000000"

# ---------------------------------------------------------------- geometry
# The mark on its 32 grid (Sabine, benchy-branding.md section 1). Visual bounds x 2..30, y 5..30.
MARK32 = [  # x, y, w, h, rx
    (2, 16, 28, 4, 1),     # bench top
    (5, 20, 4, 10, 0.5),   # left leg
    (23, 20, 4, 10, 0.5),  # right leg
    (6, 8, 10, 8, 1),      # seated part
    (19, 5, 7, 7, 1),      # lifted part (4-unit gap above the bench)
]
# The bare 16 px cut: whole pixels, no rounding. Bounds x 1..15, y 2..15.
MARK16 = [(1, 8, 14, 2, 0), (2, 10, 2, 5, 0), (12, 10, 2, 5, 0), (3, 4, 5, 4, 0), (10, 2, 3, 3, 0)]
# Tile cuts: white mark on a teal rounded square, for every surface we don't control
# (tab strips, taskbars, desktops, home screens). Inner area keeps a 2 px (16) / 4 px (32) margin.
TILE16 = [(2, 8, 12, 2, 0), (3, 10, 2, 4, 0), (11, 10, 2, 4, 0), (4, 4, 4, 4, 0), (10, 2, 3, 3, 0)]
TILE16_RX = 3
TILE32 = [(4, 15, 24, 3, 0.75), (6, 18, 3, 10, 0.5), (23, 18, 3, 10, 0.5), (7, 8, 8, 7, 0.75), (18, 6, 6, 6, 0.75)]
TILE32_RX = 7
TILE24 = [(3, 11, 18, 2, 0), (5, 13, 2, 8, 0), (17, 13, 2, 8, 0), (5, 6, 6, 5, 0), (14, 4, 4, 4, 0)]
TILE24_RX = 5

# Wordmark: lowercase "benchy" in Barlow Semi Condensed SemiBold; secondary words in Regular.
FONT_BOLD = "BarlowSemiCondensed-SemiBold.ttf"
FONT_REG = "BarlowSemiCondensed-Regular.ttf"


def rects(spec, fill, dx=0.0, dy=0.0, s=1.0, indent="  "):
    out = []
    for x, y, w, h, rx in spec:
        r = f' rx="{fmt(rx * s)}"' if rx else ""
        out.append(f'{indent}<rect x="{fmt(dx + x * s)}" y="{fmt(dy + y * s)}" width="{fmt(w * s)}" '
                   f'height="{fmt(h * s)}"{r} fill="{fill}"/>')
    return "\n".join(out)


def fmt(v):
    v = round(v, 3)
    return str(int(v)) if v == int(v) else f"{v:g}"


def header(w, h, label, extra=""):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {fmt(w)} {fmt(h)}" '
            f'width="{fmt(w)}" height="{fmt(h)}" role="img" aria-label="{label}"{extra}>\n'
            '  <!-- SPDX-License-Identifier: GPL-3.0-or-later -->\n')


# ---------------------------------------------------------------- wordmark outlining
class Outliner:
    def __init__(self, fontdir):
        import uharfbuzz as hb
        from fontTools.ttLib import TTFont
        self.hb = hb
        self.fonts = {}
        for key, name in (("bold", FONT_BOLD), ("reg", FONT_REG)):
            path = os.path.join(fontdir, name)
            if not os.path.exists(path):
                sys.exit(f"missing font: {path}")
            blob = hb.Blob.from_file_path(path)
            face = hb.Face(blob)
            font = hb.Font(face)
            tt = TTFont(path)
            self.fonts[key] = (font, tt, tt.getGlyphSet(), tt["head"].unitsPerEm)

    def run(self, text, key, size, x, baseline):
        """Return (svg path data, advance in px) for text set at size px, pen at (x, baseline)."""
        from fontTools.pens.svgPathPen import SVGPathPen
        from fontTools.pens.transformPen import TransformPen
        font, tt, gs, upem = self.fonts[key]
        buf = self.hb.Buffer()
        buf.add_str(text)
        buf.guess_segment_properties()
        self.hb.shape(font, buf, {"kern": True, "liga": True})
        k = size / upem
        order = tt.getGlyphOrder()
        pen_x = 0
        parts = []
        for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
            name = order[info.codepoint]
            sp = SVGPathPen(gs, ntos=lambda v: fmt(v))
            # font units, y up -> px, y down
            tp = TransformPen(sp, (k, 0, 0, -k, x + (pen_x + pos.x_offset) * k, baseline - pos.y_offset * k))
            gs[name].draw(tp)
            parts.append(sp.getCommands())
            pen_x += pos.x_advance
        return " ".join(p for p in parts if p), pen_x * k

    def ink_bounds(self, text, key, size):
        """Ink x-extent of the first glyph's left side bearing, for optical alignment."""
        font, tt, gs, upem = self.fonts[key]
        from fontTools.pens.boundsPen import BoundsPen
        order = tt.getGlyphOrder()
        first = tt.getBestCmap()[ord(text[0])]
        bp = BoundsPen(gs)
        gs[first].draw(bp)
        return bp.bounds[0] * size / upem


# ---------------------------------------------------------------- SVG masters
def mark_svg(spec, size, fill, label):
    return header(size, size, label) + rects(spec, fill) + "\n</svg>\n"


def tile_svg(spec, size, rx, label, tile=TEAL, ink=WHITE, scale=1.0):
    s = size * scale
    return (header(s, s, label) + f'  <rect width="{fmt(s)}" height="{fmt(s)}" rx="{fmt(rx * scale)}" fill="{tile}"/>\n'
            + rects(spec, ink, s=scale) + "\n</svg>\n")


def touch_svg():
    # Full-bleed square: iOS and Android round the corners themselves and fill transparency with black.
    S, s = 180, 4.0  # the 32-grid mark at 4x is 112 wide, 100 tall: 62 % of the square
    dx = (S - 28 * s) / 2 - 2 * s
    dy = (S - 25 * s) / 2 - 5 * s
    return header(S, S, "Benchy") + f'  <rect width="{S}" height="{S}" fill="{TEAL}"/>\n' + \
        rects(MARK32, WHITE, dx, dy, s) + "\n</svg>\n"


def lockup(o, words, mark_fill, label, mark_px=32, size=27, gap=8, pad=0):
    """Mark + wordmark on one line. words: list of (text, fontkey, fill, size, dx before)."""
    s = mark_px / 32
    # crop the mark's empty grid: visual x 2..30, y 5..30
    mx, my = -2 * s + pad, -5 * s + pad
    body = [rects(MARK32, mark_fill, mx, my, s)]
    mark_right = 28 * s + pad
    baseline = round(my + 30 * s - 1.5 * s, 2)  # the wordmark sits on the legs' feet, minus overshoot room
    x = mark_right + gap - o.ink_bounds(words[0][0], words[0][1], words[0][3])
    for text, key, fill, wsize, dx in words:
        x += dx
        d, adv = o.run(text, key, wsize, x, baseline)
        body.append(f'  <path fill="{fill}" d="{d}"/>')
        x += adv
    width = x + pad
    height = 25 * s + 2 * pad + 0.2 * size  # room for the y descender below the feet
    return header(round(width, 2), round(height, 2), label) + "\n".join(body) + "\n</svg>\n"


def build_svg(out, fontdir):
    o = Outliner(fontdir)
    d = os.path.join(out, "svg")
    os.makedirs(d, exist_ok=True)
    files = {
        "benchy-mark.svg": mark_svg(MARK32, 32, TEAL, "Benchy"),
        "benchy-mark-16.svg": mark_svg(MARK16, 16, TEAL, "Benchy"),
        "benchy-tile.svg": tile_svg(TILE32, 32, TILE32_RX, "Benchy", scale=8),  # 256 master, exact 8x of the 32 cut
        "benchy-tile-32.svg": tile_svg(TILE32, 32, TILE32_RX, "Benchy"),
        "benchy-tile-24.svg": tile_svg(TILE24, 24, TILE24_RX, "Benchy"),
        "benchy-tile-16.svg": tile_svg(TILE16, 16, TILE16_RX, "Benchy"),
        "apple-touch-icon.svg": touch_svg(),
        # graphs: one color, survives invert(.895) hue-rotate(180deg) in dark mode
        "benchy-graph.svg": lockup(o, [("benchy", "bold", TEAL, 27, 0)], TEAL, "Benchy"),
        # print / PDF: one ink, any single color
        "benchy-oneink.svg": lockup(o, [("benchy", "bold", ONE_INK, 27, 0)], ONE_INK, "Benchy"),
        "benchy-phoromatic-light.svg": lockup(o, [("benchy", "bold", INK, 27, 0), ("Phoromatic", "reg", MUTED, 26, 7)],
                                              TEAL, "Benchy Phoromatic"),
        "benchy-phoromatic-dark.svg": lockup(o, [("benchy", "bold", INK_D, 27, 0), ("Phoromatic", "reg", MUTED_D, 26, 7)],
                                             TEAL_ON_DARK, "Benchy Phoromatic"),
        "benchymatic-light.svg": lockup(o, [("benchy", "bold", INK, 27, 0), ("matic", "reg", INK, 27, 0.5)],
                                        TEAL, "benchymatic"),
        "benchymatic-dark.svg": lockup(o, [("benchy", "bold", INK_D, 27, 0), ("matic", "reg", INK_D, 27, 0.5)],
                                       TEAL_ON_DARK, "benchymatic"),
    }
    # client icon: same art as the 256 tile, under the client's own file name
    files["benchy-client.svg"] = files["benchy-tile.svg"]
    for name, text in files.items():
        with open(os.path.join(d, name), "w") as f:
            f.write(text)
        print("svg", name)


# ---------------------------------------------------------------- rasters
def render(svg, png, w, h=None):
    cmd = ["rsvg-convert", "-w", str(w)] + (["-h", str(h)] if h else []) + ["-o", png, svg]
    subprocess.run(cmd, check=True)


def pad_to(png, W, H):
    """Center an image on a transparent W x H canvas (PDF rasters keep the old PTS image sizes)."""
    from PIL import Image
    im = Image.open(png).convert("RGBA")
    c = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    c.paste(im, ((W - im.width) // 2, (H - im.height) // 2), im)
    c.save(png, optimize=True)


def write_ico(path, pngs):
    """ICO with one entry per size: BMP (32-bit BGRA + AND mask) up to 48, PNG at 256.
    BMP entries keep NSIS and older shell code happy; PNG keeps the 256 small."""
    from PIL import Image
    import io
    entries, blobs = [], []
    for p in pngs:
        im = Image.open(p).convert("RGBA")
        w, h = im.size
        if w >= 256:
            b = io.BytesIO()
            im.save(b, "PNG", optimize=True)
            data = b.getvalue()
        else:
            px = im.tobytes("raw", "BGRA")
            rows = [px[y * w * 4:(y + 1) * w * 4] for y in range(h)][::-1]
            xor = b"".join(rows)
            mask_row = ((w + 31) // 32) * 4
            and_mask = bytearray(mask_row * h)
            alpha = im.getchannel("A").tobytes()
            for y in range(h):
                for x in range(w):
                    if alpha[(h - 1 - y) * w + x] == 0:
                        and_mask[y * mask_row + x // 8] |= 0x80 >> (x % 8)
            bih = struct.pack("<IiiHHIIiiII", 40, w, h * 2, 1, 32, 0, len(xor) + len(and_mask), 0, 0, 0, 0)
            data = bih + xor + bytes(and_mask)
        entries.append((w, h, data))
    off = 6 + 16 * len(entries)
    out = struct.pack("<HHH", 0, 1, len(entries))
    for w, h, data in entries:
        out += struct.pack("<BBBBHHII", w % 256, h % 256, 0, 0, 1, 32, len(data), off)
        off += len(data)
    out += b"".join(d for _, _, d in entries)
    with open(path, "wb") as f:
        f.write(out)


def build_raster(out):
    sv = lambda n: os.path.join(out, "svg", n)
    png = os.path.join(out, "png")
    ico = os.path.join(out, "ico")
    os.makedirs(png, exist_ok=True)
    os.makedirs(ico, exist_ok=True)
    P = lambda n: os.path.join(png, n)
    jobs = [
        # web
        ("benchy-tile-32.svg", "favicon.png", 32),              # result viewer, 32 px
        ("apple-touch-icon.svg", "apple-touch-icon.png", 180),
        # desktop, installer, shortcuts; the favicon ICO packs the same four
        ("benchy-tile-16.svg", "benchy-16.png", 16), ("benchy-tile-24.svg", "benchy-24.png", 24),
        ("benchy-tile-32.svg", "benchy-32.png", 32), ("benchy-tile.svg", "benchy.png", 48),
        ("benchy-tile.svg", "benchy-256.png", 256),
        # client (CMake installs it to hicolor/256x256/apps; make-appdir.py copies it)
        ("benchy-client.svg", "benchy-client.png", 256),
        # bare mark, for docs and checks
        ("benchy-mark-16.svg", "benchy-mark-16.png", 16), ("benchy-mark.svg", "benchy-mark-32.png", 32),
    ]
    for s, p, w in jobs:
        render(sv(s), P(p), w)
    # PDF export and dump-documentation keep the old PTS raster sizes (158x82, 308x160), one ink
    render(sv("benchy-oneink.svg"), P("benchy-158x82.png"), 130)
    pad_to(P("benchy-158x82.png"), 158, 82)
    render(sv("benchy-oneink.svg"), P("benchy-308x160.png"), 260)
    pad_to(P("benchy-308x160.png"), 308, 160)
    write_ico(os.path.join(ico, "favicon.ico"), [P("benchy-16.png"), P("benchy-24.png"), P("benchy-32.png"), P("benchy.png")])
    write_ico(os.path.join(ico, "benchy.ico"),
              [P("benchy-16.png"), P("benchy-24.png"), P("benchy-32.png"), P("benchy.png"), P("benchy-256.png")])
    print("rasters written to", png, "and", ico)


def main():
    here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("step", choices=["svg", "raster"])
    ap.add_argument("--fonts", help="folder with the Barlow Semi Condensed TTFs (svg step)")
    ap.add_argument("--out", default=here)
    a = ap.parse_args()
    if a.step == "svg":
        if not a.fonts:
            ap.error("svg needs --fonts")
        build_svg(a.out, a.fonts)
    else:
        if not shutil.which("rsvg-convert"):
            sys.exit("rsvg-convert not found (librsvg)")
        build_raster(a.out)


if __name__ == "__main__":
    main()
