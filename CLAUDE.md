# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Drupal 11 storefront for selling **products** and **kits** (bundles of
products), built as two custom modules (`product_kit`, `kit_catalog`) plus a
custom storefront theme (`ecommerce_barrio`) on top of a standard
`drupal/recommended-project` install. Kit price and stock are **never
stored** — always derived from the products in the kit on every read — and
products are shared between kits, so the cart is built to reserve stock
safely under concurrency. There is **no checkout yet**: the cart is a
read-only summary.

| | |
| --- | --- |
| Drupal | 11.4 (`drupal/core-recommended`), docroot `web/` |
| PHP | 8.4 |
| Database | MariaDB |
| Web server | nginx-fpm |
| Local env | [DDEV](https://ddev.com) (project name `drupal-ecommerce`) |
| CLI | Drush 13 |
| Theme | `ecommerce_barrio` (custom, default + front end), Claro (admin) |
| Dev tooling | `drupal/core-dev` (PHPUnit, PHPCS / `drupal/coder`) |

The root `README.md` and each custom module/theme's own `README.md` are the
authoritative design docs — read those before making non-trivial changes;
this file is only commands + map.

## Commands

```bash
ddev start
ddev composer install
ddev drush cr                 # cache rebuild — run after any code/config/template change
ddev launch                   # opens the site in a browser
ddev describe                 # site URL + DB credentials
```

Fresh database (the dev DB is already installed — standard profile):

```bash
ddev drush site:install standard -y
ddev drush pm:enable product_kit kit_catalog -y
```

Running the `kit_catalog` Kernel test suite (16 tests on `CartManager`: stock
arithmetic, idempotent replay, shared-stock regressions, a two-session
`FOR UPDATE` lock test — the only automated tests in this codebase):

```bash
cp web/core/phpunit.xml.dist web/core/phpunit.xml   # once; already done here
# web/core/phpunit.xml's SIMPLETEST_DB must point at a reachable DB — Kernel
# tests use their own randomly-prefixed tables and drop them afterwards, so
# DDEV's own DB is safe: mysql://db:db@db/db#kittest

# whole suite
ddev exec "cd web/core && ../../vendor/bin/phpunit -c phpunit.xml ../modules/custom/kit_catalog/tests"

# a single test file
ddev exec "cd web/core && ../../vendor/bin/phpunit -c phpunit.xml ../modules/custom/kit_catalog/tests/src/Kernel/CartManagerTest.php"

# a single test method
ddev exec "cd web/core && ../../vendor/bin/phpunit -c phpunit.xml ../modules/custom/kit_catalog/tests/src/Kernel/CartManagerTest.php --filter testForUpdateLockBlocksSecondSession"
```

`product_kit` has no automated tests of its own yet.

Coding standards:

```bash
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom
```

Catalogue integrity check (lists products with no kit — normal — and kits
with no product — should never happen; always exits successfully):

```bash
ddev drush product-kit:audit
```

Editing any content that's config (blocks, block layout, menus, text
formats) is done through the admin UI, not files — this project has no
config sync directory workflow in play; `drush config:set`/`php:script`
against live entities is how prior sessions have scripted catalogue/content
changes (see `web/modules/custom/kit_catalog/README.md` and the theme's
README for examples).

## Architecture

### `product_kit` — the catalogue (entities, no storefront UI)

Three content entities under `Drupal\product_kit`:

```
PRODUCT ──< KIT_PRODUCT >── KIT
 price       kit_id           name
 stock       product_id       description
 ...         quantity         status
```

* **Kit price** = Σ `product.price × quantity`; **kit stock** = min over
  lines of ⌊`product.stock ÷ quantity`⌋. Both computed on every read by
  `KitCalculator` (`product_kit.calculator`) — never persisted columns, so a
  kit can never disagree with its products.
* The product↔kit relationship is required **one way only**: a kit must
  always have ≥ 1 product (enforced on every path — forms, line delete,
  product delete — via `KitCompositionManager`); a product needs no kit.
* Prices are integers in **minor units** in 64-bit columns (`PriceFormatter`
  is the only place that converts to/from the `"10.50"` the UI shows).
* `view` of an enabled `kit`/`product` is public
  (`ProductKitAccessControlHandler`); every other operation needs
  `administer product kit`.
