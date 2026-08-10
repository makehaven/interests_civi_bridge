/**
 * @file
 * Recommendation cards on the onboarding thank-you page.
 *
 * View links open in a new tab so the member never loses their place in the
 * guide (starring, the primary action, already happens in place via AJAX).
 */
(function (Drupal) {
  Drupal.behaviors.mhRecCardLinks = {
    attach(context) {
      if (!context.querySelectorAll) {
        return;
      }
      context.querySelectorAll('.mh-rec-card a[href]').forEach((link) => {
        if (link.closest('.flag') || link.classList.contains('use-ajax')) {
          return;
        }
        link.target = '_blank';
        link.rel = 'noopener';
      });
    },
  };
})(Drupal);
