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

1. Bump the version in all three places to the same value (`.github/scripts/check-version.sh` checks them):
   - `Version:` in the header of `woo-zahls-gateway.php`
   - `define('ZAHLS_VERSION', ...)` in `woo-zahls-gateway.php`
   - `Stable tag:` in `readme.txt` (and add a changelog entry)
2. Merge to `main`, then tag the merge commit on `main` and push: `git tag v2.0.8 && git push origin v2.0.8`
3. The `Release` workflow checks that the tag is on `main` and the versions match, commits to WordPress.org SVN (`trunk`, `tags/<version>`, `assets`) and creates a GitHub release with the plugin ZIP and its provenance attestation.

Changes to `readme.txt` (e.g. "Tested up to") or `.wordpress-org/` on `main` are published without a new release by the `Update readme and assets` workflow, as long as the readme's `Stable tag` is already released.

### WordPress.org credentials

The SVN credentials live in the GitHub environment `wordpress-org`, so only the release and readme/assets workflows can read them:

| Secret | Value |
| --- | --- |
| `SVN_USERNAME` | WordPress.org username with commit access to the plugin |
| `SVN_PASSWORD` | SVN password from WordPress.org profile → Account & Security |

Restrict the environment's deployment branches and tags to `main` and `v*`.

## CI

Pull requests and pushes to `main` run the `CI` workflow: version consistency check and `php -l` on PHP 7.4 and 8.5. All actions are pinned to commit SHAs; Dependabot keeps them up to date.

Release ZIPs carry a signed build provenance attestation:

```sh
gh attestation verify zahls-ch-payment-gateway.zip --repo zahls/zahls-plugin-woocommerce
```

## Security

See [SECURITY.md](SECURITY.md).

## License

GPLv2 or later
