# Product Kit

Custom content entities for a product catalogue with bundles ("kits").

## Domain model

```
PRODUCT ──< KIT_PRODUCT >── KIT
 price       kit_id           name
 stock       product_id       description
 ...         quantity         status
```

Products and kits are many-to-many; `kit_product` is the association, and
`quantity` lives there because "how many units of this product" depends on the
kit (a HDMI cable can be ×1 in one kit and ×3 in another).

| Piece of data | Stored? | Where it comes from |
| --- | --- | --- |
| `product.price` | yes | the product |
| `product.stock` | yes | the product |
| `kit.name`, `kit.description`, `kit.status` | yes | the kit |
| `kit_product.quantity` | yes | the relationship |
| **kit price** | **no** | Σ `product.price × kit_product.quantity` |
| **kit stock** | **no** | min over lines of ⌊`product.stock ÷ kit_product.quantity`⌋ |

Kit price and stock are **derived on every read**, never persisted. There is no
column, no cache table and nothing to keep in sync, so a kit can never disagree
with its products. (Earlier versions stored `kit.price` and a `kit_stock`
entity; both were removed by `product_kit_update_10003`.)

Example: Fita ×2 (stock 20 → 10 kits), Conector ×3 (stock 15 → 5 kits),
Alicate ×1 (stock 8 → 8 kits) → kit stock **5**.

The relationship is required **one way only**: a kit always has at least one
product (empty kits are not a valid, savable state), but a product does not
need to be in any kit — it can sit outside every bundle until it is added to
one.

When the cart and orders are built, the kit price must be calculated at
purchase time and **frozen as a snapshot on the order item**; from then on it
must not follow the products' current prices.

## Code organization

Each entity's interface lives next to its class under `src/Entity/`, in the
`Drupal\product_kit\Entity` namespace (the Drupal Commerce convention). The rest
is under `src/` in `Drupal\product_kit`.

| Class | Role |
| --- | --- |
| `Entity\Product`, `Entity\Kit`, `Entity\KitProduct` | The three content entities. |
| `KitCalculator` (`product_kit.calculator`) | Derives kit price and stock, for a saved kit or for a plain list of `{product, quantity}` lines (used for the live preview in the kit form). |
| `KitCompositionManager` (`product_kit.composition_manager`) | Owns the product ↔ kit relationship: validating/merging rows, syncing lines, cascading deletes, guarding the "kit needs ≥ 1 product" rule. Every entry point goes through it. |
| `PriceFormatter` (`product_kit.price_formatter`) | Minor units ↔ decimal notation. |
| `ProductKitAccessControlHandler` | `view` access for `kit` and `product` — public when enabled, admin-permission-gated otherwise; every other operation is unchanged (admin-only). |
| `Form\CompositionFormBase`, `Form\KitForm`, `Form\ProductForm` | Entity forms with an inline AJAX list of lines (required on the kit form, optional on the product form). |
| `Form\ProductDeleteForm`, `Form\KitProductDeleteForm` | Delete confirmations that explain and enforce the rule. |
| `Form\KitProductForm` | Add a single line / change a line's quantity. |
| `KitProductStorageSchema` | Adds `UNIQUE(kit, product)`. |
| `Plugin\Validation\Constraint\UniqueKitProduct`, `KitProductQuantityInStock` | `kit_product` constraints. |
| `Field\LineItemList`, `Field\KitDerivedItemList` | Computed fields (`items`, kit `price` / `stock`). |
| `Controller\ProductKitOverviewController` | Admin landing page (`/admin/product-kit`). |
| `Drush\Commands\ProductKitCommands` | `drush product-kit:audit`. |

## Entities

| Entity | Table | Purpose |
| --- | --- | --- |
| `product` | `product` | A sellable item, in any number of kits (including none). |
| `kit` | `kit` | A bundle of one or more products. |
| `kit_product` | `kit_product` | One product inside one kit, with a quantity. |

### product

