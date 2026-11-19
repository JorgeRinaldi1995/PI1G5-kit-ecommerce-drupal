# Kit Assistant

"Cleiton" — an OpenAI-backed chat assistant for the storefront. A visitor
talks to it about which professional starter kit they need, Cleiton looks
up the real catalogue and (for logged-in visitors) adds kits/products to
their cart, through the same `product_kit`/`kit_catalog` services the rest
of the site uses — nothing here is a second source of truth for prices,
stock or cart contents.

## Setting it up

An OpenAI API key is required and is **never** stored in config (config is
exportable, and in this project intended to be committed). It is read from:

1. The `OPENAI_API_KEY` environment variable, or
2. `$settings['kit_assistant_openai_api_key']` in `settings.local.php`.

On DDEV, the key lives in `.ddev/config.local.yaml` (already gitignored by
DDEV itself — see `.ddev/.gitignore`'s `/config.*.local.y*ml` pattern, not
something this module added):

```yaml
#ddev-generated
web_environment:
  - OPENAI_API_KEY=sk-...
```

Then `ddev restart`. Without a key configured, every chat turn replies with
a friendly "indisponível no momento" message instead of erroring — see
`OpenAiClient::isConfigured()`/the settings form's warning banner at
`/admin/config/content/kit-assistant`.

Settings (model, moderation model, max tool-call rounds per message,
conversation turns remembered, enable/disable) live at
`/admin/config/content/kit-assistant`, `administer site configuration`
permission. Defaults: `gpt-4o-mini`, `omni-moderation-latest`, 4 rounds, 8
turns.

## Architecture

| Piece | Role |
| --- | --- |
| `OpenAiClient` (`kit_assistant.openai_client`) | Thin Guzzle wrapper around the Chat Completions and Moderation REST endpoints. Reads the API key (see above), nothing else touches it. |
| `CatalogTools` (`kit_assistant.catalog_tools`) | Defines the 5 tools the model may call and executes them, entirely through `product_kit`/`kit_catalog`'s own entities and `CartManager` — never raw SQL. |
| `ConversationStore` (`kit_assistant.conversation_store`) | Per-visitor turn history in `PrivateTempStore` (session-scoped) — not a content entity or custom table, and not persisted past the session; see its docblock. |
| `Assistant` (`kit_assistant.assistant`) | Orchestrates one turn: moderate in → system prompt + history + tools → the tool-call loop → moderate out. All of this module's guardrails live here, in that order — see its docblock. |
| `ChatController` | Three JSON endpoints: `GET /kit-assistant/token`, `POST /kit-assistant/message`, `POST /kit-assistant/reset`. CSRF (manual header check, see below), flood control (30 messages/hour per uid-or-IP), input length cap (1000 chars). |
| `ChatWidgetBlock` (`kit_assistant_chat_widget`) | Renders the widget shell + drupalSettings (URLs, login state). Placed site-wide in `ecommerce_barrio`'s `sidebar_second` region (unused by the theme otherwise) — the region doesn't matter visually since the widget is `position: fixed`. |
| `js/chat.js` | Vanilla JS Drupal behavior: bubble toggle, fetch-based send/receive, client-side greeting, cart-badge live update. No framework, matching `ecommerce_barrio`'s existing choice for the product slider. |

## Why `CatalogTools` is where it is, not in `kit_catalog`

The five tools (`list_available_kits`, `get_kit_details`, `add_kit_to_cart`,
`add_product_to_cart`, `get_cart_summary`) are OpenAI-specific function-call
*schemas* wrapped around calls into `kit_catalog.cart_manager` and
`product_kit`'s entities. They add no new business rules of their own —
every validation (kit/product exists, is published, login required) mirrors
a rule that already exists in those modules; `CatalogTools` only translates
between "what the model is allowed to ask for" and "what those services
already enforce". Kept in this module because nothing about tool-calling or
OpenAI's schema format belongs in `kit_catalog`.

## Guardrails

Per the request this was built for — "validação de input, output, e deve
responder apenas a perguntas pertinentes ao domínio" — four layers, all in
`Assistant::respond()` and `OpenAiClient`:

1. **Input moderation.** Every visitor message is sent to OpenAI's
   Moderation endpoint *before* it ever reaches the main chat model. A
   flagged message never gets a model call at all.
2. **Domain restriction via system prompt.** There is no separate
   classifier step — the system prompt (`Assistant::systemPrompt()`)
   is the one and only place the domain boundary is defined ("only this
   store's professional starter kits; recuse educadamente for anything
   else"), together with an explicit instruction not to follow a
   visitor's attempt to override it (prompt-injection resistance by
   instruction, not by a second model pass). This is a deliberate,
   documented trade-off: a prompt is not a hard guarantee the way a tool
   boundary is (see point 3) — worth revisiting with a dedicated
   classifier call if this ever needs to be airtight rather than
   "reasonably robust and cheap".
