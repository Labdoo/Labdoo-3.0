/* eslint-disable no-bitwise, no-nested-ternary, no-mutable-exports, comma-dangle, strict */

'use strict';

(($, Drupal, drupalSettings, once) => {
    Drupal.behaviors.labdoo_ui = {
        attach: function attach(context) {
            // Search overlay logic
            const elements = once('search-init', '.search-overlay-block', context);
            elements.forEach((el) => {
                const $searchBlock = $(el);
                const $trigger = $searchBlock.find('.search-icon-trigger');
                const $overlay = $searchBlock.find('.search-overlay');
                const $close = $searchBlock.find('.search-overlay-close');
                const $input = $overlay.find('input[type="text"]');

                $trigger.on('click', function(e) {
                    e.preventDefault();
                    $overlay.addClass('active');
                    $input.focus();
                    $('body').addClass('search-overlay-open');
                });

                $close.on('click', function(e) {
                    e.preventDefault();
                    $overlay.removeClass('active');
                    $('body').removeClass('search-overlay-open');
                });

                // Close on escape key
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && $overlay.hasClass('active')) {
                        $overlay.removeClass('active');
                        $('body').removeClass('search-overlay-open');
                    }
                });

                // Close on click outside content
                $overlay.on('click', function(e) {
                    if ($(e.target).hasClass('search-overlay')) {
                        $overlay.removeClass('active');
                        $('body').removeClass('search-overlay-open');
                    }
                });
            });
        }
    }
})(jQuery, Drupal, drupalSettings, once);
