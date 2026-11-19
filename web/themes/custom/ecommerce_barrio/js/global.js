/**
 * @file
 * Global theme behaviors: the "Mais vendidos" prev/next buttons and
 * position dots scroll the grid they sit next to. Purely an enhancement
 * over the CSS scroll-snap slider (kit_catalog's own grid already swipes
 * without this) -- all of these are plain <button type="button"> with no
 * JS attached, so without this file they are simply inert, not broken.
 */
(function (Drupal, once) {

  'use strict';

  // Same "one card's width, plus the grid's gap" step every scroll-driven
  // interaction (arrows, dots) moves by, so they all land on the same
  // scroll-snap stops. Falls back to a fraction of the visible width if a
  // card can't be measured (e.g. an empty grid).
  function cardStep(target) {
    var card = target.querySelector('.kit-catalog-card');
    return card ? card.getBoundingClientRect().width + 16 : target.clientWidth * 0.8;
  }

  Drupal.behaviors.ecommerce_barrio = {
    attach: function (context) {
      once('kit-catalog-grid-nav', '[data-grid-scroll]', context).forEach(function (button) {
        button.addEventListener('click', function () {
          var target = document.getElementById(button.getAttribute('data-grid-target'));
          if (!target) {
            return;
          }
          target.scrollBy({
            left: button.getAttribute('data-grid-scroll') === 'prev' ? -cardStep(target) : cardStep(target),
            behavior: 'smooth',
          });
        });
      });

      once('kit-catalog-grid-dots', '.kit-catalog-grid__dots[data-grid-target]', context).forEach(function (dots) {
        var target = document.getElementById(dots.getAttribute('data-grid-target'));
        if (!target) {
          return;
        }

        var buttons = [];

        // How many *reachable* scroll positions actually exist, not how
        // many cards there are: once enough cards fit in view at once
        // (desktop), the leftover scrollable distance can be much less
        // than (card count - 1) full steps, so a dot per card would leave
        // some permanently unreachable. Recomputed on resize since it's a
        // function of viewport width.
        function pageCount() {
          var maxScroll = target.scrollWidth - target.clientWidth;
          if (maxScroll <= 1) {
            return target.querySelector('.kit-catalog-card') ? 1 : 0;
          }
          return Math.min(
            target.querySelectorAll('.kit-catalog-card').length,
            Math.ceil(maxScroll / cardStep(target)) + 1
          );
        }

        function setActiveFromScroll() {
          if (!buttons.length) {
            return;
          }
          var index = Math.round(target.scrollLeft / cardStep(target));
          index = Math.max(0, Math.min(buttons.length - 1, index));
          buttons.forEach(function (button, i) {
            button.classList.toggle('kit-catalog-grid__dot--active', i === index);
          });
        }

        function rebuild() {
          var count = pageCount();
          if (count === buttons.length) {
            return;
          }
          dots.innerHTML = '';
          buttons = [];
          for (var i = 0; i < count; i++) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'kit-catalog-grid__dot';
            button.setAttribute('aria-label', Drupal.t('Ir para a página @n', { '@n': i + 1 }));
            (function (index) {
              button.addEventListener('click', function () {
                target.scrollTo({ left: index * cardStep(target), behavior: 'smooth' });
              });
            })(i);
            dots.appendChild(button);
            buttons.push(button);
          }
          setActiveFromScroll();
        }

        rebuild();
        target.addEventListener('scroll', setActiveFromScroll);

        var resizeTimer;
        window.addEventListener('resize', function () {
          clearTimeout(resizeTimer);
          resizeTimer = setTimeout(rebuild, 150);
        });
      });
    },
  };

})(Drupal, once);
