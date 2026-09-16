/**
 * Keep the newly revealed referral name in view when a member chooses it.
 */
(function (Drupal, once) {
  Drupal.behaviors.referralCapture = {
    attach(context) {
      once('referral-capture', '.mh-interest-picker', context).forEach((form) => {
        form.addEventListener('change', (event) => {
          const input = event.target;
          if ((input.name === 'discovery' && input.value === 'member') ||
              (input.name === 'referral_add' && input.checked)) {
            // Let Drupal states reveal the field before moving focus.
            window.requestAnimationFrame(() => {
              const name = form.querySelector('[name="discovery_referring"]');
              if (name && name.getClientRects().length) {
                name.focus({ preventScroll: true });
                name.scrollIntoView({ block: 'nearest' });
              }
            });
          }
        });
      });
    },
  };
})(Drupal, once);
