=== Second Shelf ===
Contributors: jeptvar
Tags: woocommerce, returns, inventory, resale, open-box
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.2
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Inspect returned units and prepare separate, traceable resale drafts with stock of one.

== Description ==

Second Shelf adds an inspection workbench under WooCommerce. Record a physical return against an original order line, document condition and missing accessories, choose public photos, and generate a separate resale draft for staff review.

Private notes stay in the inspection. The draft has its own SKU, one unit of stock and no backorders. Refunds and original inventory are never modified. Staff must reconcile any earlier restock before selling a separate resale listing.

Pilot release. Supports physical simple products and variations, MySQL/MariaDB with advisory locks and InnoDB, and WooCommerce's legacy and HPOS order storage. Requires WooCommerce 9.5+. Media Library uploads have public URLs: do not upload private customer evidence.

== Installation ==

1. Activate WooCommerce.
2. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
3. Activate Second Shelf and open WooCommerce > Second Shelf.
4. Start on a staging store and review the inventory boundaries in README.md.

== Frequently Asked Questions ==

= Does this issue refunds or restock the original item? =
No. Handle refunds separately. Avoid double-counting units already restocked by another tool.

= Are inspection photos private? =
No. Photos are ordinary WordPress Media Library attachments with public URLs. Only the text field labelled Internal inspection notes is private to the workbench.

= Does creating a draft publish it? =
No. Staff review and publish through the WooCommerce product editor.

= What happens when the plugin is removed? =
Inspection records and resale products are retained. Uninstall does not delete business records or attachments. Back up and manage retention through your site's existing process.

== Screenshots ==

1. Inspection workbench with status filters, condition and resale prices.
2. Inspect a returned unit, choose public photos and prepare a separate resale draft.

== Changelog ==

= 0.1.2 =
* Prepared database identifiers and added translation context for directory review.
* Updated package metadata and added directory screenshots.

= 0.1.1 =
* Refined WordPress admin layout with native controls, compact list filters, product thumbnails, condition and resale price columns.
* Grouped listing, photos and internal notes into admin panels; moved inspection decisions and save actions into a sidebar.
* Retained unsaved-change protection for sidebar controls and improved mobile layouts.

= 0.1.0 =
* Initial pilot: unit receipt, inspection, photos, disclosures, single-unit drafts, private source links and retry handling.
