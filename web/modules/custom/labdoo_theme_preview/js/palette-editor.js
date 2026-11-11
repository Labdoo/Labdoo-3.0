(function (Drupal, once, drupalSettings) {
  'use strict';

  Drupal.behaviors.labdooPaletteEditor = {
    attach(context) {
      once('ldn-palette-editor', '#labdoo-next-palette-settings', context).forEach((form) => {
        const palettes = drupalSettings.labdooNextPalettes;
        const selector = form.elements.active;
        const create = form.elements.create;
        const name = form.elements['new_palette[label]'];
        const loaded = form.elements.loaded_palette;
        const load = form.querySelector('.ldn-palette-load');
        if (load) {
          load.hidden = true;
          load.disabled = true;
        }
        selector.addEventListener('change', () => {
          const palette = palettes[selector.value];
          if (!palette) return;
          loaded.value = selector.value;
          name.value = create.checked ? '' : palette.label;
          Object.entries(palette.colors).forEach(([key, color]) => {
            const input = form.elements[`new_palette[colors][${key}]`];
            if (input) input.value = color;
          });
        });
        create.addEventListener('change', () => {
          name.value = create.checked ? '' : palettes[selector.value].label;
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
