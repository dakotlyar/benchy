# Benchy brand files

The Benchy mark is a workbench: one part sits on the bench and another is lifted off it. The machine stays and the parts get swapped. It's drawn in one teal. This folder holds the final artwork for Benchy 0.10.0 (checkpoint B0 of `ideas/developing/benchy-branding.md`). The SVGs are the masters. Every PNG and ICO here is generated from them by `tools/make-brand.py`.

The mark comes in two forms:

- **The bare mark** is teal on a transparent background. Use it where Benchy controls the background: the Phoromatic banner, result graphs, print and docs.
- **The tile** is a white mark on a teal rounded square. Use it for every surface Benchy doesn't control: browser tabs, the Windows desktop and taskbar, Linux launchers and phone home screens. On a dark tab strip or taskbar the bare teal mark only reaches about 3:1 contrast and looks dim, so these surfaces always get the tile.

## Files and where they go

Paths in the Benchy tree are relative to `pts-core/`.

| File | What it is | Where it goes |
|---|---|---|
| `svg/benchy-mark.svg` | The mark on its 32 grid, teal `#0b7480` | Source for the banner and graph helper. Copy it to `static/images/brand/` |
| `svg/benchy-mark-16.svg`, `png/benchy-mark-16.png`, `png/benchy-mark-32.png` | The pixel-snapped 16 px cut of the bare mark | `static/images/brand/`. Use it for small marks inside pages, such as the system table corner |
| `svg/benchy-tile.svg` | The app tile master, 256 px (an exact 8× of the 32 cut) | `static/images/brand/`. Install it as `benchy.svg` in `share/icons/hicolor/scalable/apps/` from `install-sh` and `install_macos` |
| `svg/benchy-tile-32.svg`, `-24.svg`, `-16.svg` | The tile redrawn on whole pixels for 32, 24 and 16 px | `static/images/brand/`. Source for the small rasters |
| `svg/benchy-graph.svg` | The one-color graph form: mark and wordmark, all teal | The paths that `pts_graph_core::draw_brand_mark()` draws, scaled to 76 px wide in the old 76×41 logo box (about 16 px high, top-right). It survives the dark-mode `invert(.895) hue-rotate(180deg)` |
| `svg/benchy-oneink.svg` | The print form: mark and wordmark in a single ink (black here) | `static/images/brand/`. Source for the PDF rasters. Any single ink color works |
| `svg/benchy-phoromatic-light.svg`, `-dark.svg` | The banner lockup for 0.10.x: "benchy Phoromatic" | The inline SVG in `phoromatic_webui_header()`, at 30 px high. In CP1, replace the fills with `var(--ph-brand)`, `var(--ph-text)` and `var(--ph-text-muted)` |
| `svg/benchymatic-light.svg`, `-dark.svg` | The banner lockup after the engine conversion: "benchymatic", with "matic" in Regular | Not used yet. Keep it in `static/images/brand/` |
| `svg/apple-touch-icon.svg`, `png/apple-touch-icon.png` | The 180 px touch icon: a full-bleed teal square, because phones round the corners themselves | `phoromatic/public_html/apple-touch-icon.png` |
| `ico/favicon.ico` | The tile at 16, 24, 32 and 48 px (BMP entries) | `phoromatic/public_html/favicon.ico`. Keep the filename and link it as `favicon.ico?v=<PTS_CORE_VERSION>` |
| `png/favicon.png` | The tile at 32 px | `static/dynamic-result-viewer/favicon.png` |
| `png/benchy.png` | The tile at 48 px | `static/images/benchy.png` (same filename, no path changes) |
| `png/benchy-256.png` | The tile at 256 px | `static/images/benchy-256.png`, and `hicolor/256x256/apps/benchy.png` |
| `ico/benchy.ico` | The tile at 16, 24, 32 and 48 px (BMP) and 256 px (PNG) | `static/images/benchy.ico`, used by the NSIS `MUI_ICON` and the Start-menu shortcuts in `benchy-windows.ps1` |
| `png/benchy-16.png`, `-24.png`, `-32.png` | The individual tile sizes inside both ICOs | Not shipped. They're kept for checking |
| `png/benchy-158x82.png`, `png/benchy-308x160.png` | One-ink rasters at the old PTS image sizes | Replace `static/images/pts-158x82.jpg` (`pts_pdf_template.php`) and `pts-308x160.png` (`pts_result_file_output.php`, `dump_documentation.php`) |
| `svg/benchy-client.svg`, `png/benchy-client.png` | The client icon: the same art as the tile, under the client's filenames | `benchy-client/assets/`. CMake installs them to `hicolor/scalable/apps` and `hicolor/256x256/apps`, and `make-appdir.py` copies the PNG into the AppImage |
| `tools/make-brand.py` | The generator | Goes in `static/images/brand/` next to the masters. Run it by hand and commit what it writes; nothing runs at build time |
| `fonts/OFL-BarlowSemiCondensed.txt` | The font's license | Reference only. See "Font" below |

