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

  document.querySelectorAll('[data-gp-cat-select]').forEach((select) => {
    select.addEventListener('change', () => {
      const targetUrl = select.value;
      if (!targetUrl) return;
      window.location.href = targetUrl;
    });
  });

  const partSearchBox = document.querySelector('[data-gp-part-search-box]');
  if (partSearchBox) {
    const isHeroVariant = partSearchBox.classList.contains('gp-part-search-box--hero');
    const toggleButton = partSearchBox.querySelector('[data-gp-part-search-toggle]');
    const closeButton = partSearchBox.querySelector('[data-gp-part-search-close]');
    const stateKey = 'gpPartSearchCollapsed';

    const setCollapsed = (collapsed) => {
      partSearchBox.classList.toggle('is-collapsed', collapsed);
      if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      }
      window.localStorage.setItem(stateKey, collapsed ? '1' : '0');
    };

    if (isHeroVariant) {
      setCollapsed(false);
      if (toggleButton) {
        toggleButton.setAttribute('aria-expanded', 'true');
      }
    } else {
      const isCollapsed = window.localStorage.getItem(stateKey) === '1';
      setCollapsed(isCollapsed);
    }

    if (toggleButton && !isHeroVariant) {
      toggleButton.addEventListener('click', () => setCollapsed(false));
    }

    if (closeButton && !isHeroVariant) {
      closeButton.addEventListener('click', () => setCollapsed(true));
    }
  }

  document.querySelectorAll('.gp-product__fav').forEach((button) => {
    button.addEventListener('click', () => {
      button.classList.toggle('is-active');
      button.innerHTML = button.classList.contains('is-active') ? '&#9829;' : '&#9825;';
    });
  });

  const getVisibleCount = () => {
    if (window.matchMedia('(max-width: 767px)').matches) return 1;
    if (window.matchMedia('(max-width: 1199px)').matches) return 2;
    return 4;
  };

  document.querySelectorAll('[data-gp-carousel]').forEach((carousel) => {
    const track = carousel.querySelector('[data-gp-carousel-track]');
    const slides = Array.from(carousel.querySelectorAll('.gp-carousel__slide'));
    const dotsWrap = carousel.querySelector('[data-gp-carousel-dots]');
    const prev = carousel.querySelector('[data-gp-prev]');
    const next = carousel.querySelector('[data-gp-next]');

    if (!track || slides.length === 0 || !dotsWrap || !prev || !next) return;

    let page = 0;
    let pages = 1;

    const renderDots = () => {
      dotsWrap.innerHTML = '';
      for (let i = 0; i < pages; i += 1) {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = `gp-carousel__dot${i === page ? ' is-active' : ''}`;
        dot.setAttribute('aria-label', `Idź do strony ${i + 1}`);
        dot.addEventListener('click', () => {
          page = i;
          update();
        });
        dotsWrap.appendChild(dot);
      }
    };

    const update = () => {
      const visible = getVisibleCount();
      pages = Math.max(1, Math.ceil(slides.length / visible));
      if (page > pages - 1) page = pages - 1;

      track.style.transform = `translateX(-${page * 100}%)`;
      prev.disabled = page === 0;
      next.disabled = page >= pages - 1;
      renderDots();
    };

    prev.addEventListener('click', () => {
      page = Math.max(0, page - 1);
      update();
    });

    next.addEventListener('click', () => {
      page = Math.min(pages - 1, page + 1);
      update();
    });

    window.addEventListener('resize', update);
    update();
  });
});