3. **Tool-calling is structurally bounded.** The model can only ever call
   the five tools `CatalogTools` defines — read the catalogue, read/write
   *one visitor's own* cart. There is no tool that reaches anything else
   (no arbitrary query, no other user's data, no site configuration), so
   "agentic" here cannot mean more than that regardless of what a
   malicious prompt asks for. `max_tool_rounds` (default 4) caps a
   pathological tool-call loop's worst-case cost per message.
4. **Output moderation.** The model's final reply is checked the same way
   before ever being returned to the visitor.

**Moderation failure vs. moderation flagged are deliberately different
messages.** `OpenAiClient::moderate()` *throws* `OpenAiException` on a
transport/auth failure (unreachable API, bad key) rather than silently
treating it as "flagged" — if it did, every single turn would show the
same "prefiro não responder a isso" a genuinely inappropriate message
gets, and an admin debugging a bad API key would reasonably conclude the
content guardrail itself was broken. A moderation failure instead
propagates up to `ChatController`'s existing `catch (OpenAiException $e)`
and shows "indisponível no momento" — the same message a failed chat
call gets. Both still block the turn; only the visitor-facing text
differs, and only to be honest about which thing actually failed.

**Grounding, not just restriction.** The system prompt also instructs the
model to never state a kit/product/price it didn't get from
`list_available_kits`/`get_kit_details` — the tools are the only source of
catalogue facts the model is given, so hallucinated prices/kits are
structurally unlikely, not just discouraged.

**Tool-calling reliability fixes (2026-11-19), because the model doesn't
always do what the prompt says.** Both found by replaying real reported
conversations against a live session, not by reading the code — the
backend was correct in both cases:

- The model sometimes passes a *guessed* `kit_id`/`product_id` to
  `add_kit_to_cart`/`add_product_to_cart` instead of an ID it actually
  got from `list_available_kits`/`get_kit_details` earlier in the
  conversation. `CatalogTools` already rejects it (`{"error":
  "not_found"}`) — never a wrong add, just a confusing one if the model
  then apologizes instead of retrying. The system prompt now explicitly
  requires using only a tool-returned ID, and to silently retry via
  `list_available_kits` on a `not_found`/`invalid_argument` tool error
  before ever telling the visitor something went wrong.
- The model sometimes answers a vague opening message ("quero virar
  programador") with a *complete*, tool-call-free reply that only
  announces an action ("Vou listar os kits... um momento!") and never
  follows through — the conversation dead-ends with a promise that never
  arrives. A prompt rule against this alone did **not** reliably fix it
  (measured ~2/3 of replays still stalling with the rule in place).
  `Assistant::respond()` now detects this pattern structurally
  (`looksLikeStall()`, a deliberately over-matching regex on common
  Portuguese "I'll go check" phrasing) and, while tool rounds remain,
  pushes a `role: system` nudge back into the conversation instead of
  accepting the stall as final — bounded by the same `max_tool_rounds`
  any other round already was. Verified firing correctly and recovering
  the conversation in real replays, not just that the failure rate
  happened to drop.

Neither fix makes tool-calling deterministic — same "prompt is not a hard
guarantee" caveat as point 2 above applies to the first fix, and the
second is a backstop for one specific observed failure shape, not a
general solution to the model skipping tool calls. If a new variant of
either surfaces, replay the exact reported conversation with temporary
per-round logging in `Assistant::respond()`'s loop before assuming the
fix regressed or that the backend is at fault.

## Login requirement mirrors the rest of the storefront

Exactly like every other "Add to cart" surface in this project, cart writes
require authentication. `CatalogTools::addKitToCart()`/`addProductToCart()`/
`getCartSummary()` check `$uid === NULL` *before* touching `CartManager` at
all and return `{"error": "login_required"}` as a normal tool result — the
model is told (via the system prompt's dynamic login-state line) not to
even attempt those tools for an anonymous visitor, and the controller
response's `login_required` flag makes the widget show a real "Entrar para
comprar" link regardless of whether the model behaves. Belt and suspenders:
the actual enforcement is the `$uid === NULL` check, the prompt is just
there to avoid a wasted tool round-trip.

## Two CSRF/session gotchas, found only by testing the real HTTP flow

Both were invisible from reading the code — only curl-ing the actual
request/response cycle (and, for the second, Chrome DevTools Protocol
computed-style inspection) surfaced them.

1. **The token must not be minted inside the block.** `ChatWidgetBlock` is
   per-visitor (`getCacheMaxAge(): 0`), which makes Drupal render it as a
   BigPipe placeholder on any page whose shell would otherwise be
   cacheable — and placeholder content streams to the client *after* the
   response's headers are already sent. A session (and its `Set-Cookie`)
   started while generating a CSRF token inside that placeholder can never
   reach the client on that response; the next POST would carry a token
   whose seed the server never actually persisted, and get rejected.
   Fixed by *not* generating the token in the block at all: `js/chat.js`
   fetches one from `GET /kit-assistant/token` (`ChatController::token()`)
   as its own top-level request, where this problem doesn't exist.
2. **Starting a session is not enough on its own.**
   `Drupal\Core\Session\SessionManager::getSessionDataMask()` explicitly
   ignores the CSRF metadata bag ("it does not contain any user data")
   when deciding whether a session is worth persisting — by design, so an
   anonymous visitor who only ever triggers a token generation and nothing
   else stays cacheable. Writing *only* a CSRF seed into the session,
   with nothing else in it, is therefore treated as obsolete/empty and
   never actually gets a `Set-Cookie`. `ChatController::token()` writes
   one real attribute-bag value (`kit_assistant_active`) to force real
   persistence — see that method's docblock for the exact mechanism.

## A CSS debugging story worth keeping: the block-wrapper "ghost box"

Screenshotting the home page after placing the widget turned up a visible,
empty, bordered box sitting in the sidebar region — nowhere near the
widget's actual (correctly `position: fixed`) bottom-right bubble.
Diagnosing it needed Chrome DevTools Protocol (`Runtime.evaluate` +
`getComputedStyle`/`getBoundingClientRect` over a raw WebSocket from a
small Node script — `curl`/`dump-dom` alone could not show computed
styles), not just screenshots or markup inspection. Two real,
independent causes, found in order:

1. The panel is toggled via the native `hidden` attribute, but also has an
   unconditional `display: flex` from its class. `[hidden] { display: none }`
   lives in the browser's **user-agent** stylesheet, the lowest-priority
   cascade origin — any **author** rule with a `display` value wins
   regardless of specificity. Fixed with an explicit
   `.kit-assistant__panel[hidden] { display: none; }` author rule (now
   higher-specificity *and* correct-origin, so the attribute has effect).
2. Separately, Barrio's base theme ships several region-contextual "widget
   box" rules for `.block` — `.sidebar .block { border; padding: 15px; ... }`,
   `.site-footer .block { ... }`, etc. — confirmed by reading the actual
   served CSS aggregate, not guessed. The widget's real content escapes
   via `position: fixed`, but that chrome is drawn on the block wrapper
   itself regardless, leaving an empty bordered box with 15px of padding
   wherever the block is placed. A same-specificity class override loses
   this fight on source order alone (ties go to whichever CSS file loads
   later); chasing every region variant one at a time is a losing game.
   Fixed once, robustly, with the block's own DOM id
   (`#block-ecommerce-barrio-chat-assistant { border: none; margin: 0;
   padding: 0; background: none; }`) — an id unconditionally outranks any
   number of classes, so this wins regardless of region or load order.

**Also found and fixed while testing (unrelated to this module, but found
this way):** `KitGridForm`'s hidden `form_build_id`/`form_id` inputs were
visible on the page as empty bordered boxes, for the exact same root cause
as #1 above — Bootstrap's `.form-control` class sets `display: block`
(author origin), overriding the browser's `input[type="hidden"] { display:
none }` default. Fixed in `ecommerce_barrio`'s `style.css`, not here, since
it affects every form on the site, not just this widget:
`input[type="hidden"] { display: none !important; }`.

## Known limitations

* Conversation history is session-scoped only (`PrivateTempStore`) — it is
  lost on logout/session expiry, same spirit as this project's cart having
  no "save for later". Not a bug; a deliberate scope call, since nothing
  asked for persisted chat transcripts.
* The cart-badge live update in the header
  (`updateCartBadge()` in `js/chat.js`) only refreshes after a chat-driven
  add; it doesn't listen for cart changes from elsewhere on the page.
  Same limitation `CartLinkBlock` already documents for itself.
* No streaming — a reply is a single request/response, not
  token-by-token. Simpler and matches this module's synchronous
  fetch()-based design; revisit if latency becomes a real complaint.
* Domain restriction is prompt-based, not a hard classifier (see
  "Guardrails" above) — a deliberate cost/complexity trade-off, flagged
  rather than oversold as airtight.
