(function () {
  var profileMenu = document.querySelector('[data-gp-profile-menu]');
  if (profileMenu) {
    var trigger = profileMenu.querySelector('[data-gp-profile-trigger]');
    var dropdown = profileMenu.querySelector('[data-gp-profile-dropdown]');

    var closeDropdown = function () {
      trigger.setAttribute('aria-expanded', 'false');
      dropdown.hidden = true;
    };

    trigger.addEventListener('click', function () {
      var isOpen = trigger.getAttribute('aria-expanded') === 'true';
      trigger.setAttribute('aria-expanded', String(!isOpen));
      dropdown.hidden = isOpen;
    });

    document.addEventListener('click', function (event) {
      if (!profileMenu.contains(event.target)) {
        closeDropdown();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeDropdown();
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
  var lastGoogleContext = null;

  if (googleConfig && googleConfig.clientId && window.google && google.accounts && google.accounts.id) {
    google.accounts.id.initialize({
      client_id: googleConfig.clientId,
      callback: function (response) {
        if (!response || !response.credential) {
          return;
        }

        var contextSelector = lastGoogleContext
          ? '[data-gp-google-button][data-gp-context="' + lastGoogleContext + '"]'
          : '[data-gp-google-button]';
        var wrapper = document.querySelector(contextSelector);
        if (!wrapper) {
          return;
        }

        var form = wrapper.parentNode.querySelector('[data-gp-google-form]');
        if (!form) {
          return;
        }

        var credentialInput = form.querySelector('[data-gp-google-credential]');
        if (!credentialInput) {
          return;
        }

        credentialInput.value = response.credential;
        form.submit();
      }
    });

    document.querySelectorAll('[data-gp-google-button]').forEach(function (buttonHost) {
      google.accounts.id.renderButton(buttonHost, {
        type: 'standard',
        theme: 'outline',
        size: 'large',
        text: 'continue_with',
        width: 320
      });

      var socialForm = buttonHost.parentNode ? buttonHost.parentNode.querySelector('[data-gp-google-form]') : null;
      var triggerButton = socialForm ? socialForm.querySelector('[data-gp-google-submit]') : null;

      if (triggerButton) {
        triggerButton.addEventListener('click', function (event) {
          event.preventDefault();
          lastGoogleContext = buttonHost.getAttribute('data-gp-context') || null;
          google.accounts.id.prompt();
        });
      }
    });
  }
})();
