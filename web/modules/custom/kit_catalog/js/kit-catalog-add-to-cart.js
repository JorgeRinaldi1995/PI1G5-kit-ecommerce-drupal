/**
 * @file
 * Mints a fresh idempotency token right before each "Add to cart" click.
 *
 * Shared by KitGridForm (many cards in one <form>, token field named
 * "wrapper[$id][token]") and AddToCartFormBase (a kit or product detail
 * page's single card, token field just named "token") -- both use the
 * hidden token field next to their button to tell a genuinely new click
 * apart from a duplicated/retried submission of the same click. Drupal's
 * Form API intentionally redisplays a hidden field's *submitted* value on
 * any rebuild of the same form (standard #default_value behaviour -- it
 * only seeds the very first render), so the AJAX "replace this card"
 * response always echoes back the token that was just posted, never a new
 * one. Left alone, every click after the first on the same card, in the
 * same page view, would be indistinguishable from a replay of that first
 * click.
 *
 * This regenerates the token on 'mousedown', in the capture phase, which the
 * DOM guarantees runs before any bubble-phase handler on the same element --
 * including core's own Drupal.Ajax 'mousedown' handler (#ajax defaults to
 * that event for buttons), which is what actually serializes and sends the
 * form. So by the time core reads the field's value, it is already fresh
 * for this click. A transport-level retry of that same submission (the
 * browser or a proxy resending an already-sent request body) does not fire
 * a new 'mousedown' event, so it still carries the token this code set for
 * the original click -- which is exactly what lets the server tell the two
 * cases apart.
 */
(function (Drupal) {
  function freshToken() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }

  Drupal.behaviors.kitCatalogAddToCart = {
    attach(context) {
      const cards = context.querySelectorAll
        ? context.querySelectorAll('.kit-catalog-card')
        : [];
      cards.forEach((card) => {
        const button = card.querySelector('.kit-catalog-card__cta[type="submit"]');
        const tokenField = card.querySelector(
          'input[type="hidden"][name="token"], input[type="hidden"][name$="[token]"]',
        );
        if (!button || !tokenField) {
          return;
        }
        button.addEventListener(
          'mousedown',
          () => {
            tokenField.value = freshToken();
          },
          true,
        );
      });
    },
  };
})(Drupal);
