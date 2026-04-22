(function () {
  const cartTarget = document.querySelector('.gp-main-actions__item--cart');
  const addToCartButtons = document.querySelectorAll('.single-product .single_add_to_cart_button');

  if (!cartTarget || addToCartButtons.length === 0) {
    return;
  }

  const getFlyingImage = () => {
    const galleryImage = document.querySelector('.single-product .woocommerce-product-gallery__image.flex-active-slide img');
    if (galleryImage && galleryImage.currentSrc) {
      return galleryImage.currentSrc;
    }

    const fallbackImage = document.querySelector('.single-product .woocommerce-product-gallery__image img');
    return fallbackImage ? fallbackImage.currentSrc : '';
  };

  const runFlyAnimation = (button) => {
    const btnRect = button.getBoundingClientRect();
    const cartRect = cartTarget.getBoundingClientRect();
    const imageSrc = getFlyingImage();

    const fly = document.createElement('span');
    fly.className = 'gp-cart-fly-item';
    fly.style.left = `${btnRect.left + btnRect.width / 2 - 36}px`;
    fly.style.top = `${btnRect.top + btnRect.height / 2 - 36}px`;
    fly.style.opacity = '1';
    fly.style.transform = 'translate3d(0,0,0) scale(1)';
    if (imageSrc) {
      fly.style.backgroundImage = `url('${imageSrc}')`;
    }

    document.body.appendChild(fly);

    const dx = cartRect.left + cartRect.width / 2 - (btnRect.left + btnRect.width / 2);
    const dy = cartRect.top + cartRect.height / 2 - (btnRect.top + btnRect.height / 2);

    requestAnimationFrame(() => {
      fly.style.transform = `translate3d(${dx}px, ${dy}px, 0) scale(0.25)`;
      fly.style.opacity = '0.12';
    });

    setTimeout(() => {
      fly.remove();
      cartTarget.classList.add('gp-cart-bounce');
      setTimeout(() => cartTarget.classList.remove('gp-cart-bounce'), 500);
    }, 700);
  };

  addToCartButtons.forEach((button) => {
    button.addEventListener('click', () => {
      runFlyAnimation(button);
    });
  });
})();
