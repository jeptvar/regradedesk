# Contributing

Open an issue with the merchant workflow or reproducible failure before making a broad change. Keep the first release focused on one physical unit moving from receipt to a reviewable resale draft.

Use the disposable Docker setup documented in README.md. Run `npm test`, `npm run test:browser`, PHP syntax checks and `npm run package`. Add tests for changes to permissions, inventory, duplicate prevention or failure recovery. Never include customer data, live credentials or private inspection images in fixtures or issues.

Use WooCommerce CRUD for orders and products. Escape output, validate input and check capabilities in the domain layer as well as nonces in request handlers. Keep private inspection fields out of product content and public APIs.

Development dependencies stay out of the plugin ZIP. Contributions are distributed under GPL-2.0-or-later.
