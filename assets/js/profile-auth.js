(function () {
  var profileMenu = document.querySelector('[data-gp-profile-menu]');
  if (profileMenu) {
    var trigger = profileMenu.querySelector('[data-gp-profile-trigger]');
    var dropdown = profileMenu.querySelector('[data-gp-profile-dropdown]');

    if (!trigger || !dropdown) {
      return;
    }

    var setDropdownState = function (isOpen) {
      profileMenu.classList.toggle('is-open', isOpen);
      trigger.setAttribute('aria-expanded', String(isOpen));
      dropdown.hidden = !isOpen;
    };

    var closeDropdown = function () {
      setDropdownState(false);
    };

    var toggleDropdown = function () {
      var isOpen = profileMenu.classList.contains('is-open');
      setDropdownState(!isOpen);
    };

    trigger.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      toggleDropdown();
    });

    document.addEventListener('click', function (event) {
      if (profileMenu.contains(event.target)) {
        return;
      }

      setDropdownState(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        setDropdownState(false);
      }
    });
  }




  var allCatMenu = document.querySelector('[data-gp-all-cat-menu]');
  if (allCatMenu) {
    var allCatTrigger = allCatMenu.querySelector('[data-gp-all-cat-trigger]');
    var allCatDropdown = allCatMenu.querySelector('[data-gp-all-cat-dropdown]');

    var closeAllCatMenu = function () {
      allCatTrigger.setAttribute('aria-expanded', 'false');
      allCatDropdown.hidden = true;
    };

    allCatTrigger.addEventListener('click', function () {
      var isOpen = allCatTrigger.getAttribute('aria-expanded') === 'true';
      allCatTrigger.setAttribute('aria-expanded', String(!isOpen));
      allCatDropdown.hidden = isOpen;
    });

    document.addEventListener('click', function (event) {
      if (!allCatMenu.contains(event.target)) {
        closeAllCatMenu();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeAllCatMenu();
      }
    });
  }

  var showFormFeedback = function (form, message, level) {
    if (!form || !message) {
      return;
    }

    var existing = form.querySelector('.gp-auth-notice');
    if (existing) {
      existing.remove();
    }

    var notice = document.createElement('div');
    notice.className = 'gp-auth-notice ' + (level === 'success' ? 'is-success' : 'is-error');
    notice.setAttribute('role', 'status');
    notice.setAttribute('aria-live', 'polite');
    notice.textContent = message;
    form.insertBefore(notice, form.firstChild);
  };

  document.querySelectorAll('[data-gp-password-toggle]').forEach(function (toggleButton) {
    toggleButton.addEventListener('click', function () {
      var target = document.getElementById(toggleButton.getAttribute('data-gp-password-toggle'));
      if (!target) {
        return;
      }

      var isPassword = target.getAttribute('type') === 'password';
      target.setAttribute('type', isPassword ? 'text' : 'password');
      toggleButton.setAttribute('aria-label', isPassword ? 'Ukryj hasło' : 'Pokaż hasło');
      toggleButton.textContent = isPassword ? '🙈' : '👁';
    });
  });

  document.querySelectorAll('[data-gp-auth-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        form.reportValidity();
        return;
      }

      var submitButton = form.querySelector('.gp-auth-submit');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.dataset.originalText = submitButton.textContent;
        submitButton.textContent = 'Trwa wysyłanie…';
      }
    });

    form.querySelectorAll('[data-gp-disabled-google]').forEach(function (button) {
      button.addEventListener('click', function () {
        showFormFeedback(form, button.getAttribute('data-gp-feedback'), 'error');
      });
    });
  });

  var googleConfig = window.gpGoogleAuth || null;
  if (!googleConfig || !googleConfig.clientId) {
    return;
  }

  var startedAt = Date.now();
  var maxWaitMs = 5000;

  function setupGoogleIdentity() {
    if (!(window.google && window.google.accounts && window.google.accounts.id)) {
      if (Date.now() - startedAt < maxWaitMs) {
        window.setTimeout(setupGoogleIdentity, 150);
      }
      return;
    }

    try {
      google.accounts.id.initialize({
        client_id: googleConfig.clientId,
        nonce: googleConfig.nonce || '',
        callback: function (response) {
          if (!response || !response.credential) {
            return;
          }

          var activeButton = document.querySelector('[data-gp-google-button][data-gp-google-active="1"]');
          if (!activeButton || !activeButton.parentNode) {
            return;
          }

          var form = activeButton.parentNode.querySelector('[data-gp-google-form]');
          var credentialInput = form ? form.querySelector('[data-gp-google-credential]') : null;
          if (!form || !credentialInput) {
            return;
          }

          credentialInput.value = response.credential;
          form.submit();
        }
      });
    } catch (e) {
      return;
    }

    document.querySelectorAll('[data-gp-google-button]').forEach(function (button) {
      button.setAttribute('data-gp-google-active', '1');

      var buttonWidth = Math.max(220, Math.min(400, Math.round(button.clientWidth || 320)));

      try {
        google.accounts.id.renderButton(button, {
          theme: 'outline',
          size: 'large',
          type: 'standard',
          text: 'continue_with',
          shape: 'pill',
          logo_alignment: 'left',
          width: buttonWidth
        });
      } catch (e) {
        return;
      }
    });
  }

  setupGoogleIdentity();
})();
