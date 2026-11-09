/**
 * @file
 * Groups CiviCRM's flat Area of Interest checkboxes under category headings.
 *
 * CiviCRM renders the interest custom field as one alphabet-soup grid of the
 * child interests. Drupal passes the category map from the taxonomy; this moves
 * each existing option row (hidden input + checkbox + label, untouched) into a
 * fieldset per category. Anything the map does not know lands in "Other", so a
 * new CiviCRM option is never hidden.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  function normalize(text) {
    return text.replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function buildGroup(label, pairs) {
    const fieldset = document.createElement('fieldset');
    fieldset.className = 'mh-civi-interest-group';
    const legend = document.createElement('legend');
    legend.className = 'mh-civi-interest-group__title';
    legend.textContent = label;
    const options = document.createElement('div');
    options.className = 'mh-civi-interest-group__options';
    pairs.forEach((pair) => options.appendChild(pair));
    fieldset.append(legend, options);
    return fieldset;
  }

  Drupal.behaviors.interestsCiviBridgeGroups = {
    attach(context) {
      const settings = drupalSettings.interestsCiviBridge && drupalSettings.interestsCiviBridge.civiGroups;
      if (!settings || !settings.groups || !settings.groups.length) {
        return;
      }

      const lookup = new Map();
      settings.groups.forEach((group, i) => {
        group.children.forEach((child, j) => lookup.set(normalize(child), [i, j]));
      });

      const selector = 'input[type="checkbox"][name^="' + settings.field + '["]';
      const firstInputs = Array.from(context.querySelectorAll(selector));
      const containers = new Set();
      firstInputs.forEach((input) => {
        const container = input.closest('.crm-multiple-checkbox-radio-options');
        if (container) {
          containers.add(container);
        }
      });

      once('mh-civi-interest-groups', Array.from(containers)).forEach((container) => {
        const buckets = settings.groups.map(() => []);
        const other = [];

        container.querySelectorAll(':scope > .crm-option-label-pair').forEach((pair) => {
          const input = pair.querySelector(selector);
          if (!input) {
            other.push(pair);
            return;
          }
          const label = pair.querySelector('label[for="' + input.id + '"]') || pair.querySelector('label');
          const key = label ? normalize(label.textContent) : '';
          if (lookup.has(key)) {
            const [group, order] = lookup.get(key);
            buckets[group].push({ pair, order });
          }
          else {
            other.push(pair);
          }
        });

        // Nothing matched: the map and CiviCRM disagree entirely. Leave
        // CiviCRM's layout alone rather than show one "Other" box.
        if (buckets.every((b) => b.length === 0)) {
          return;
        }

        const fragment = document.createDocumentFragment();
        settings.groups.forEach((group, i) => {
          if (buckets[i].length) {
            // Taxonomy order, the same order members see on Drupal forms.
            const pairs = buckets[i].sort((a, b) => a.order - b.order).map((b) => b.pair);
            fragment.appendChild(buildGroup(group.label, pairs));
          }
        });
        if (other.length) {
          fragment.appendChild(buildGroup(Drupal.t('Other'), other));
        }

        container.classList.add('mh-civi-interest-groups');
        container.appendChild(fragment);
      });
    },
  };
})(Drupal, drupalSettings, once);
