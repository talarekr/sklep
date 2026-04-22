(function () {
  const select = document.getElementById('gp-language-select');
  if (!select) {
    return;
  }

  const allowedLanguages = ['pl', 'en', 'fr', 'uk', 'de'];
  const storageKey = 'gp_selected_language';

  const setGoogTransCookie = (lang) => {
    const safeLang = allowedLanguages.includes(lang) ? lang : 'pl';
    const value = '/pl/' + safeLang;
    const oneYear = 60 * 60 * 24 * 365;

    document.cookie = 'googtrans=' + value + ';path=/;max-age=' + oneYear;
    document.cookie = 'googtrans=' + value + ';path=/';
  };

  const setStoreLanguageCookie = (lang) => {
    const safeLang = allowedLanguages.includes(lang) ? lang : 'pl';
    const oneYear = 60 * 60 * 24 * 365;
    document.cookie = 'gp_selected_language=' + safeLang + ';path=/;max-age=' + oneYear + ';SameSite=Lax';
  };

  const applyLanguageToWidget = (lang) => {
    const safeLang = allowedLanguages.includes(lang) ? lang : 'pl';
    const combo = document.querySelector('.goog-te-combo');

    if (!combo) {
      return false;
    }

    combo.value = safeLang;
    combo.dispatchEvent(new Event('change'));
    return true;
  };

  const applyLanguage = (lang) => {
    const safeLang = allowedLanguages.includes(lang) ? lang : 'pl';

    localStorage.setItem(storageKey, safeLang);
    setGoogTransCookie(safeLang);
    setStoreLanguageCookie(safeLang);

    if (!applyLanguageToWidget(safeLang)) {
      window.setTimeout(function () {
        applyLanguageToWidget(safeLang);
      }, 400);
    }
  };

  window.gpGoogleTranslateInit = function () {
    if (!window.google || !window.google.translate || !window.google.translate.TranslateElement) {
      return;
    }

    new window.google.translate.TranslateElement(
      {
        pageLanguage: 'pl',
        includedLanguages: allowedLanguages.join(','),
        autoDisplay: false,
      },
      'gp-google-translate-element'
    );

    const savedLanguage = localStorage.getItem(storageKey) || 'pl';
    if (allowedLanguages.includes(savedLanguage)) {
      select.value = savedLanguage;
      if (savedLanguage !== 'pl') {
        window.setTimeout(function () {
          applyLanguageToWidget(savedLanguage);
        }, 350);
      }
    }
  };

  select.addEventListener('change', function (event) {
    const nextLanguage = event.target.value;
    applyLanguage(nextLanguage);
    window.location.reload();
  });

  const initialLanguage = localStorage.getItem(storageKey) || 'pl';
  if (allowedLanguages.includes(initialLanguage)) {
    select.value = initialLanguage;
    setGoogTransCookie(initialLanguage);
    setStoreLanguageCookie(initialLanguage);
  }

  const script = document.createElement('script');
  script.src = 'https://translate.google.com/translate_a/element.js?cb=gpGoogleTranslateInit';
  script.async = true;
  document.body.appendChild(script);
})();