| Field | Type | Notes |
| --- | --- | --- |
| `id`, `uuid` | serial, uuid | |
| `name` | string(255) | Label, required. |
| `status` | boolean | "Enabled" (`EntityPublishedInterface`). |
| `price` | integer (64-bit) | Unit price in **minor units** (`1000` = `10.00`); see "Prices". |
| `stock` | integer ≥ 0 | Units in inventory. Not called `quantity`, which belongs to `kit_product`. |
| `description` | text_long | |
| `image` | image | Managed image field. |
| `items` | computed entity_reference | The product's `kit_product` lines (which kits it is in). |
| `uid`, `created`, `changed` | — | |

### kit

| Field | Type | Notes |
| --- | --- | --- |
| `id`, `uuid` | serial, uuid | |
| `name` | string(255) | Label, required. |
| `status` | boolean | "Enabled". |
| `description` | text_long | |
| `price` | **computed** integer | Derived, read-only. Not a column. |
| `stock` | **computed** integer | Derived, read-only. Not a column. |
| `items` | computed entity_reference | The kit's `kit_product` lines. |
| `uid`, `created`, `changed` | — | |

`Kit::getPrice()`, `Kit::getStock()` and `Kit::canBeSold()` (enabled **and**
stock > 0) are the API; the computed fields are the same values for display and
Views-style consumers. `Product::canBeSold()` mirrors it (enabled and
stock > 0) for a product sold on its own — a product does not need to be in
a kit to be sellable in its own right, same as it never needed one to exist.

### kit_product

| Field | Type | Notes |
| --- | --- | --- |
| `id` | serial | Surrogate key (content entities need one scalar ID). |
| `kit` | entity_reference → kit | Required. |
| `product` | entity_reference → product | Required. |
| `quantity` | integer ≥ 1 | Units of the product in one kit. |

## Business rules and how they are enforced

### A kit always has at least one product; a product needs no kit

The rule runs one way. A kit is never left without a product, on any path; a
product not being in any kit is normal and unguarded.

* **Kit form** (`KitForm`): validation rejects a submit with no product rows,
  whether creating a kit or editing one down to zero. Kit and lines are saved
  in one DB transaction.
* **Product form** (`ProductForm`): removing the product from a kit is fine
  *unless* that kit has no other product — then it is a validation error
  naming the kit(s) that would be emptied (`KitCompositionManager::getEmptiedKits()`).
  Placing the product in a kit is otherwise optional: pick existing kits,
  and/or type the name of a **new kit to create around the product** — also
  how the first product / first kit of a catalogue can come to exist.
* **`kit_product` line delete** (`KitProductDeleteForm`, and
  `hook_ENTITY_TYPE_predelete` for Drush/API callers that skip the form):
  refused if the line is the last product of its kit.
* **Deleting a product** (`ProductDeleteForm`): allowed freely when it does not
  empty any kit. When it would, the form lists those kits and requires ticking
  "Also delete these kits" (`KitCompositionManager::deleteProduct($product, TRUE)`);
  a bare `$product->delete()` throws instead.
* **Deleting a kit** has no guard in the other direction — a product does not
  need a kit, so removing it from one, even its only one, is never blocked.
  `KitDeleteForm` is unnecessary and was removed; kit deletion uses the plain
  core delete form and cascades its lines
  (`hook_ENTITY_TYPE_predelete` → `KitCompositionManager::preDeleteKit()`).
* An empty kit is never actually reachable in practice — `KitCalculator` would
  simply treat it as price/stock 0 if one existed, but the guards above mean
  it doesn't.
* Existing data that predates these rules or came from a bypassed API call is
  not fixed automatically: `drush product-kit:audit` (`pk:audit`) lists
  products with no kit (normal) and kits with no product (should not happen;
  worth investigating) — purely informational, always exits successfully.

### One line per product per kit; several units allowed by quantity

* `UniqueKitProduct` constraint plus a real `UNIQUE(kit, product)` key
  (`KitProductStorageSchema`).
