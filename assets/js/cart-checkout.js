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
})(jQuery);