* Selling (stock decrement) is **not built** — when it is, see that
  README's "Selling a kit" section for the required atomic-`UPDATE`
  pattern, not a naive read-then-write.

### `kit_catalog` — storefront pages + cart

Built on top of `product_kit`, adds no new catalogue data of its own:

* **Kits grid** block plugin (`kit_catalog_grid`, `KitGridBlock` +
  `KitGridForm`) — configurable heading/subheading, count, columns.
* `/kit/{kit}` and `/product/{product}` detail/purchase pages
  (`KitPageController`/`ProductPageController`), each embedding a single-item
  `AddToCartFormBase` subclass.
* `/cart` (`CartForm`), **login required** (`_user_is_logged_in: 'TRUE'`) —
  summary of kit + product lines, each with a "Remover" button
  (`CartManager::removeKitLine()`/`removeProductLine()`); still no quantity
  edit or checkout.
* `/storefront` (`StorefrontController::build()`) is `system.site:page.front`.
  **The controller returns nothing on purpose** — the entire home page (hero,
  grid, newsletter, footer) is Block Layout config in the active theme, not
  PHP. This exists only because `page.front` needs a route to point at; core's
  node-based front page doesn't fit since there are no node types in this
  install.
* **Adding to cart always requires login**; every form re-checks
  `currentUser()->isAuthenticated()` server-side.

**The concurrency design is the most important thing to understand before
touching `CartManager`:**

* A kit and a bare product draw from the **same shared `product.stock`
  pool** — `CartManager::reservedUnitsByProduct()` sums demand from *both*
  `kit_catalog_cart_item` and `kit_catalog_cart_product_item` before either
  `addKitToCart()` or `addProductToCart()` decides whether an add fits.
  Treating them as separate pools would let a kit and a direct sale of one
  of its own products jointly oversell it.
* Both entry points funnel through `CartManager::reserveAgainstProducts()`,
  which locks every affected `product` row (`SELECT ... FOR UPDATE`, lowest
  product ID first — deadlock-free ordering) inside one transaction before
  reading or writing anything derived from them.
* It's also **idempotent**: each "Add to cart" click carries a one-time
  token, regenerated client-side on `mousedown` (not server-side on form
  rebuild — Form API redisplays a hidden field's last *submitted* value, so
  a server-only token never actually changes across clicks).
  `CartManager::claimIdempotencyKey()` makes a retried/duplicated submission
  replay its original recorded outcome instead of re-running the
  reservation.
* Cart storage is **plain DB tables** (`kit_catalog_cart_item`,
  `kit_catalog_cart_product_item`, `kit_catalog_add_request`), not content
  entities — this logic runs inside a lock held as briefly as possible, and
  entity-save hook/cache overhead has no place there.
* `/cart` sets `'#cache' => ['contexts' => ['user']]` explicitly on every
  render array, including the empty-cart one — without it, Dynamic Page
  Cache serves one user's cart to the next visitor (a real bug caught and
  fixed during development, not a hypothetical).

**Scope — checkout does not exist**: `/cart` can remove a line entirely
but has no quantity edit or place-order, no order entity, and nothing
decrements `product.stock` (the cart only *reserves* against it; removing
a line just gives that reservation back). Cart reservations never expire
on their own. When checkout is
built, it must snapshot the kit price at purchase time and decrement stock
with the same atomic-`UPDATE`-per-product-in-one-transaction pattern used for
reservation.

### `ecommerce_barrio` — the theme

Bootstrap 5 subtheme generated from Barrio (`drupal/bootstrap_barrio`) via
its starterkit script; Bootstrap CSS/JS loads from a CDN, no local build
step. Default theme, `page.front` = `/storefront`.

