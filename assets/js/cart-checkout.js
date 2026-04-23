(function ($) {
  var miniCartPanel = document.querySelector('[data-gp-mini-cart-panel]');
  var miniCartOverlay = document.querySelector('[data-gp-mini-cart-overlay]');
  var miniCartContent = document.querySelector('[data-gp-mini-cart-content]');
  var authModal = document.querySelector('[data-gp-auth-modal]');

  var openMiniCart = function () {
    if (!miniCartPanel || !miniCartOverlay) return;
    miniCartPanel.hidden = false;
    miniCartOverlay.hidden = false;
    document.body.classList.add('gp-lock-scroll');
  };

  var closeMiniCart = function () {
    if (!miniCartPanel || !miniCartOverlay) return;
    miniCartPanel.hidden = true;
    miniCartOverlay.hidden = true;
    document.body.classList.remove('gp-lock-scroll');
  };

  var openAuthModal = function () {
    if (!authModal) return;
    authModal.hidden = false;
    document.body.classList.add('gp-lock-scroll');
  };

  var closeAuthModal = function () {
    if (!authModal) return;
    authModal.hidden = true;
    document.body.classList.remove('gp-lock-scroll');
  };

  document.querySelectorAll('[data-gp-mini-cart-open]').forEach(function (button) {
    button.addEventListener('click', openMiniCart);
  });

  document.querySelectorAll('[data-gp-mini-cart-close]').forEach(function (button) {
    button.addEventListener('click', closeMiniCart);
  });

  if (miniCartOverlay) {
    miniCartOverlay.addEventListener('click', closeMiniCart);
  }

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
})(jQuery);
