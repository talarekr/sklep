document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-search-switch]').forEach((switcher) => {
    switcher.querySelectorAll('button').forEach((button) => {
      button.addEventListener('click', () => {
        switcher.querySelectorAll('button').forEach((item) => item.classList.remove('is-active'));
        button.classList.add('is-active');
      });
    });
  });

  document.querySelectorAll('[data-close-bar]').forEach((button) => {
    button.addEventListener('click', () => {
      const bar = button.closest('[data-closable-bar]');
      if (bar) {
        bar.style.display = 'none';
      }
    });
  });

  document.querySelectorAll('.gp-product__fav').forEach((button) => {
    button.addEventListener('click', () => {
      button.classList.toggle('is-active');
      button.innerHTML = button.classList.contains('is-active') ? '&#9829;' : '&#9825;';
    });
  });
});
