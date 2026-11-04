# drupal-ecommerce

A Drupal 11 storefront for selling **products** and **kits** (bundles of
products), built as two custom modules on top of a standard
`drupal/recommended-project` install.

Kit price and stock are never stored — they are derived from the products in
the kit on every read — and products are shared between kits, so the cart is
built to reserve stock safely under concurrency. There is **no checkout yet**:
the cart is a read-only summary.

## Stack

| | |
| --- | --- |
| Drupal | 11.4 (`drupal/core-recommended`), docroot `web/` |
| PHP | 8.4 |
| Database | MariaDB |
| Web server | nginx-fpm |
| Local env | [DDEV](https://ddev.com) (project name `drupal-ecommerce`) |
| CLI | Drush 13 |
| Themes | Olivero (front end), Claro (admin) |
| Dev tooling | `drupal/core-dev` (PHPUnit, PHPCS / `drupal/coder`) |

## Getting started

```bash
ddev start
ddev composer install
ddev drush cr
ddev launch            # opens the site in a browser
```

The site is already installed (standard profile). On a fresh database, install
it first and then enable the custom modules:

```bash
ddev drush site:install standard -y
ddev drush pm:enable product_kit kit_catalog -y
```

`ddev describe` shows the URLs and database credentials.

## Custom modules

Both live in [web/modules/custom/](web/modules/custom/). Each has its own
README with the full design notes — those are the authoritative docs; this
file is only the overview.

### [product_kit](web/modules/custom/product_kit/README.md) — catalogue

Three content entities:

```
PRODUCT ──< KIT_PRODUCT >── KIT
 price       kit_id           name
 stock       product_id       description
 ...         quantity         status
```

* **Kit price** = Σ `product.price × quantity`; **kit stock** = min over lines
  of ⌊`product.stock ÷ quantity`⌋. Both are computed on every read, never
  persisted, so a kit can never disagree with its products.
* A kit must contain at least one product; a product does not need to be in
  any kit.
* `view` of an enabled kit or product is public; create/update/delete need
  the `administer product kit` permission.
* `drush product-kit:audit` lists products with no kit (normal) and kits with
  no product (should not happen).

### [kit_catalog](web/modules/custom/kit_catalog/README.md) — storefront and cart

* **Kits grid** block (`kit_catalog_grid`), configurable count and columns.
* Detail and purchase pages for kits and products, each with **Add to cart**.
* A per-user, read-only cart summary.
* Adding to cart **requires login**.
* **Shared-stock safe**: a product used by several kits, or sold on its own,
  is one shared pool. Adding to cart locks the affected product rows
  (`SELECT … FOR UPDATE`) inside a transaction, so concurrent adds can never
  oversell.
* **Idempotent**: every click carries a token; a retried request replays its
  original outcome instead of reserving twice.

## Routes

| Path | What | Access |
| --- | --- | --- |
| `/` → `/storefront` | Home page: hero (Custom Block) + kits grid, both placed via Block Layout | public |
| `/kit/{kit}` | Kit detail, product breakdown, Add to cart | public if kit is enabled |
| `/product/{product}` | Product page, Add to cart | public if product is enabled |
| `/cart` | Cart summary (kits + products, subtotals, total) | logged-in users |
| `/admin/product-kit` | Catalogue admin | `administer product kit` |
| `/admin/structure/product-kit/{product,kit,kit-line}` | Entity settings | `administer product kit` |

## Running the tests

`kit_catalog` has a Kernel test suite (12 tests on `CartManager`: stock
arithmetic, idempotent replay, shared-stock regressions and a two-session
`FOR UPDATE` lock test).

```bash
# Once: copy the PHPUnit config and set SIMPLETEST_DB to a reachable DB,
# e.g. mysql://db:db@db/db#kittest (Kernel tests use their own prefixed
# tables and drop them afterwards, so DDEV's own DB is safe to use).
cp web/core/phpunit.xml.dist web/core/phpunit.xml

ddev exec "cd web/core && ../../vendor/bin/phpunit -c phpunit.xml ../modules/custom/kit_catalog/tests"
```

Coding standards:

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom
```

## Not built yet / known limitations

* **No checkout**: no orders, no stock decrement, no remove or edit in the
  cart. When it is built, decrement stock with one atomic conditional
  `UPDATE` per product in a single transaction, and snapshot the price on
  the order (see `product_kit`'s README).
* **Cart reservations never expire**: an abandoned cart keeps its stock
  reserved for good.
* `product_kit` has no automated tests of its own yet.
* Prices are single-currency, shown as plain decimals.
* Schema changes in development were applied by reinstalling the modules.
  Any deployment past this point needs `hook_update_N` implementations.

## License

GPL-2.0-or-later — see [LICENSE.txt](LICENSE.txt).
