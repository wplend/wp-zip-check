# wp-zip-check

Check a WordPress plugin or theme ZIP **before** you install it. One PHP file, no dependencies besides `php-zip` and (optionally) ClamAV.

```
$ php wp-zip-check.php my-plugin.zip --expect=1.2.0

wp-zip-check  my-plugin.zip (1.2 MB)

  SHA-256  2ee94e1eb46d0b1c43f4f0c614257df0bfb487eb091b8a52c19598534346960e
  SHA-1    af45ca6bac34772bb863178713eb1eff3e9b5991
  MD5      0a5210da56de54cda13109902ac0b6ce

  Files    412 (5.3 MB unpacked)
           PHP 180 · JavaScript 96 · CSS / SCSS 41 · Images 70 · Translations 25

  Plugin: My Plugin 1.2.0
    Requires WordPress   6.0
    Tested up to         6.6
    Requires PHP         7.4
    License              GPL-2.0-or-later
    Translations         12

  PASS  Version matches 1.2.0
  PASS  PHP lint: 180 files, no syntax errors (PHP 8.3)
  PASS  ClamAV: 412 files, nothing found (ClamAV 1.4.3/27779/...)

  What's new in 1.2.0:
    - New: ...
    - Fix: ...
```
*(numbers above are an example)*

## What it checks

| Check | How |
|---|---|
| Checksums | SHA-256, SHA-1, MD5 of the archive — compare with the value published by your source |
| Safe unpack | to a temp folder; absolute paths and `../` are skipped; archives over 2 GB unpacked are rejected |
| File types | counts PHP, JS, CSS, images, fonts, translations, other |
| Malware | `clamscan -r` over every unpacked file |
| Headers | `Plugin Name` / `Theme Name`, `Version`, `Requires at least`, `Tested up to`, `Requires PHP`, `WC tested up to`, `Elementor tested up to`, `License` (also from `readme.txt`) |
| Version | `--expect=1.2.0` compares it with the declared `Version` |
| PHP syntax | `php -l` on every PHP file (4 in parallel) |
| Changelog | entry for the declared version from `readme.txt`, `changelog.txt` or `CHANGELOG.md` |
| Bundles | if the top level has no plugin/theme (e.g. an "all files" package), nested ZIPs are opened |

The temp folder is deleted when the check ends.

## Install

```bash
# Debian / Ubuntu
sudo apt install php-cli php-zip clamav
sudo freshclam            # update ClamAV signatures

curl -O https://raw.githubusercontent.com/wplend/wp-zip-check/main/wp-zip-check.php
```

## Usage

```bash
php wp-zip-check.php package.zip                 # full check
php wp-zip-check.php package.zip --expect=1.2.0  # also verify the version
php wp-zip-check.php package.zip --json          # machine-readable report
php wp-zip-check.php package.zip --no-clamav     # skip the malware scan
```

Exit codes: `0` all checks passed · `1` a check failed · `2` error (bad ZIP, missing file).

Check a whole folder:

```bash
for f in *.zip; do php wp-zip-check.php "$f" --json > "${f%.zip}.json"; done
```

## Verify a checksum without this tool

```bash
sha256sum package.zip                          # Linux
shasum -a 256 package.zip                      # macOS
Get-FileHash -Algorithm SHA256 package.zip     # Windows PowerShell
```

## Limits

- A clean ClamAV result means no **known** signature matched. It is not a code audit.
- `php -l` checks syntax only; it does not run the code.
- Requirements and "Tested up to" are what the developer declares, not a test.

## Background

This is a standalone version of the package scanner we run on every archive at [WPlend](https://wplend.net) — see our [review methodology](https://wplend.net/review-methodology/).

## License

GPL-2.0-or-later
