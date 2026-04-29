document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('[data-search-switch]').forEach((switcher) => {
    const container = switcher.closest('.gp-category-search-hero__panel') || switcher.parentElement;
    const form = container ? container.querySelector('[data-category-search-form]') : null;
    const searchModeInput = form ? form.querySelector('[data-category-search-mode]') : null;
    const partInput = form ? form.querySelector('[data-category-search-input="part"]') : null;
    const modelInput = form ? form.querySelector('[data-category-search-input="model"]') : null;

    const setActiveMode = (mode) => {
      const isModel = mode === 'model';

      if (searchModeInput) {
        searchModeInput.value = isModel ? 'vehicle_model' : 'part_number';
      }

      if (partInput) {
        partInput.disabled = isModel;
        partInput.hidden = isModel;
      }

      if (modelInput) {
        modelInput.disabled = !isModel;
        modelInput.hidden = !isModel;
      }
    };

    switcher.querySelectorAll('button').forEach((button) => {
      button.addEventListener('click', () => {
        switcher.querySelectorAll('button').forEach((item) => item.classList.remove('is-active'));
        button.classList.add('is-active');

        const mode = button.getAttribute('data-mode') || 'part';
        setActiveMode(mode);
      });
    });

    if (form) {
      form.addEventListener('submit', () => {
        const activeButton = switcher.querySelector('button.is-active') || switcher.querySelector('button[data-mode="part"]');
        setActiveMode(activeButton ? activeButton.getAttribute('data-mode') || 'part' : 'part');

        const actionUrl = form.getAttribute('action');
        if (!actionUrl) return;

        try {
          const parsedUrl = new URL(actionUrl, window.location.origin);
          parsedUrl.searchParams.delete('part_number');
          parsedUrl.searchParams.delete('s');
          parsedUrl.searchParams.delete('search_mode');
          form.setAttribute('action', `${parsedUrl.pathname}${parsedUrl.search}`);
        } catch (error) {
          // ignore invalid action URL, keep current form action
        }
      });
    }

    const initialActiveButton = switcher.querySelector('button.is-active') || switcher.querySelector('button[data-mode="part"]');
    setActiveMode(initialActiveButton ? initialActiveButton.getAttribute('data-mode') || 'part' : 'part');
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

  const sidebarCategorySelect = document.querySelector('[data-gp-sidebar-category-select]');
  const sidebarSubcategoryList = document.querySelector('[data-gp-subcategory-list]');
  const sidebarMoreButton = document.querySelector('[data-gp-subcategory-more]');

  if (sidebarCategorySelect && sidebarSubcategoryList) {
    const maxVisibleItems = 6;

    const setupShowMore = () => {
      const list = sidebarSubcategoryList.querySelector('.gp-cat-filter__list');
      if (!list || !sidebarMoreButton) return;

      const items = Array.from(list.querySelectorAll('li'));
      const shouldCollapse = items.length > maxVisibleItems;
      list.classList.toggle('is-collapsed', shouldCollapse);

      items.forEach((item, index) => {
        item.toggleAttribute('hidden', shouldCollapse && index >= maxVisibleItems);
      });

      sidebarMoreButton.hidden = !shouldCollapse;
      sidebarMoreButton.textContent = 'Wyświetl więcej';
      sidebarMoreButton.setAttribute('data-expanded', '0');
    };

    setupShowMore();

    sidebarCategorySelect.addEventListener('change', () => {
      const targetUrl = sidebarCategorySelect.value;
      if (!targetUrl || targetUrl === '0') return;
      window.location.href = targetUrl;
    });

    const sidebarFilterForm = document.querySelector('[data-gp-sidebar-filter-form]');
    const brandSelect = sidebarFilterForm ? sidebarFilterForm.querySelector('[data-gp-brand-select]') : null;
    if (sidebarFilterForm && brandSelect) {
      brandSelect.addEventListener('change', () => {
        sidebarFilterForm.submit();
      });
    }

    if (sidebarMoreButton) {
      sidebarMoreButton.addEventListener('click', () => {
        const list = sidebarSubcategoryList.querySelector('.gp-cat-filter__list');
        if (!list) return;

        const isExpanded = sidebarMoreButton.getAttribute('data-expanded') === '1';
        const items = Array.from(list.querySelectorAll('li'));
        items.forEach((item, index) => {
          if (index < maxVisibleItems) {
            item.hidden = false;
            return;
          }

          item.hidden = isExpanded;
        });

        sidebarMoreButton.textContent = isExpanded ? 'Wyświetl więcej' : 'Pokaż mniej';
        sidebarMoreButton.setAttribute('data-expanded', isExpanded ? '0' : '1');
      });
    }
  }

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

  document.querySelectorAll('[data-gp-hero-slider]').forEach((slider) => {
    const slides = Array.from(slider.querySelectorAll('[data-gp-hero-slide]'));
    const dotsWrap = slider.querySelector('[data-gp-hero-dots]');
    const prevButton = slider.querySelector('[data-gp-hero-prev]');
    const nextButton = slider.querySelector('[data-gp-hero-next]');
    const titleElement = slider.querySelector('[data-gp-hero-title]');
    const autoplayMs = Number.parseInt(slider.getAttribute('data-autoplay-ms') || '5500', 10);

    if (!slides.length || !dotsWrap || !prevButton || !nextButton) return;

    let activeIndex = slides.findIndex((slide) => slide.classList.contains('is-active'));
    if (activeIndex < 0) activeIndex = 0;
    let autoplayTimer = null;

    const slideTitles = slides.map((slide) => {
      const titleTemplate = slide.querySelector('[data-gp-hero-slide-title]');
      return titleTemplate ? titleTemplate.innerHTML : '';
    });

    const renderDots = () => {
      dotsWrap.innerHTML = '';
      slides.forEach((_, index) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = `gp-hero__dot${index === activeIndex ? ' is-active' : ''}`;
        dot.setAttribute('aria-label', `Idź do slajdu ${index + 1}`);
        dot.setAttribute('aria-pressed', index === activeIndex ? 'true' : 'false');
        dot.addEventListener('click', () => {
          goTo(index);
          restartAutoplay();
        });
        dotsWrap.appendChild(dot);
      });
    };

    const goTo = (index) => {
      activeIndex = (index + slides.length) % slides.length;

      slides.forEach((slide, slideIndex) => {
        const isActive = slideIndex === activeIndex;
        slide.classList.toggle('is-active', isActive);
        slide.setAttribute('aria-hidden', isActive ? 'false' : 'true');
      });

      if (titleElement && slideTitles[activeIndex]) {
        titleElement.innerHTML = slideTitles[activeIndex];
      }

      renderDots();
    };

    const stopAutoplay = () => {
      if (!autoplayTimer) return;
      window.clearInterval(autoplayTimer);
      autoplayTimer = null;
    };

    const startAutoplay = () => {
      if (slides.length < 2) return;
      stopAutoplay();
      autoplayTimer = window.setInterval(() => {
        goTo(activeIndex + 1);
      }, Number.isFinite(autoplayMs) ? autoplayMs : 5500);
    };

    const restartAutoplay = () => {
      stopAutoplay();
      startAutoplay();
    };

    prevButton.addEventListener('click', () => {
      goTo(activeIndex - 1);
      restartAutoplay();
    });

    nextButton.addEventListener('click', () => {
      goTo(activeIndex + 1);
      restartAutoplay();
    });

    slider.addEventListener('mouseenter', stopAutoplay);
    slider.addEventListener('mouseleave', startAutoplay);
    slider.addEventListener('focusin', stopAutoplay);
    slider.addEventListener('focusout', startAutoplay);

    goTo(activeIndex);
    startAutoplay();
  });

  const getVisibleCount = () => {
    if (window.matchMedia('(max-width: 767px)').matches) return 2;
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

      const viewport = carousel.querySelector('[data-gp-carousel-viewport]');
      const viewportWidth = viewport ? viewport.getBoundingClientRect().width : 0;
      const offset = Math.max(0, page * viewportWidth);

      track.style.transform = `translateX(-${offset}px)`;
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
