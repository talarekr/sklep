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
});
