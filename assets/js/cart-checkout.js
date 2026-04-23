(function ($) {
  var miniCartPanel = document.querySelector('[data-gp-mini-cart-panel]');
  var miniCartWrap = document.querySelector('[data-gp-mini-cart-wrap]');
  var miniCartContent = document.querySelector('[data-gp-mini-cart-content]');
  var authModal = document.querySelector('[data-gp-auth-modal]');
  var isAuthModalOpen = function () {
    return !!authModal && !authModal.hidden;
  };
  var syncBodyScrollLock = function () {
    document.body.classList.toggle('gp-lock-scroll', isAuthModalOpen());
  };

  var openMiniCart = function () {
    if (!miniCartPanel) return;
    closeAuthModal();
    miniCartPanel.hidden = false;
    miniCartPanel.setAttribute('aria-hidden', 'false');
    syncBodyScrollLock();
  };

  var closeMiniCart = function () {
    if (!miniCartPanel) return;
    miniCartPanel.hidden = true;
    miniCartPanel.setAttribute('aria-hidden', 'true');
    syncBodyScrollLock();
  };

  var openAuthModal = function () {
    if (!authModal) return;
    closeMiniCart();
    authModal.hidden = false;
    authModal.setAttribute('aria-hidden', 'false');
    syncBodyScrollLock();
  };

  var closeAuthModal = function () {
    if (!authModal) return;
    authModal.hidden = true;
    authModal.setAttribute('aria-hidden', 'true');
    syncBodyScrollLock();
  };

  // Hard reset defensive state on page entry to prevent accidental auto-open.
  closeMiniCart();
  closeAuthModal();

  document.querySelectorAll('[data-gp-mini-cart-open]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.preventDefault();
      openMiniCart();
    });
  });

  document.querySelectorAll('[data-gp-mini-cart-close]').forEach(function (button) {
    button.addEventListener('click', closeMiniCart);
  });

  document.querySelectorAll('[data-gp-auth-modal-close]').forEach(function (button) {
    button.addEventListener('click', closeAuthModal);
  });

  if (authModal) {
    authModal.addEventListener('click', function (event) {
      if (event.target === authModal) {
        closeAuthModal();
      }
    });
  }

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    closeAuthModal();
    closeMiniCart();
  });

  document.addEventListener('click', function (event) {
    if (!miniCartWrap || !miniCartPanel || miniCartPanel.hidden) {
      return;
    }

    if (miniCartWrap.contains(event.target)) {
      return;
    }

    closeMiniCart();
  });

  var refreshCartNumbers = function (count) {
    document.querySelectorAll('.gp-mini-cart-count').forEach(function (badge) {
      badge.textContent = count;
    });
  };

  var syncLocalStorage = function () {
    if (!miniCartContent) return;
    window.localStorage.setItem('gpMiniCartSnapshot', miniCartContent.innerHTML);
  };

  var postCartAction = function (action, payload) {
    return $.post(gpCartCheckout.ajaxUrl, {
      action: action,
      nonce: gpCartCheckout.nonce,
      itemKey: payload.itemKey,
      delta: payload.delta
    });
  };

  var refreshMiniCart = function () {
    return postCartAction('gp_get_mini_cart', {}).done(function (response) {
      if (!response || !response.success || !miniCartContent) return;
      miniCartContent.innerHTML = response.data.contentHtml;
      refreshCartNumbers(response.data.count);
      syncLocalStorage();
    });
  };

  if (miniCartContent) {
    miniCartContent.addEventListener('click', function (event) {
      var qtyButton = event.target.closest('[data-gp-mini-cart-qty]');
      if (qtyButton) {
        var row = qtyButton.closest('[data-cart-item-key]');
        if (!row) return;

        postCartAction('gp_update_mini_cart_quantity', {
          itemKey: row.getAttribute('data-cart-item-key'),
          delta: parseInt(qtyButton.getAttribute('data-gp-mini-cart-qty'), 10)
        }).done(function (response) {
          if (!response || !response.success) return;
          miniCartContent.innerHTML = response.data.contentHtml;
          refreshCartNumbers(response.data.count);
          syncLocalStorage();
        });

        return;
      }

      var removeButton = event.target.closest('[data-gp-mini-cart-remove]');
      if (removeButton) {
        var rowToRemove = removeButton.closest('[data-cart-item-key]');
        if (!rowToRemove) return;

        postCartAction('gp_remove_mini_cart_item', {
          itemKey: rowToRemove.getAttribute('data-cart-item-key')
        }).done(function (response) {
          if (!response || !response.success) return;
          miniCartContent.innerHTML = response.data.contentHtml;
          refreshCartNumbers(response.data.count);
          syncLocalStorage();
        });
      }
    });
  }

  document.querySelectorAll('[data-gp-order-cta], .checkout-button').forEach(function (button) {
    button.addEventListener('click', function (event) {
      if (gpCartCheckout.isLoggedIn) {
        return;
      }

      event.preventDefault();
      openAuthModal();
    });
  });

  $(document.body).on('added_to_cart wc_fragments_refreshed', function () {
    refreshMiniCart();
  });

  var replaceExactText = function (scope, from, to) {
    if (!scope) return;

    scope.querySelectorAll('*').forEach(function (el) {
      if (!el.childNodes || el.childNodes.length !== 1) {
        return;
      }

      var node = el.childNodes[0];
      if (!node || node.nodeType !== Node.TEXT_NODE) {
        return;
      }

      var text = node.textContent ? node.textContent.trim() : '';
      if (text !== from) {
        return;
      }

      node.textContent = to;
    });
  };

  var sanitizeProductMetaLine = function (scope) {
    if (!scope) return;

    scope.querySelectorAll('.wc-block-cart-item__product .wc-block-components-product-details__witam, .wc-block-components-product-details__witam, .wc-block-components-product-metadata').forEach(function (el) {
      if (!el.textContent) return;
      if (el.textContent.indexOf('Witam oferta dotyczy:') === -1) return;
      el.textContent = el.textContent.replace('Witam oferta dotyczy:', '').trim();
      if (el.textContent === '') {
        el.remove();
      }
    });

    scope.querySelectorAll('.wc-block-components-product-details__value').forEach(function (el) {
      if (!el.textContent) return;
      if (el.textContent.trim() !== 'Witam oferta dotyczy:') return;
      el.remove();
    });
  };

  var enhanceCartBlock = function () {
    if (!document.body.classList.contains('woocommerce-cart')) {
      return;
    }

    var cartScope = document.querySelector('.wp-block-woocommerce-cart, .wc-block-cart');
    if (!cartScope) {
      return;
    }

    replaceExactText(cartScope, 'Free shipping', 'Koszt dostawy');
    replaceExactText(cartScope, 'BEZPŁATNIE', '0 zł');
    replaceExactText(cartScope, 'FREE!', '0 zł');
    replaceExactText(cartScope, 'Estimated total', 'Suma');
    replaceExactText(cartScope, 'Szacowana łączna kwota', 'Suma');
    sanitizeProductMetaLine(cartScope);

    cartScope.querySelectorAll('.wc-block-cart__submit-button, .wc-block-components-checkout-place-order-button').forEach(function (button) {
      button.classList.add('gp-cart-cta-button');
      if (button.textContent && button.textContent.trim() !== 'Przejdź do płatności') {
        button.textContent = 'Przejdź do płatności';
      }
    });
  };

  var replaceTextContains = function (scope, from, to) {
    if (!scope) return;

    scope.querySelectorAll('*').forEach(function (el) {
      if (!el.childNodes || el.childNodes.length !== 1) {
        return;
      }

      var node = el.childNodes[0];
      if (!node || node.nodeType !== Node.TEXT_NODE || !node.textContent) {
        return;
      }

      if (node.textContent.indexOf(from) === -1) {
        return;
      }

      node.textContent = node.textContent.replace(from, to);
    });
  };

  var removeCheckoutShippingOptions = function (scope) {
    if (!scope) return;

    scope.querySelectorAll('h1, h2, h3, h4, h5, h6, .wc-block-components-checkout-step__title, .wc-block-components-title').forEach(function (titleEl) {
      var title = titleEl.textContent ? titleEl.textContent.trim() : '';
      if (title !== 'Opcje wysyłki' && title !== 'Shipping options') {
        return;
      }

      var shippingContainer = titleEl.closest(
        '.wc-block-components-checkout-step, .wc-block-checkout__shipping-method-block, .wc-block-checkout__shipping-fields-block, .wc-block-components-address-form, .wc-block-checkout__contact-fields'
      );

      if (shippingContainer) {
        shippingContainer.remove();
        return;
      }

      var nextElement = titleEl.nextElementSibling;
      titleEl.remove();
      if (nextElement) {
        nextElement.remove();
      }
    });

    scope.querySelectorAll('.wc-block-components-checkout-step__title, .wc-block-components-title').forEach(function (titleEl) {
      var title = titleEl.textContent ? titleEl.textContent.trim() : '';
      if (title !== 'Opcje wysyłki' && title !== 'Shipping options') {
        return;
      }

      var shippingStep = titleEl.closest('.wc-block-components-checkout-step, .wc-block-checkout__shipping-method-block');
      if (shippingStep) {
        shippingStep.remove();
      }
    });
  };

  var enhanceCheckoutBlock = function () {
    if (!document.body.classList.contains('woocommerce-checkout') || document.body.classList.contains('woocommerce-order-received')) {
      return;
    }

    var checkoutScope = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout, form.checkout, .woocommerce-checkout');
    if (!checkoutScope) {
      return;
    }

    replaceExactText(checkoutScope, 'Free shipping', 'Koszt dostawy');
    replaceExactText(checkoutScope, 'BEZPŁATNIE', '0 zł');
    replaceExactText(checkoutScope, 'FREE!', '0 zł');
    replaceTextContains(checkoutScope, 'Free shipping', 'Koszt dostawy');
    replaceTextContains(checkoutScope, 'BEZPŁATNIE', '0 zł');
    replaceTextContains(checkoutScope, 'FREE!', '0 zł');
    removeCheckoutShippingOptions(checkoutScope);
  };

  enhanceCartBlock();
  enhanceCheckoutBlock();

  if (document.body.classList.contains('woocommerce-cart')) {
    var isEnhancingCartBlock = false;
    var cartObserver = new MutationObserver(function () {
      if (isEnhancingCartBlock) {
        return;
      }

      isEnhancingCartBlock = true;
      window.requestAnimationFrame(function () {
        enhanceCartBlock();
        isEnhancingCartBlock = false;
      });
    });

    var cartObserverScope = document.querySelector('.wp-block-woocommerce-cart, .wc-block-cart');
    if (!cartObserverScope) {
      return;
    }

    cartObserver.observe(cartObserverScope, {
      childList: true,
      subtree: true
    });
  }

  if (document.body.classList.contains('woocommerce-checkout') && !document.body.classList.contains('woocommerce-order-received')) {
    var isEnhancingCheckoutBlock = false;
    var checkoutObserver = new MutationObserver(function () {
      if (isEnhancingCheckoutBlock) {
        return;
      }

      isEnhancingCheckoutBlock = true;
      window.requestAnimationFrame(function () {
        enhanceCheckoutBlock();
        isEnhancingCheckoutBlock = false;
      });
    });

    var checkoutObserverScope = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout, form.checkout, .woocommerce-checkout');
    if (!checkoutObserverScope) {
      return;
    }

    checkoutObserver.observe(checkoutObserverScope, {
      childList: true,
      subtree: true
    });
  }
})(jQuery);
