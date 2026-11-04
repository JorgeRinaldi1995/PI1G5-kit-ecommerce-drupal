# Kit Catalog

Storefront pages built on top of `product_kit`: a block that lists enabled
kits in a grid, a detail page for each kit, a purchase page for each
product — kit or bare product, both with an **Add to cart** button — and a
read-only cart summary page. There is still no checkout flow — see "Scope"
below.

## What it provides

* A block, **Kits grid (Product Kit)** (plugin ID `kit_catalog_grid`),
  placeable in any region. Configurable: number of kits to show (0 = all
  enabled kits) and number of grid columns (2–4). Browsing the grid itself
  needs no account. Each card shows the kit's name (linked to its detail
  page, below), description, derived price (`product_kit.price_formatter`)
  and derived stock (`Kit::getStock()`); the card reads "Out of stock" when
  `Kit::canBeSold()` is false.
* A detail page per kit, `/kit/{kit}` (`kit_catalog.kit_page`,
  `KitPageController`): the same facts as the grid card, plus the kit's
  full "what's in this kit" breakdown — each line linking to that product's
  own purchase page, *unless* the viewer cannot see that product (disabled,
  not an admin): the line still shows (a disabled product still counts
  toward the kit's composition, price and stock, per `product_kit`'s
  rules), just as plain text instead of a dead link.
* A purchase page per product, `/product/{product}`
  (`kit_catalog.product_page`, `ProductPageController`): image, description,
  price, derived stock (`Product::canBeSold()`/`getStock()`), and its own
  "Add to cart" button — a product bought this way is not part of any kit
  purchase; see the next section for why that's still safe.
* A read-only cart summary, `/cart` (`kit_catalog.cart_page`,
  `CartPageController`, login required —
  `requirements: { _user_is_logged_in: 'TRUE' }`): every kit and product
  line the user has added, each linked back to its own page, with unit
  price, quantity, subtotal and a grand total — `CartManager::getKitItems()`
  and `getProductItems()` combined in one table, so a kit bought as a kit
  and a product bought on its own show up side by side, consistently. Every
  "Add to cart" form (`AddToCartFormBase`) and the grid
  (`KitGridForm::buildForm()`) also show a small, live "Cart: N item(s)"
  link to this page, recomputed on every add.
* **Adding to cart requires being logged in**, everywhere. An anonymous
  visitor gets a "Log in to add to cart" link (back to the same page
  afterwards) instead of a working button. Every form re-checks
  `currentUser()->isAuthenticated()` server-side too, in case that link is
  ever bypassed.
