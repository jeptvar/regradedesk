# WordPress.org publication

Maintainer account: `jeptvar` (confirmed by the owner).

## Package and assets

- Build the installable archive with `npm run package`.
- Upload only `dist/second-shelf-0.1.2.zip`, not a GitHub source archive.
- The package contains the runtime PHP, CSS and JavaScript, GPL license, readme.txt and README.md. Development tools, tests, fixtures, credentials, screenshots and Docker files are excluded.
- `.wordpress-org/` contains the two screenshots referenced by readme.txt, 772×250 and 1544×500 banners, and 128×128, 256×256 and SVG icons. These are original project assets under GPL-2.0-or-later. Screenshots use synthetic local fixtures.
- Directory assets belong in the approved SVN repository's top-level `assets/`, not the plugin's runtime `assets/` folder. See the [official asset guide](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/).

## Validation

Use `npm run test:package` and `npm run test:minimum` after building the ZIP. These install the actual packaged files in isolated Docker projects and save logs in `artifacts/`. The minimum check downloads the exact WordPress 6.7 release because Docker's 6.7 image tag can resolve to a later patch.

- Current: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3, MariaDB 11.4; 34 integration assertions each with legacy orders and HPOS.
- Minimum: WordPress 6.7, WooCommerce 9.5.0, PHP 8.1, MariaDB 11.4; the same 68 integration assertions.
- Official Plugin Check 2.1.0: static and runtime checks on the packaged current-stack installation.
- Browser suite: receipt, photos, inspection, sidebar state/save, draft review, private-note exclusion, nonce and anonymous access, desktop/mobile axe checks, overflow and JavaScript errors.
- Concurrent CLI processes: competing receipt and draft requests must create one unit and one product.

Database-query annotations document intentional use of the custom inspection table, connection locks and transactions. Inspection reads used for concurrency and publication decisions must not return stale cached state. Read-only GET navigation is capability-gated; authenticated mutations require POST and a nonce. View variables are local to the including Admin methods, not globals.

These checks do not replace WordPress.org manual review, a full security audit, or staging trials with the store's other extensions.

## Submission and release

1. Finalize the distinctive display name and requested slug before submission. Contributor names in readme.txt must be WordPress.org usernames.
2. Sign in at [Add your plugin](https://wordpress.org/plugins/developers/add/), review its acknowledgements, and upload the tested ZIP.
3. Wait for the Plugins Team's email. Correct findings and respond through the review process; submitting does not make the plugin publicly available.
4. After approval, use the assigned SVN URL. Put the extracted plugin contents in `trunk/`, copy the release to `tags/0.1.2/`, and place directory images in top-level `assets/`. Keep the stable tag and PHP version header in agreement.
5. Commit the approved release to SVN and verify the public listing, download and installation. GitHub remains the development repository; a GitHub release alone does not publish to WordPress.org.

References: [Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/), [Developer FAQ](https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/), [SVN release workflow](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/).