The engine (`benchy-engine`) has no icons today and needs none from this set.

**README header on GitHub.** GitHub shows the README in both light and dark themes, so the one-ink black raster would disappear in dark mode. Use a `<picture>` that serves `benchy-phoromatic-dark.svg` for `prefers-color-scheme: dark` and `benchy-phoromatic-light.svg` otherwise. A version without "Phoromatic" can be cut later if Dave wants one.

**No `favicon.svg`.** The design originally listed one, but browsers prefer an SVG favicon over the ICO when both are linked, and a single SVG can't be pixel-exact at both 16 and 32 px. The ICO already carries a hand-snapped cut for each size. The page head links `favicon.ico` and `apple-touch-icon.png` only.

## Colors

| Token | Value | Use | Contrast |
|---|---|---|---|
| Teal | `#0b7480` | The mark, tiles and graph form; `--ph-brand` in light mode | 5.5:1 on white |
| Teal on dark UI | `#138896` | The bare mark on dark Phoromatic surfaces; `--ph-brand` in dark mode | 4.1:1 on `#191c25` |
| White | `#ffffff` | The mark on the tile | 5.5:1 on teal |
| Wordmark, light | `#1f2430` for "benchy", `#626c7a` for "Phoromatic" | The same values as `--ph-text` and `--ph-text-muted` | |
| Wordmark, dark | `#e6e8ec` and `#99a1b1` | The dark values of the same tokens | |
| One ink | `#000000` | Print and PDF | |

The tiles use `#0b7480` in both themes, because a desktop or tab strip has no theme Benchy can read.

## Font

The wordmark is set in **Barlow Semi Condensed**: SemiBold for "benchy" and Regular for "Phoromatic" and "matic". It's by Jeremy Tribby (The Barlow Project Authors, 2017) and licensed under the **SIL Open Font License 1.1**. The license text is in `fonts/OFL-BarlowSemiCondensed.txt`.

Every shipped SVG has the letters converted to outlined `<path>`s, shaped with HarfBuzz with the font's kerning, so no font file ships and nothing depends on installed fonts. Under the OFL, artwork made with a font is not a derivative of the Font Software, so the outlined logo carries no OFL obligations. It can sit in the GPL tree like any other image. The license copy is kept here so the origin is on record. The font's `fsType` is 0, which means no embedding restrictions.

## Regenerating

```sh
python3 -m venv venv && venv/bin/pip install fonttools uharfbuzz pillow
# fonts: BarlowSemiCondensed-SemiBold.ttf and -Regular.ttf from
#   https://github.com/google/fonts/tree/main/ofl/barlowsemicondensed
venv/bin/python tools/make-brand.py svg --fonts /path/to/fonts   # only when the geometry or words change
venv/bin/python tools/make-brand.py raster                        # needs rsvg-convert (librsvg)
```

The geometry lives at the top of the script: `MARK32`, `MARK16`, `TILE32`, `TILE24` and `TILE16`, each a list of rectangles. The 16, 24 and 32 px cuts use whole pixels on purpose. If you change them, check the output at 8× zoom.
