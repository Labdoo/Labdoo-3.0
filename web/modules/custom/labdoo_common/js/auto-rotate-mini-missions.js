/**
 * @file
 * JavaScript to auto-rotate the "Current mini-missions" block.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Behavior to auto-rotate the "Current mini-missions" block.
   */
  Drupal.behaviors.autoRotateMiniMissions = {
    attach: function (context, settings) {
      // Target the "Current mini-missions" block.
      const blockSelector = '.block-views-blockactions-block-1';
      const $block = $(once('auto-rotate-mini-missions', blockSelector, context));
      // Time in milliseconds between rotations
      const ROTATION_INTERVAL = 5000;

      if ($block.length) {
        let rotationTimer;

        // Function to click the "next" pager link.
        const rotateToNext = function() {
          const $nextLink = $block.find('.pager__item--next a');
          if ($nextLink.length) {
            $nextLink[0].click();
          }

          // Set timer for next rotation.
          rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
        };

        // Start the rotation.
        rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);

        // Reset the timer when the user manually clicks a pager link.
        $block.find('.pager__item a').on('click', function() {
          clearTimeout(rotationTimer);
          rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
        });

        // Stop rotation when the user hovers over the block.
        $block.on('mouseenter', function() {
          clearTimeout(rotationTimer);
        });

        // Resume rotation when the user leaves the block.
        $block.on('mouseleave', function() {
          rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
        });
      }
    }
  };

})(jQuery, Drupal);
