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
})();
