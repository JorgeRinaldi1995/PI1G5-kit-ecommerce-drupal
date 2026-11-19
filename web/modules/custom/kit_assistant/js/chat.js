/**
 * @file
 * The Cleiton chat widget: open/close, send/receive, nothing framework-y.
 *
 * Matches this theme's existing choice (see ecommerce_barrio's README,
 * "The product slider") to reach for plain JS + a Drupal behavior instead
 * of a JS framework for a single, self-contained interactive widget.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  function escapeAndLinebreak(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/\n/g, '<br>');
  }

  function updateCartBadge(count) {
    if (count === null || count === undefined) {
      return;
    }
    const link = document.querySelector('.kit-catalog-cart-link');
    if (!link) {
      return;
    }
    let badge = link.querySelector('.kit-catalog-cart-link__badge');
    if (count > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'kit-catalog-cart-link__badge';
        link.appendChild(badge);
      }
      badge.textContent = String(count);
    }
    else if (badge) {
      badge.remove();
    }
  }

  function initWidget(root) {
    const settings = (drupalSettings.kitAssistant) || {};
    const toggle = root.querySelector('[data-kit-assistant-toggle]');
    const panel = root.querySelector('[data-kit-assistant-panel]');
    const closeBtn = root.querySelector('[data-kit-assistant-close]');
    const resetBtn = root.querySelector('[data-kit-assistant-reset]');
    const messages = root.querySelector('[data-kit-assistant-messages]');
    const form = root.querySelector('[data-kit-assistant-form]');
    const input = root.querySelector('[data-kit-assistant-input]');
    const sendBtn = root.querySelector('[data-kit-assistant-send]');

    let greeted = false;
    let csrfToken = null;
    let tokenPromise = null;

    function getToken() {
      if (csrfToken) {
        return Promise.resolve(csrfToken);
      }
      if (!tokenPromise) {
        tokenPromise = fetch(settings.tokenUrl, { credentials: 'same-origin' })
          .then(function (response) {
            return response.json();
          })
          .then(function (data) {
            csrfToken = data.token;
            return csrfToken;
          });
      }
      return tokenPromise;
    }

    function addMessage(role, text) {
      const bubble = document.createElement('div');
      bubble.className = 'kit-assistant__message kit-assistant__message--' + role;
      bubble.innerHTML = escapeAndLinebreak(text);
      messages.appendChild(bubble);
      messages.scrollTop = messages.scrollHeight;
      return bubble;
    }

    function addLoginPrompt() {
      const bubble = document.createElement('div');
      bubble.className = 'kit-assistant__message kit-assistant__message--system';
      const link = document.createElement('a');
      link.href = settings.loginUrl;
      link.className = 'kit-assistant__login-link';
      link.textContent = Drupal.t('Entrar para comprar');
      bubble.appendChild(link);
      messages.appendChild(bubble);
      messages.scrollTop = messages.scrollHeight;
    }

    function greet() {
      if (greeted) {
        return;
      }
      greeted = true;
      addMessage('assistant', Drupal.t('Oi! Eu sou o Cleiton. Me conta qual profissão você está começando, ou o que você já sabe fazer, que eu recomendo um kit daqui da loja pra você.'));
    }

    function openPanel() {
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      getToken();
      greet();
      input.focus();
    }

    function closePanel() {
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function () {
      if (panel.hidden) {
        openPanel();
      }
      else {
        closePanel();
      }
    });
    closeBtn.addEventListener('click', closePanel);

    resetBtn.addEventListener('click', function () {
      getToken().then(function (token) {
        fetch(settings.resetUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': token },
        }).catch(function () {});
      });
      messages.innerHTML = '';
      greeted = false;
      greet();
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      const text = input.value.trim();
      if (!text || sendBtn.disabled) {
        return;
      }

      addMessage('user', text);
      input.value = '';
      sendBtn.disabled = true;
      const typing = addMessage('assistant', Drupal.t('Digitando...'));
      typing.classList.add('kit-assistant__message--typing');

      getToken()
        .then(function (token) {
          return fetch(settings.messageUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': token,
            },
            body: JSON.stringify({ message: text }),
          });
        })
        .then(function (response) {
          if (response.status === 429) {
            return { reply: Drupal.t('Você enviou muitas mensagens em pouco tempo. Aguarde um pouco e tente de novo.') };
          }
          if (!response.ok) {
            return { reply: Drupal.t('Desculpe, algo deu errado. Tente novamente.') };
          }
          return response.json();
        })
        .catch(function () {
          return { reply: Drupal.t('Desculpe, não consegui falar com o assistente agora. Tente novamente.') };
        })
        .then(function (data) {
          typing.remove();
          addMessage('assistant', data.reply || Drupal.t('Desculpe, não consegui responder agora.'));
          if (data.login_required) {
            addLoginPrompt();
          }
          updateCartBadge(data.cart_count);
        })
        .finally(function () {
          sendBtn.disabled = false;
          input.focus();
        });
    });
  }

  Drupal.behaviors.kitAssistant = {
    attach: function (context) {
      once('kit-assistant', '[data-kit-assistant]', context).forEach(initWidget);
    },
  };

})(Drupal, drupalSettings, once);