* In the kit form the same product may be entered **on several rows or with a
  quantity above 1**. Rows for the same product are merged into one line whose
  quantity is the sum, and the admin is told so.

### Quantity cannot exceed the product's stock

A line may not ask for more units than the product has in stock — checked on
the kit form, the product form (against the stock being submitted) and the
line form (`KitProductQuantityInStock`). One unit is **always** allowed, so a
product with no stock can still be placed in a kit; that kit's derived stock is
simply 0. Only new or changed lines are judged: stock moves on its own, and an
untouched line whose product later ran low must not break unrelated edits.

### Prices

Prices are stored as integers in minor units, in 64-bit columns (a kit price is
a sum of `quantity × unit price`, which a 32-bit column would overflow at about
21.4 million). `product_kit.price_formatter` (`PriceFormatter`) is the one
place that converts between that and the decimal notation people use:

* `format(1050)` → `"10.50"`, and `parse("10,50")` → `1050`. Both use integer
  arithmetic only. `parse()` accepts `.` or `,` as the decimal mark and up to
  two decimals, and **rejects** thousands separators (`"1,000"` is ambiguous,
  and guessing would misprice a product).
* The `product_kit_price` **widget** lets the admin type `10.50` into an
  integer field; the `product_kit_price` **formatter** displays it as `10.50`.
* List builders and forms use the same service, so no screen divides by 100.

### Selling a kit (not built yet)

`Kit::canBeSold()` is the check. There is no cart/checkout here yet, so nothing
decrements `product.stock`. When that is built, the naive
read-then-write-via-Entity-API pattern is **not** safe: two concurrent sales of
kits that share a product can both read the same stock before either writes.
Use one atomic conditional `UPDATE` per product, inside one transaction for the
whole kit sale:

```php
$affected = \Drupal::database()->update('product')
  ->expression('stock', 'stock - :qty', [':qty' => $line_quantity * $kits_sold])
  ->condition('id', $product_id)
  ->condition('stock', $line_quantity * $kits_sold, '>=')
  ->execute();
// 0 rows: not enough stock — the database resolved the race.
```

Reset the `product` / `kit_product` entity caches afterwards. Because kit stock
is derived, no kit needs updating after a decrement.

## Admin UI

`/admin/product-kit` is the landing page; lists and forms live under
`/admin/content/{product,kit,kit-line}`. `Kit` shows its calculated price and
stock in the list and, live, in the form (**Update totals** refreshes the
preview from the rows as typed).

## Deploying

Schema changes ship as `hook_update_N` in `product_kit.install`; run
`drush updb` on every environment, then `drush cr`.

* `10002` widens `product.price` to 64-bit.
* `10003` removes the `kit_stock` entity type and the stored `kit.price` column.
* `10004` merges any duplicate lines, then adds `UNIQUE(kit, product)`.

After deploying, run `drush product-kit:audit` once.

## Known limitations

* Prices are single-currency, shown as a bare decimal: no symbol or locale
  separators. Add a `currency_code` field (and make `PriceFormatter` aware of
  it) if that changes.
* Line prices are not snapshotted: changing a product's price re-prices every
  kit that contains it. That is intended for the catalogue; orders must
  snapshot (see above).
* The list pages compute each kit's price/stock per row (a few queries per
  kit); fine for admin-sized catalogues, batch it if that changes.
* No automated tests of its own yet. `phpunit`/`drupal/core-dev` *is* now
  installed project-wide (added for `kit_catalog`'s test suite — see that
  module's README, "Automated tests") and could cover this module's rules
  (the one-way kit/product relationship, stock derivation, price parsing)
  the same way.

## Permissions

A single permission, `administer product kit`, governs create/update/delete
and settings for all three entity types. `view` of an enabled `kit` or
`product` is public — `ProductKitAccessControlHandler` grants it to anyone,
admin permission or not; `kit_product` (the association) has no public
access, it stays admin-only. See that class's docblock for why a listing
query (`->accessCheck(TRUE)`) does not get the same treatment automatically.
