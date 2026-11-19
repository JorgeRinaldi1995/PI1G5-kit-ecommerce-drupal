# Ecommerce Barrio

Mobile-first storefront theme for this project, generated from Barrio
(`drupal/bootstrap_barrio`, Bootstrap 5) via its `scripts/create_subtheme.sh`
starterkit. Bootstrap's CSS/JS load from a CDN (`ecommerce_barrio/bootstrap_cdn`
library) — no local Bootstrap build step.

Visually, it implements the "casa.moderna" home-decor storefront design
(`Home — desktop@1x.png` / `Home — mobile@1x.png`, supplied by the user
2026-11-04): announcement bar, logo + search + account/wishlist/cart header,
an always-visible category bar, a two-panel hero, a "Mais vendidos" product
slider/grid, a newsletter band and a four-column footer.

## What's here vs. what's config

This theme supplies the **look** (`css/colors.css`, `css/style.css`,
`templates/`) plus two small block plugins that live in `kit_catalog`
(cart link, since it's domain logic — item counts — not presentation).
Everything else on the home page is **Block Layout configuration**, not
code: the hero, hero promo panel, newsletter and every footer column are
plain Custom Block Library (`block_content`, bundle `basic`) entities,
editable at `/block/{id}` with no deploy. See `kit_catalog`'s README, "The
home page", for the full rationale (`/storefront` route, visibility
conditions, etc.).

To find/edit them: `/admin/structure/block`, theme `Ecommerce Barrio`. The
home-page-only ones (hero, hero promo, kit grid, newsletter) are
visibility-restricted to `<front>`; the header, category nav, cart link and
footer show on every page.

## Palette

`css/colors.css` defines the whole palette as CSS custom properties on
`:root` (`--ecb-bg`, `--ecb-primary`, `--ecb-text`, `--ecb-black`, etc.) —
white/black with one blue accent, matching the design files. `css/style.css`
(components) reuses those variables rather than repeating literal colors,
so re-theming is editing one file.

**Barrio's own CSS assumes the classic look it ships with** (a black
footer, white-on-dark footer text, a dark navbar) — `colors.css` has to
actively override `.site-footer .content`/`.content a` (two classes, more
specific than a bare `.site-footer` rule) back to dark text, or Barrio's
own near-white `rgba()` footer text wins and renders unreadable on this
theme's light footer. If a future color tweak looks like text has gone
invisible, check here before assuming it's a new bug.

## Layout gotcha worth remembering: Bootstrap's own `flex-wrap: wrap`

Three different pieces of markup this theme styles also carry a Bootstrap
utility class (`.row` on `region-featured-top`/`region-featured-bottom-first`,
`.nav` on a Barrio menu block's `<ul>`) that sets `display: flex;
flex-wrap: wrap;` by default. A same-or-higher-specificity override that
sets `display: flex; flex-direction: row;` but never touches `flex-wrap`
does **not** cancel Bootstrap's `wrap` — that property was never
re-declared, so it stays active and two 50%-basis children silently wrap
onto separate lines instead of sitting side by side, which reads exactly
like a `flex-direction: column` bug even though direction is correctly
`row`. Hit twice while building this (the hero panels, then the category
pills) before the pattern was obvious; every multi-item flex container
here now sets `flex-wrap: nowrap` (or an intentional `wrap`) explicitly,
never left implicit.

## Region wrapper gotcha: `region--nowrap.html.twig`

Barrio's `hook_preprocess_region()` puts `header`, `primary_menu`,
`content`, `breadcrumb`, `highlighted` and both sidebars on a "nowrap"
list that swaps in `region--nowrap.html.twig` — which prints the region's
blocks with **no** wrapping element at all (no `<section class="region
region-X">`, no `.row`). Every *other* region (`top_header`,
`featured_top`, `featured_bottom_first`, the footer columns) gets the
normal wrapper. A CSS rule targeting `.region-header` or
`.region-primary-menu` will silently match nothing — confirmed by reading
`BootstrapBarrioHooks::preprocessRegion()`, not guessed. In practice this
is harmless for `header` (its blocks end up as plain flex-item siblings in
`#navbar-main .container` with nothing to flatten) but worth knowing
before chasing a layout rule that "should" apply and doesn't.

## Menu block markup: `.nav`/`.nav-item`/`.nav-link`, not `.menu`/`.menu-item`

