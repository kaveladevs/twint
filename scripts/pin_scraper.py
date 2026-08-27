#!/usr/bin/env python3
"""Pinterest pin scraper for visual research.

Downloads pins matching one or more search queries so the images can be reviewed
locally (e.g. to derive image-generation prompts from a mood board).

Pinterest blocks datacenter IPs with ``403 host_not_allowed``, so this must run
from a residential connection -- your own machine, not a VPS.

Usage:
    python3 scripts/pin_scraper.py "luxury mediterranean hotel lobby"
    python3 scripts/pin_scraper.py -n 200 -o mood/lobby "query one" "query two"

Requires: pip install pinterest-dl
"""

import argparse
import shutil
import subprocess
import sys
from pathlib import Path


def check_deps() -> None:
    if shutil.which("pinterest-dl") is None:
        sys.exit(
            "pinterest-dl not found. Install it first:\n"
            "    python3 -m venv .venv && source .venv/bin/activate\n"
            "    pip install pinterest-dl"
        )


def scrape(query: str, count: int, outdir: Path) -> int:
    """Run pinterest-dl for one query. Returns the number of files downloaded."""
    before = set(outdir.glob("*"))
    cmd = [
        "pinterest-dl", "search", query,
        "-n", str(count),
        "-o", str(outdir),
        "--caption", "txt",  # keep alt text -- useful context when reading images
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        print(f"  ! failed: {result.stderr.strip().splitlines()[-1:] or 'unknown error'}")
        return 0
    return len(set(outdir.glob("*")) - before)


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("queries", nargs="+", help="Search queries")
    ap.add_argument("-n", "--num", type=int, default=100,
                    help="Images per query (default: 100)")
    ap.add_argument("-o", "--output", default="pins",
                    help="Output directory (default: pins)")
    args = ap.parse_args()

    check_deps()

    outdir = Path(args.output)
    outdir.mkdir(parents=True, exist_ok=True)

    total = 0
    for query in args.queries:
        print(f"Scraping '{query}' ...")
        n = scrape(query, args.num, outdir)
        print(f"  -> {n} new files")
        total += n

    print(f"\n{total} images in {outdir.resolve()}")


if __name__ == "__main__":
    main()
