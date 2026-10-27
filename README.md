# zahls.ch Payment Gateway for WooCommerce

Accept TWINT, cards, PostFinance and more in WooCommerce via [zahls.ch](https://www.zahls.ch).

Published on WordPress.org as [`zahls-ch-payment-gateway`](https://wordpress.org/plugins/zahls-ch-payment-gateway/).

## Requirements

- WordPress with WooCommerce 8.0+
- zahls.ch account

## Install

Install "zahls.ch" from WordPress → Plugins → Add New, or upload `zahls-ch-payment-gateway.zip` from the [latest release](https://github.com/zahls/zahls-plugin-woocommerce/releases/latest).

## Repository layout

- Plugin files live in the repository root and are deployed to SVN `trunk`.
- `.wordpress-org/` holds the WordPress.org banners, icons and screenshots (SVN `assets`).
- `.distignore` lists files that are not deployed.

## Release

1. Bump the version in all three places to the same value:
   - `Version:` in the header of `woo-zahls-gateway.php`
   - `define('ZAHLS_VERSION', ...)` in `woo-zahls-gateway.php`
   - `Stable tag:` in `readme.txt` (and add a changelog entry)
2. Merge to `main`, then tag and push: `git tag v2.0.8 && git push origin v2.0.8`
3. The `Release` workflow checks the versions, commits to WordPress.org SVN (`trunk`, `tags/<version>`, `assets`) and creates a GitHub release with the plugin ZIP.

Changes to `readme.txt` (e.g. "Tested up to") or `.wordpress-org/` on `main` are published without a new release by the `Update readme and assets` workflow, as long as the readme's `Stable tag` is already released.

### Secrets

| Secret | Value |
| --- | --- |
| `SVN_USERNAME` | WordPress.org username with commit access to the plugin |
| `SVN_PASSWORD` | SVN password from WordPress.org profile → Account & Security |

## License

GPLv2 or later