* Clicking "Add to cart" calls `kit_catalog.cart_manager` (`CartManager`),
  which keeps the cart in three plain tables — `kit_catalog_cart_item` (kit
  lines), `kit_catalog_cart_product_item` (product lines, added on their
  own), `kit_catalog_add_request` (idempotency ledger, see "Race safety and
  idempotency") — not content entities: this logic runs inside a database
  lock held as briefly as possible, and an entity save's
  hooks/cache-invalidation overhead has no place in that section. Every one
  of these forms refreshes in place over AJAX and shows a confirmation or
  rejection message, not a full page reload.

## The home page

`/storefront` (`kit_catalog.storefront`, `StorefrontController::build()`) is
set as `system.site:page.front`. The controller itself returns nothing — on
purpose. Everything a visitor sees there is placed the ordinary Drupal way,
as Block Layout config, visibility-restricted to `<front>` (the "pages:"
condition tracks whatever `page.front` is, not a hardcoded path):

* **Storefront hero** — a `block_content` (Custom Block Library, bundle
  `basic`) in Olivero's `hero` region. Editable from
  `/block/{id}` with no code change and no deploy, which is the actual
  point of using a reusable custom block instead of hardcoding the markup
  in the controller or the theme.
* **Kits grid (Product Kit)** (`kit_catalog_grid`, the same block plugin
  described above) in the `content` region — placed, not special-cased;
  it's the same configurable block an admin could also drop into a sidebar
  elsewhere.

This route exists only because `page.front` needs *something* to point at;
the standard core front page (`/node`, the "frontpage" View over promoted
nodes) doesn't fit here since this catalogue's content lives in `kit` and
`product` entities, not nodes — there are no node types defined in this
install at all. No PHP decides what the home page shows; `/admin/structure/block`
does, exactly as it would for any other Drupal page.

## A kit and a bare product share the same stock

`CartManager` has two public entry points, `addKitToCart()` (used by
`KitGridForm` and `KitAddToCartForm`) and `addProductToCart()` (used by
`ProductAddToCartForm`, the product page's button). A kit's lines resolve
to products through `kit_product`; a direct product line *is* a product —
either way, both ultimately draw from the exact same `product.stock`.
`CartManager` treats the two as one shared pool, because the alternative —
tracking "reserved for a kit" and "reserved on its own" as if they were
different resources — would let a kit and a direct sale of one of its
products jointly oversell that product, each unaware of the other's claim.
See `CartManager::reservedUnitsByProduct()`, which sums demand from *both*
tables per product before either entry point decides whether an add fits.
This is exercised for real now, not just between two kits: buying a kit's
last unit of some product directly, on its own purchase page, correctly
blocks that kit from being added afterward (and vice versa) — verified live
in development, see "Race safety and idempotency".

## Race safety and idempotency

Stock is **derived** from `product.stock` (`product_kit`'s `KitCalculator`),
never stored, and several people can click "Add to cart" at the same
moment — on the same kit, on two different kits that happen to share a
product, or on a kit and a bare-product line for one of its products.
Handled naively — read the current stock, check it, then write a new cart
row — that is exactly the read-then-write race `product_kit`'s own README
warns about for the eventual checkout stock decrement; it just shows up one
step earlier, at "add to cart", where it would let two different claims
both believe they reserved the last unit.

`CartManager::reserveAgainstProducts()` (the shared core both
`addKitToCart()` and `addProductToCart()` run through) closes that race the
same way the README prescribes for the decrement: it locks every `product`
row either entry point could draw from first (`SELECT ... FOR UPDATE`,
lowest product ID first, so two adds touching an overlapping set of
products can never deadlock each other by locking in opposite orders),
inside a database transaction. Every concurrent call that touches one of
those products — another add for the same kit/product, a different kit
sharing a product, a bare-product add for a product a kit also uses, or
(later) a checkout trying to sell it — has to wait for that lock before it
can read or write anything derived from them, so the reservation totals
read afterwards can never be invalidated by another in-flight request.
Verified under genuine concurrent load during development, twice: 8
simultaneous kit-only "add to cart" requests against a kit with derived
stock 3 produced exactly 3 successes and 5 rejections every run; later, 4
kit-adds and 4 direct-product-adds fired at once for one shared product
with stock 3 produced exactly 3 successes total (split across both kinds)
and 5 rejections, confirming the two entry points genuinely share one pool,
not two.

It is also idempotent, which is a different problem from the one above: a
double-click, or a network-level retry that resends an already-sent
request, must not reserve stock twice for what the visitor experiences as
one click. `KitGridForm` renders a one-time token alongside each button,
regenerated client-side on every `mousedown`
(`js/kit-catalog-add-to-cart.js`) — *not* server-side on every form rebuild,
because Form API intentionally redisplays a hidden field's last **submitted**
value on a rebuild (standard `#default_value` behaviour), so a server-only
token would never actually change across clicks within one page view. See
the comment on `KitGridForm::buildCard()`'s `token` element for the full
reasoning, including the no-JS fallback (still correct, just coarser: a
fresh page load always gets a fresh token).

`CartManager::claimIdempotencyKey()` inserts that token into
`kit_catalog_add_request`, uniquely keyed, as its first step. A
retried/duplicated submission collides with its own earlier row instead of
running the stock check and reservation a second time — and, importantly,
is answered with **that earlier row's real outcome** (recorded once
computed, via `recordOutcome()`), not a blind "success": a retry of a
rejected click is told it was rejected, never flipped into a false "added".

## A cart page's whole content is one user's data -- cache it accordingly

`CartPageController::view()` sets `'#cache' => ['contexts' => ['user']]` on
every render array it returns, including the "cart is empty" one. This is
not a precaution; it fixes a real bug caught in development, not a
hypothetical: without it, Dynamic Page Cache keys its cached response on
the URL (`/cart`) without knowing the content is per-user, and will happily
serve the first authenticated visitor's cart (and total!) to the next one
who loads the page. Verified both ways: a second test user initially saw
the first test user's two cart lines and their 950.00 total; adding the cache
context made both the "has items" and "empty" responses come back
`x-drupal-dynamic-cache: UNCACHEABLE` instead, and each user has seen only
their own cart since. `KitPageController`/`ProductPageController` were
never at risk of this specific bug -- embedding a `FormBase`
(`AddToCartFormBase`) already makes a page's cacheability "poor" on its
own, for unrelated reasons (a form's build ID/CSRF token has to be fresh
per request) -- but a plain, form-free controller returning per-user data,
like this one, has no such accidental protection and has to declare it.

## Scope: what this is not

Per the request this was built for, **the checkout flow is out of scope**:

* `/cart` is read-only: no "remove", no "edit quantity", no "place order".
  No order entity exists, and nothing decrements `product.stock` —
  `CartManager` only *reserves* against shared stock, it doesn't sell
  anything.
* **Known limitation**: nothing expires an abandoned cart line. Since a
  reservation counts against every other claim on that stock (that's the
  whole point — see above), a kit or product added to a cart that's never
  checked out stays "sold" forever. Worth an expiry sweep (e.g. a
  cron-deleted row older than N) once checkout exists to decide what
  "abandoned" means for this store.
* When checkout is built, follow the concurrency note already in
  `product_kit`'s README ("Selling a kit (not built yet)"): stock must be
  decremented with one atomic conditional `UPDATE` per product inside one
  transaction per kit sale, not a naive read-then-write — the same technique
  `CartManager` already uses for reservation, just at the point of sale
  instead of at add-to-cart.
* The kit price shown here is live (recalculated from current product
  prices on every page load). An actual order must freeze it as a snapshot,
  per the same README.

## Entity access

`product_kit`'s `kit` entity now uses
`Drupal\product_kit\ProductKitAccessControlHandler`, which grants `view` of
an enabled kit to anyone — not just accounts with `administer product kit`
(the original default `EntityAccessControlHandler` denied `view` to
everyone else, which is what the first version of this module had to work
around). That fix matters for anything that checks access on an
already-loaded kit directly, such as a future canonical route's
`_entity_access` requirement.

It does *not* change what `KitGridForm::loadSellableKits()` does, though:
`->accessCheck(FALSE)` there stays, because Drupal's entity query access
filtering (`accessCheck(TRUE)`) only tags the SQL query for a
`hook_query_TAG_alter()` to act on, and `kit` has no such hook — the flag
would be inert either way. `status = 1` in that same query is the real (and
only) filter keeping an unpublished kit out of the grid; see the comment on
that method.

`kit_catalog.routing.yml`'s two routes (`/kit/{kit}`, `/product/{product}`)
are what the access handler fix was for: both use
`requirements: { _entity_access: 'kit.view' }` (respectively `product.view`),
Drupal's standard "check access on the upcasted route parameter" pattern,
which calls the entity's access handler directly — unlike a listing query,
this is not inert. Verified live: an anonymous visitor gets a disabled
product's page as a real `403`, and a disabled line inside an otherwise
enabled kit's "what's in this kit" list renders as plain text instead of a
link to a page that would just deny them (`KitPageController::view()`
checks `$product->access('view')` per line before deciding whether to link
it).

## Why a Block (for the grid), and single-item forms (for the detail pages)

The original brief asked specifically for a block, so the grid is a
`BlockBase` plugin rendering a `FormBase` (`KitGridForm`) — one form holding
every card, since a block can only return one render tree. The kit and
product detail pages are plain routes (`kit_catalog.routing.yml`) to plain
controllers, each embedding a single-item `AddToCartFormBase` subclass
(`KitAddToCartForm`, `ProductAddToCartForm`) — there is only ever one
button on those pages, so there is no need for the grid form's per-card
`#tree` keying; `AddToCartFormBase` is the shared base both detail-page
forms and the grid's per-card logic conceptually agree with, just without
needing to pack more than one button into a `<form>`.

A `FormBase` (not a plain render array) is what lets every "Add to cart"
button submit through core's existing AJAX form plumbing with no custom
route needed for the submission itself (the request posts back to the
page's own URL, not `/system/ajax` — that path is a dead end for a `#ajax`
button; it posts to the element's `#ajax['url']`, which defaults to the
form's own `#action`). Every one of these forms reports (implicitly, via
how `KitGridBlock::getCacheMaxAge()` is set, and simply by never being
rendered through a cached block for the detail pages) that it must not be
render-cached: it embeds a form (a fresh CSRF-bound build ID matters per
request) and reflects per-user, per-authentication-state content. On an
authenticated page viewing the *block* specifically, this makes Drupal
defer it to a BigPipe placeholder rather than inline markup — expected, and
transparent to a real browser (the detail-page controllers are not blocks,
so they render inline immediately; no BigPipe placeholder to decode when
testing them, unlike the grid).

## Automated tests

`tests/src/Kernel/CartManagerTest.php` is the permanent, repeatable form of
what had, through this module's whole development, been verified by hand
against the dev database each time — every number quoted above ("8
simultaneous requests ... exactly 3 succeeded", etc.) came from a real but
throwaway run; this suite is what's left to re-run that proof on demand,
in CI, or after a future change, without rebuilding the harness from
scratch. It is also the first automated test coverage in this codebase —
neither this module nor `product_kit` had any before (`phpunit` wasn't
even installed; this module's Fase 5 added `drupal/core-dev` as a
`require-dev` dependency specifically to get it).

Two different techniques cover two different claims, deliberately:

* Plain sequential `KernelTestBase` assertions cover the *arithmetic* --
  `reserveAgainstProducts()`'s formula, idempotent replay, the Fase 0 and
  Fase 2 regressions (two kits sharing a product; a kit and a bare line
  sharing a product), `unavailable` vs `insufficient_stock` vs `added`. A
  real race still serializes into *some* order once the lock is held, so a
  sequential test exercising the same code path still catches a regression
  in the formula itself.
* `testForUpdateLockBlocksSecondSession()` covers the one thing a
  sequential test structurally cannot: that the `FOR UPDATE` lock the rest
  of the design leans on genuinely blocks a second, independent database
  session (not a second PHP reference to the same one) from reading the
  same row until the first commits. Two connections are opened against the
  *same* test-prefixed tables (`Database::addConnectionInfo()` with a
  second target), and a short `innodb_lock_wait_timeout` on the second one
  turns "was this session blocked" into something a test can assert on
  without hanging.

To run it (DDEV, from the project root):

```sh
cp web/core/phpunit.xml.dist web/core/phpunit.xml   # once; already done here
# Edit web/core/phpunit.xml's SIMPLETEST_DB to a real, reachable database --
# Kernel tests create their own randomly-prefixed tables and drop them
# again after, so pointing this at the same database DDEV already uses for
# the site is the normal, safe way to run this locally (it was used
# throughout this module's own development: mysql://db:db@db/db#kittest).
ddev exec "cd web/core && ../../vendor/bin/phpunit -c phpunit.xml ../modules/custom/kit_catalog/tests/src/Kernel/CartManagerTest.php"
```
