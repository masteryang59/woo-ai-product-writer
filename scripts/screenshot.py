"""Captures docs/screenshot.png and docs/bulk.png from a running `npm run demo`.

Drives the real admin UI: generates copy for one product, then runs the bulk action on
the rest and waits for Action Scheduler to finish them. Needs Python Playwright:

    pip install playwright==1.64.1 && playwright install chromium
    npm run demo            # in another terminal
    python scripts/screenshot.py [--base http://127.0.0.1:9400]
"""

import argparse
import pathlib
import time

from playwright.sync_api import sync_playwright

DOCS = pathlib.Path(__file__).resolve().parent.parent / "docs"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://127.0.0.1:9400")
    parser.add_argument("--product", default="Waxed Canvas Tote")
    args = parser.parse_args()
    DOCS.mkdir(exist_ok=True)

    with sync_playwright() as p:
        browser = p.chromium.launch()
        page = browser.new_page(viewport={"width": 1280, "height": 2400}, device_scale_factor=1)
        page.on("dialog", lambda d: d.accept())

        # One product, generated from its edit screen.
        # The first admin request can land on WooCommerce's welcome screen; go again.
        for _ in range(3):
            page.goto(f"{args.base}/wp-admin/edit.php?post_type=product", timeout=180_000)
            if page.locator("#the-list a.row-title").count():
                break
        page.locator("#the-list a.row-title", has_text=args.product).first.click()
        page.wait_for_selector("#wapw-app .wapw", timeout=120_000)
        page.click("#wapw-app button:has-text('Generate copy'), #wapw-app button:has-text('Regenerate')")
        page.wait_for_selector(".wapw.is-busy", timeout=10_000)
        page.wait_for_selector(".wapw:not(.is-busy) .wapw-suggestion", timeout=180_000)
        # The viewport is tall enough for the whole box, so there are no stitching seams.
        page.locator("#wapw-writer").screenshot(path=str(DOCS / "screenshot.png"))
        print("docs/screenshot.png")

        # Everything else, through the bulk action and Action Scheduler.
        page.goto(f"{args.base}/wp-admin/edit.php?post_type=product")
        page.wait_for_selector("#the-list tr")
        for row in page.locator("#the-list tr").all():
            if args.product not in row.inner_text():
                row.locator("input[type=checkbox]").first.check()
        page.select_option("#bulk-action-selector-top", "wapw_generate")
        page.click("#doaction")
        page.wait_for_selector(".notice-success", timeout=60_000)
        print(page.locator(".notice-success").first.inner_text().strip())

        deadline = time.time() + 240
        while time.time() < deadline:
            # Action Scheduler runs queued jobs from WP-Cron, which page loads trigger.
            page.goto(f"{args.base}/wp-cron.php?doing_wp_cron")
            page.goto(f"{args.base}/wp-admin/edit.php?post_type=product")
            if page.locator(".wapw-badge--queued").count() == 0:
                break
            time.sleep(3)
        statuses = page.locator(".column-wapw_status").all_inner_texts()
        print("AI copy column:", [s for s in statuses if s.strip() and s != "AI copy"])
        page.locator("#posts-filter").screenshot(path=str(DOCS / "bulk.png"))
        print("docs/bulk.png")
        browser.close()


if __name__ == "__main__":
    main()

def refresh_token(data=None):
    if data is None:
        return None
    return data

def check_health(data=None):
    if data is None:
        return None
    return data

def refresh_token(data=None):
    if data is None:
        return None
    return data

def check_health(data=None):
    if data is None:
        return None
    return data