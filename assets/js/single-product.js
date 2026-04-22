(function () {
  const addToCartBtn = document.querySelector('.single-product .single_add_to_cart_button');
  const cartTarget = document.querySelector('.gp-main-actions__item--cart');

  if (!addToCartBtn || !cartTarget) {
    return;
  }

  const runFlyAnimation = () => {
    const btnRect = addToCartBtn.getBoundingClientRect();
    const cartRect = cartTarget.getBoundingClientRect();

    const fly = document.createElement('span');
    fly.className = 'gp-cart-fly-item';
    fly.style.left = `${btnRect.left + btnRect.width / 2 - 12}px`;
    fly.style.top = `${btnRect.top + btnRect.height / 2 - 12}px`;
    fly.style.opacity = '1';
    fly.style.transform = 'translate3d(0,0,0) scale(1)';

    document.body.appendChild(fly);

    const dx = cartRect.left + cartRect.width / 2 - (btnRect.left + btnRect.width / 2);
    const dy = cartRect.top + cartRect.height / 2 - (btnRect.top + btnRect.height / 2);

    requestAnimationFrame(() => {
      fly.style.transform = `translate3d(${dx}px, ${dy}px, 0) scale(0.25)`;
      fly.style.opacity = '0.15';
    });

    setTimeout(() => {
      fly.remove();
    }, 700);
  };

  addToCartBtn.addEventListener('click', () => {
    runFlyAnimation();
  });
})();
