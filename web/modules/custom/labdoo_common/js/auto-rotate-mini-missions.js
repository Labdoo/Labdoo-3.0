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
            let currentPage = 1;

            // Function to click the "next" pager link.
            const rotateToNext = function() {
              const $nextLink = $block.find('.pager__item--next a');
              
              if ($nextLink.length) {
                // Continue to next page
                $nextLink[0].click();
                currentPage++;
              } else {
                // No "next" link, so we are likely at the last page.
                // Go back to the first page
                const $firstLink = $block.find('.pager__item--first a');
                if ($firstLink.length) {
                  $firstLink[0].click();
                  currentPage = 1;
                } else {
                  // If there's no "first" link (maybe only 1-2 pages), 
                  // try to find the link to page 1 directly (often the first numbered pager item)
                  const $pageOneLink = $block.find('.pager__item a').first();
                  if ($pageOneLink.length) {
                    $pageOneLink[0].click();
                    currentPage = 1;
                  }
                }
              }

              // Set timer for next rotation.
              rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
            };

            // Start the rotation.
            rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);

            // Reset the timer when the user manually clicks a pager link.
            $block.find('.pager__item a').on('click', function() {
              clearTimeout(rotationTimer);
              // Try to detect current page from the clicked link
              const href = $(this).attr('href');
              if (href) {
                const pageMatch = href.match(/page=(\d+)/);
                if (pageMatch) {
                  currentPage = parseInt(pageMatch[1]) + 1; // page parameter is 0-indexed
                }
              }
              rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
            });

            // Stop rotation when the user hovers over the block.
            $block.hover(
              function() {
                clearTimeout(rotationTimer);
              },
              function() {
                rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
              }
            );
          }
    }
  };
})(jQuery, Drupal);
