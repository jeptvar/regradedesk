# RegradeDesk

Turn a returned unit into a documented, single-unit resale draft in WooCommerce.

Staff receive an item against its original order, inspect it, record wear and missing accessories, choose public photos, and prepare a separate product. The original order remains intact. Internal notes stay in the inspection record.

**Status: 0.1.2 pilot.** Start on a staging store. This is an inspection-to-listing workbench, not a returns authorization, refund, repair, or warehouse system.

![RegradeDesk inspection with public disclosure, private notes and a separate draft action](https://raw.githubusercontent.com/jeptvar/regradedesk/main/docs/inspection.png)

## The workflow

1. Open **WooCommerce → RegradeDesk** and find the original order by its numeric ID.
2. Choose its product line and confirm that one physical unit has arrived and is separated from sellable stock.
3. Record the listing title, condition, resale price, buyer-facing disclosure, private notes, and up to 12 photos.
4. Confirm the physical inspection and public suitability of the photos. Save the decision as **Ready for draft**. Unsuitable units can be marked **Not for resale**.
5. Select **Create resale draft**. Review the draft in the standard WooCommerce editor before publishing.

The draft contains the selected photos and disclosure, condition in its title and description, a unique `SS-{site}-{unit}` SKU, stock of one, individual purchase only, and no backorders. It inherits weight, dimensions, shipping class, tax settings and categories from the current source product. Review those values before publishing, especially when packaging or accessories changed. Returned variations become independent simple products; include all variant details in the listing title and disclosure.

## Install

Download `regradedesk-0.1.2.zip` from [Releases](https://github.com/jeptvar/regradedesk/releases) or build it with `php tools/package.php`. In WordPress use **Plugins → Add New → Upload Plugin**, choose that ZIP, and activate it with WooCommerce active.

Alternatively, copy this folder to `wp-content/plugins/regradedesk` and activate **RegradeDesk**.

Upgrading from the earlier **Second Shelf** pilot: deactivate Second Shelf, then install and activate RegradeDesk. Both use the existing inspection table and product links, so records are retained. RegradeDesk leaves its workbench disabled while the earlier plugin is active. The internal `second_shelf_*` storage names and `SS-` product SKUs remain unchanged for compatibility.

Requirements: WordPress 6.7+, WooCommerce 9.5+, PHP 8.1+, and MySQL/MariaDB supporting `GET_LOCK`. Draft reservations require InnoDB for the posts, postmeta, and RegradeDesk tables. SQLite / WordPress Playground is not supported. Release ZIP integration tests cover WordPress 6.7 + WooCommerce 9.5.0 on PHP 8.1, and WordPress 7.1.3 + WooCommerce 11.2.1 on PHP 8.3, with MariaDB 11.4 and both legacy orders and HPOS. CI also exercises WordPress 6.9.4 + WooCommerce 10.7.0. This is not an exhaustive matrix of every intervening version or third-party extension.

## Inventory and privacy boundaries

- RegradeDesk does **not** refund, change order totals, restock, or decrement the original product. If WooCommerce or another returns tool already restocked a returned unit, reconcile that stock before selling a separate resale listing. Otherwise the same physical unit could be offered twice.
- One inspection corresponds to one unit from an original order line. The total count cannot exceed that line's original whole-unit quantity. Rejected inspections still count. Recording receipt is an explicit staff action, not evidence that a shipment actually arrived.
- Supported source orders are processing, completed or refunded, with existing physical simple products or variations. Digital products, bundles, subscriptions, fractional quantities and missing source products are outside this first version's scope.
- Internal notes are never copied to product content or metadata. The order/item link is stored as private product metadata and in the inspection table. No public inspection API is registered.
- All selected photos use the normal WordPress Media Library. **Uploads have public URLs, even before a product is published.** Do not upload customer labels, faces or other private evidence here. This plugin is not private evidence storage.
- Staff require both `manage_woocommerce` and `edit_products`; photos and linked products also require their WordPress edit permissions. Mutations require authenticated POSTs with nonces.
- Completed inspection snapshots are frozen. Later product edits do not change the inspection. Record timestamps and the receiving/last-editing user are retained, but there is no full edit-history ledger.

## Duplicate and failure handling

Receipt submissions carry an idempotency identifier. A database lock serializes receipt counts per original line, and a separate lock serializes each inspection's saves and draft creation. A version field rejects stale edits.

Draft creation reserves a product ID and links it to the inspection in one database transaction, then populates that same draft through WooCommerce. If a hook interrupts preparation, the record shows **Preparation incomplete**. Fix the cause and retry from the inspection. An unfinished draft cannot be published while RegradeDesk is active. Do not publish incomplete placeholders after deactivating the plugin.

After successful preparation, repeated requests return the same product without resetting stock or content. If a linked product is trashed or deleted, RegradeDesk refuses to create a replacement. Restore it from Trash or a backup. These guarantees assume standard WordPress/WooCommerce behavior: third-party hooks that explicitly commit or alter transactions, database proxies that change connections, or direct SQL edits are unsupported.

## Local development and checks

Docker, Node.js 22+ and PHP with ZipArchive are development tools; the installed plugin has no Node or Composer runtime dependencies.

```sh
npm ci
npm run dev
# Open http://localhost:8097/wp-admin/
# Local credentials are in artifacts/demo-login.txt (ignored by Git).
npm test
npx playwright install chromium
npm run test:browser
npm run package
npm run test:package
npm run test:minimum
docker compose stop
```

The Docker site is disposable and bound to loopback. It uses dedicated `second-shelf-dev` containers and volumes. The example order and lamp illustration contain synthetic data. The PHP integration tests create additional fixtures and must only run against this development site. They exercise real WordPress, WooCommerce and MariaDB with both legacy orders and HPOS. Concurrent processes verify that competing requests produce one unit and one draft. Browser checks cover receipt, inspection, media selection, draft review, nonce rejection, and desktop/mobile accessibility of the plugin content.

To use an installed Edge browser on Windows: set `PLAYWRIGHT_CHANNEL=msedge` when running the browser check.

`php tools/package.php` builds an allowlisted ZIP; tests, Docker configuration, credentials, node modules and screenshots are excluded.

Package checks extract that ZIP into a fresh directory and mount it read-only in isolated Docker projects. They run integration tests against the shipped files; the current stack also runs official Plugin Check 2.1.0 with runtime checks enabled. Each invocation removes only its own temporary containers and volumes. Results are saved under `artifacts/`. If PHP is a Windows batch shim, set `PHP_BINARY` to the real `php.exe` path for these Node scripts.

Directory screenshots, banners and icons live in `.wordpress-org/`; see [publication notes](docs/wordpress-org.md). They belong in the WordPress.org SVN repository's top-level `assets/`, separately from the installable plugin.

## Pilot questions

Try five real returns on a staging copy with the person who handles them. Measure receipt-to-draft time and corrections needed during product review. Confirm the store's existing refund/restock process before choosing a live workflow. Batch receiving, serial numbers, repair tasks, integrations with RMA systems and private photo storage are potential follow-up work, not included features.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The project is licensed under [GPL-2.0-or-later](LICENSE). No hosted account, telemetry, paid feature gate, or external API is required by the plugin.