Barrio's own menu block template (not core's generic `menu.html.twig`)
renders `<ul class="clearfix nav"><li class="nav-item"><a|span
class="nav-link">` — the category bar's CSS targets those classes, scoped
under `.categories-nav`, not the `.menu`/`.menu-item` a plain Drupal menu
normally gets. A `route:<nolink>` link (every category item — see
`kit_catalog`'s README) renders as a `<span class="nav-link">`, not an
`<a>`, hence styling both.

## The product "slider"

No carousel library, no JS framework: `.kit-catalog-grid` (defined by
`kit_catalog`, unstyled-for-layout by design) gets a CSS-only horizontal
`scroll-snap-type: x` treatment on the front page, at every width — the
base (mobile-first) rule only grows the card width at wider breakpoints
(`calc((100% - 3 * 1rem) / 4.3)` above 900px), it never switches `display`,
`overflow-x` or `scroll-snap-type` away from the slider. Scoped with
`body.path-frontpage` so the same block placed elsewhere (e.g. a sidebar)
keeps the plain grid `kit_catalog`'s own CSS gives it by default. The
prev/next arrow buttons (`KitGridForm`'s `kit-catalog-grid__nav-btn`) are
real `<button>` elements; `js/global.js`'s one Drupal behavior scrolls the
grid by one card's width on click (`Element.scrollBy()`), a progressive
enhancement over the swipe gesture the CSS already supports on its own.
This was a deliberate choice over a React carousel component (discussed
and set aside as unnecessary complexity) and over a JS carousel library
(one more dependency for the same visual result).

An earlier version of this file switched `.kit-catalog-grid` to a static
4-column `display: grid` above 900px, which matched neither mockup
(`Home — desktop@1x.png` shows four full cards plus a fifth clipped at the
edge, same slider affordance as mobile) and left the prev/next buttons
inert at desktop widths (nothing left to scroll) — found and fixed
2026-11-19 by keeping the slider active everywhere and only resizing the
card.

### Position dots (replace the native scrollbar)

The browser's own horizontal scrollbar is hidden (`scrollbar-width: none`
+ the `-webkit-scrollbar`/`-ms-overflow-style` equivalents, scoped the
same `body.path-frontpage .kit-catalog-grid` way as the rest of this
section) in favour of a row of dots, one per *reachable scroll position*
— **not** one per kit. `KitGridForm` renders only an empty
`.kit-catalog-grid__dots` container (`data-grid-target` pointing at the
wrapper's id, same attribute the arrow buttons use); `js/global.js`
populates it at runtime and recomputes on resize, because how many
distinct positions exist depends on how many cards fit in view at once,
which only the browser knows.

This was a real bug, not just a design choice avoided ahead of time: a
first pass rendered one dot per kit server-side. At this catalogue's
current size (5 kits), desktop shows ~4.3 cards at once, which leaves
barely one card's width of scrollable range — three of the five dots were
permanently unreachable (clicking them clamped to the same max-scroll
position, and the active-dot sync could never select them either). Found
by driving a real click through Chrome DevTools Protocol and checking
`scrollLeft`/the active class afterward, not by reading the markup. Fixed
by computing `pageCount = min(cardCount, ceil(maxScroll / cardStep) + 1)`
in JS and (re)building that many `<button>`s, so the dot count always
matches what's actually reachable — 2 dots on a typical desktop width for
this catalogue's current size, 5 on mobile (where only one card is
visible at a time, so the full per-kit range is reachable).

## Regions used on the home page

| Region | Content |
| --- | --- |
| `top_header` | **Announcement bar** (`block_content`, "Storefront announcement bar") — black full-width strip. Shows on every page. |
| `header` | Logo (template-overridden wordmark, see below) + **header actions** (`block_content`, "Storefront header actions": decorative search input, a real `/user` account link, a decorative wishlist button) + **cart link** (`kit_catalog_cart_link` block plugin, real). All three sit as plain siblings in one flex row (see the `region--nowrap` note above), reflowing per breakpoint in CSS. Shows on every page. |
| `primary_menu` | **Category bar** — a dedicated menu (`casa-moderna-categories`, 8 placeholder links, see below), always visible (not collapsed behind the hamburger). Shows on every page. |
| `featured_top` | **Hero**: text panel (`block_content`, "Storefront hero") + promo panel (`block_content`, "Storefront hero promo"). DOM order is text-then-promo (meaningful reading order without CSS); visual order flips per breakpoint via `order` — promo image first on mobile, text first on desktop, matching the two design files exactly. |
| `content` | The kit grid (`kit_catalog_grid` block plugin, heading "Mais vendidos") — `StorefrontController` itself returns nothing; see `kit_catalog`'s README. |
| `featured_bottom_first` | **Newsletter** signup band (`block_content`, "Storefront newsletter") — static markup, `onsubmit="return false;"`, no backend: email capture is explicitly not functional yet. |
| `footer_first` / `footer_second` / `footer_third` / `footer_fourth` | Four footer columns: brand/social, Loja, Ajuda, Institucional — all `block_content` entities. Most of their links are `href="#"` (placeholder; see below), a few are real (`/storefront`, `/cart`). |
| `footer_fifth` | **Footer bottom bar** (`block_content`, "Storefront footer bottom"): copyright + payment methods text. Replaces Barrio's default "Powered by Drupal" block. |

The generic page title block is hidden on the front page only (visibility
condition, negated `<front>`) — the hero already carries an `<h1>`, a
second one reading "Home" above it would be redundant.

## What's decorative, and why

Several elements from the design files have no feature behind them yet in
`kit_catalog`/`product_kit`'s data model. Rather than fake data or silently
drop them, each is implemented as honest, inert chrome, consistently with
how this module already treated the newsletter form (see `kit_catalog`'s
README):

* **Search bar** — a plain `<input>`, `onsubmit="return false;"`. No search
  index exists over `kit`/`product` entities.
* **Wishlist heart** (header icon and the one on every product card) —
  `tabindex="-1"`, no click handler. No wishlist feature exists.
* **Hamburger button** (mobile header) — visible for fidelity to the
  mobile design file, but has no `data-bs-toggle` wired to it. There is
  nothing left for it to collapse: the category bar is always visible by
  design (see the `region--nowrap` note) and the account/wishlist/cart
  icons already live in the always-visible header row, not behind a menu.
* **Category links** (Novidades, Sala, Quarto, …) — `route:<nolink>`
  menu links (menu `casa-moderna-categories`), which Drupal renders as
  plain non-clickable `<span class="nav-link">` text, not dead `<a>`
  links to pages that don't exist. There is no category/taxonomy concept
  in `product_kit`'s data model yet; wiring these up is a data-model
  question for that module, not a theming one.
* **Most footer links** — `href="#"` placeholders (Central de atendimento,
  Trocas e devoluções, Sobre nós, …); none of those pages exist in this
  demo store.
* **Product card badges** ("Novo") — the *only* badge actually shown,
  because it's the only one derivable from real data
  (`kit.created`, roughly the last 30 days — see `KitGridForm::buildBadges()`).
  "Mais vendido" and a discount percentage are intentionally never shown:
  there is no sales-count field and no promo-price field to back either
  one honestly.
* **Product card photo placeholder** — `kit` has no image field (only
  `product` does; see `product_kit`'s README), so each card shows a
  labelled color block ("[Foto do produto]", cycling through a small
  palette) instead of pretending there's a photo. Same approach as the
  hero promo panel's "[Imagem da campanha]".
* **Bracketed copy** (`R$ [VALOR]`, `[X]%`, `[00.000.000/0000-00]`) is
  copied verbatim from the design files, which left them as fill-in-later
  placeholders rather than real numbers. Edit the corresponding
  `block_content` entity once real values are known.

## Demo catalogue content

The only kit that existed before the redesign, "Kit Desenvolvedor" (a
leftover test fixture from `kit_catalog`'s earlier development phases),
was unpublished when the redesign landed (2026-11-04) since it clashed
with the home-decor brand the design files establish, then **republished**
two weeks later (2026-11-18) once the catalogue direction shifted again
(see below) — it already had a coherent product lineup (teclado mecânico,
mouse sem fio, headset, mousepad, suporte para notebook), just needed
re-enabling, not rebuilding.

**Home-decor items** (8 products, each with its own single-product kit so
it shows up individually in the grid — a kit, not the product directly,
is what `KitGridForm::loadSellableKits()` queries, same reasoning
`product_kit`'s README gives): Caneca de cerâmica, Manta de algodão, Vela
aromática, Vaso de vidro, Luminária de mesa, Jogo de toalhas, Difusor de
aromas, Almofada decorativa.

**Profession starter kits** (2026-11-18, replacing two generic bundle
kits — "Kit Spa em Casa" and "Kit Sala Aconchegante" — the user asked to
swap out for this instead): each a real multi-product bundle, not a
single-item wrapper, pairing a profession with the tools a beginner would
need —

| Kit | Lines |
| --- | --- |
| Kit Eletricista | alicate universal, jogo de chaves de fenda, multímetro digital, fita isolante ×2, lanterna de cabeça |
| Kit Cabeleireira / Kit Cabeleireiro | tesoura de corte profissional, secador de cabelo profissional, escova de cabelo, pente profissional ×2, borrifador de água (same product lineup, two kits — both forms were asked for by name) |
| Kit Desenvolvedor | suporte para notebook, teclado mecânico, mouse sem fio, headset, mousepad |
| Kit Encanador | chave de grifo, fita veda rosca ×2, chave inglesa, alicate de pressão, lanterna de cabeça |

`Lanterna de cabeça` is deliberately shared between Kit Eletricista and
Kit Encanador (same product, two kits) — same "exercise real cross-kit
stock sharing, not just decorative variety" reasoning as the home-decor
bundles that preceded this set. None of these profession-tool products
got their own solo kit — unlike the home-decor items, the point here is
the curated starter kit itself, not each tool sold loose.

**Known mismatch, partially fixed**: the site name/wordmark were renamed
to "kit.ecommerce" and the hero + promo panel copy were rewritten for
profession starter kits (2026-11-18, see below). The **category nav**
(`casa-moderna-categories`: Novidades/Sala/Quarto/Cozinha/Banheiro/
Decoração/Iluminação/Ofertas) is still home-decor-flavored and wasn't
touched — nobody's asked for it yet, and inert `route:<nolink>` category
links aren't backed by any real taxonomy on product_kit anyway (see
"What's decorative, and why" above). Worth a look if this catalogue
direction is the one that sticks.

All of this is ordinary published content — edit, reprice, restock or
unpublish any of it the same way as anything else at
`/admin/content/product` / `/admin/content/kit`.

## Wordmark

`templates/block/block--system-branding-block.html.twig` overrides the
default branding block to render `kit<span class="site-branding__dot">.
</span>ecommerce` — plain text can't color one character differently
without its own markup, and `system.site:name` was set to literally
`kit.ecommerce` to match (so page titles, the admin toolbar, etc. agree
with the header, not just the logo). Originally this rendered
`casa.moderna` to match the home-decor design files; renamed 2026-11-18
when the catalogue direction moved to profession starter kits — site
identity now matches the kits, the category nav still doesn't (see
"Demo catalogue content" above).

The hero block (`block_content` id 1, "Storefront hero") and the hero
promo panel (id 7, "Storefront hero promo") were rewritten the same day
for the same reason — "Sua casa, mais leve e cheia de luz" / "Coleção de
verão" / a fake "[X]% off" badge replaced with profession-kit copy
("Tudo que você precisa pra começar na profissão", "Kit completo pra sua
primeira semana de trabalho"). **Gotcha hit while doing this**: both
blocks' `body` field must keep `format: full_html`, not `basic_html` —
`full_html` is what every other block on this page already uses, and
it's the only format whose `filter_html` config allows `class`, `<div>`,
`<h1>`, `<span>` (`basic_html`'s allowed-tags list has none of those).
Saving with `basic_html` silently strips the markup and the hero
visually collapses to default (unclassed) paragraph text — caught only
by screenshotting, not by a markup/config check.

## Cart link block

`Drupal\kit_catalog\Plugin\Block\CartLinkBlock` (`kit_catalog_cart_link`).
Anonymous visitors get a plain bag-icon link to the login page (adding to
cart requires authentication everywhere in this module); authenticated
visitors get the same bag icon with `CartManager::getItemCount()` as a
small badge — shown only when the count is above zero, so an empty cart
looks exactly like the design files' icon-only state. `getCacheMaxAge():
0`, same reasoning as `KitGridBlock` — it's per-user and must never be
render-cached. On an authenticated page this makes Drupal defer it to a
BigPipe placeholder (verified live: the badge still shows the correct
count once the placeholder resolves); a real browser is transparent to
this, `curl` just has to look for the escaped payload instead of inline
markup.

**Known limitation, matching the rest of this module's scope**: the count
is accurate as of each full page load/navigation, not live without a
reload — the AJAX-refreshed "Carrinho: N item(s)" link next to each "Add to
cart" button (`AddToCartFormBase`/`KitGridForm`) already covers the
real-time case for the form a visitor just used; wiring the header icon
into that same AJAX response would need its own replace command and
wasn't asked for.

## Language

Every user-facing string in `kit_catalog`'s storefront-facing code
(`KitGridForm`, `AddToCartFormBase`, the three page controllers,
`CartLinkBlock`) was translated to Portuguese in source, matching the
design files and the rest of this store's content (product/kit names were
already Portuguese). Admin-only strings (block configuration forms,
`product_kit`'s admin UI) were left in English — out of scope for a
storefront-facing redesign.