* **The home page is entirely Block Layout config**, not code: hero, hero
  promo, newsletter, and all four footer columns are `block_content`
  entities (bundle `basic`), editable at `/block/{id}`/`/admin/structure/block`
  with no deploy. Only the cart-count link is real code
  (`kit_catalog`'s `CartLinkBlock`), because it's the only piece that isn't
  static copy.
* The "product slider" is **CSS-only** (`scroll-snap-type: x`, scoped to
  `body.path-frontpage`) at every width, matching the supplied desktop
  mockup (four full cards + a fifth clipped at the edge) — no carousel
  library, no JS framework (a React carousel was explicitly considered and
  rejected as overengineering for this). An earlier version switched to a
  static `display: grid` above 900px, which left the Anterior/Próximo
  buttons (`global.js`) with nothing to scroll there — fixed 2026-11-19 to
  match the mockup instead of deviating from it.
* Palette lives as CSS custom properties (`--ecb-*`) on `:root` in
  `css/colors.css`; `css/style.css` (components) reuses those vars.
* **Decorative-but-honest elements**: search bar, wishlist icons, the mobile
  hamburger, most footer links (`href="#"`), and the category nav
  (`route:<nolink>` menu items rendering as inert `<span class="nav-link">`,
  not dead `<a>` links) are all intentionally inert — there's no backing
  feature (search index, wishlist, category/taxonomy concept) in the data
  model yet. Only the "Novo" product badge is shown, because it's the only
  one derivable from real data (`kit.created`); "Mais vendido" and a
  discount badge are deliberately never shown since no sales-count or
  promo-price field exists to back them.
* Site identity is currently "kit.ecommerce" (`system.site:name` +
  `templates/block/block--system-branding-block.html.twig`'s hardcoded
  wordmark); the hero/promo copy was rewritten for a profession-starter-kit
  catalogue (Kit Eletricista, Kit Encanador, Kit Cabeleireira/Cabeleireiro,
  Kit Desenvolvedor). **The category nav is still home-decor-flavored**
  (Sala/Quarto/Cozinha/…) and hasn't been updated to match — a known,
  flagged mismatch, not a bug.
* All `kit_catalog` storefront-facing strings are in Portuguese in source
  (no `.po` files involved); admin-only strings are left in English.

See `web/themes/custom/ecommerce_barrio/README.md` for the full region-by-
region block layout table and several non-obvious Bootstrap/Barrio CSS
gotchas worth reading before editing its templates/CSS:

* Bootstrap utility classes (`.row`, `.nav`) default to
  `flex-wrap: wrap` — any override that sets `flex-direction` without
  re-declaring `flex-wrap: nowrap` silently inherits the wrap and items
  stack instead of sitting side by side (looks like a `flex-direction` bug;
  isn't).
* Barrio's `hook_preprocess_region()` renders `header`/`primary_menu`/
  `content`/`breadcrumb`/both sidebars with **no** wrapping
  `<section class="region region-X">` element at all
  (`region--nowrap.html.twig`) — a CSS rule targeting `.region-header` etc.
  silently matches nothing.
* Barrio's menu block template renders `.nav`/`.nav-item`/`.nav-link`, not
  the `.menu`/`.menu-item` a plain Drupal menu normally gets.
* `block_content` body fields on this site use the `full_html` text format
  (every block but two uses it already); saving one with `basic_html`
  silently strips `class`/`<div>`/`<h1>`/`<span>` since that format's
  `filter_html` allow-list has none of them.
* When testing, **always verify layout with a real screenshot**
  (`google-chrome --headless --disable-gpu --no-sandbox
  --ignore-certificate-errors --screenshot=...`), not just `curl | grep` on
  markup/CSS presence — several real layout bugs here were invisible to
  markup inspection and only showed up rendered.

## Routes

| Path | What | Access |
| --- | --- | --- |
| `/` → `/storefront` | Home page: hero, grid, newsletter, footer, all via Block Layout | public |
| `/kit/{kit}` | Kit detail, product breakdown, Add to cart | public if kit enabled |
| `/product/{product}` | Product page, Add to cart | public if product enabled |
| `/cart` | Cart summary (kits + products, subtotals, total) | logged-in users |
| `/admin/product-kit` | Catalogue admin | `administer product kit` |
| `/admin/structure/product-kit/{product,kit,kit-line}` | Entity settings | `administer product kit` |

## Known limitations (don't silently "fix" without the user asking)

* No checkout: no orders, no stock decrement, no cart quantity edit.
* Cart reservations never expire.
* `product_kit` has no automated tests.
* Prices are single-currency, shown as a plain decimal (no symbol/locale).
* Schema changes so far were applied by reinstalling modules in dev; a real
  deployment needs `hook_update_N` implementations (`product_kit.install`
  already has three: `10002`–`10004`).
* The storefront's category nav still reads as a home-decor store even
  though the catalogue is now profession starter kits — flagged in the
  theme README, not fixed, since nobody's asked for that copy yet.
