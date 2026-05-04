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
      const ROTATION_INTERVAL = 4000;
      // Maximum number of pages to rotate (1-indexed, página 10)
      const MAX_PAGE = 10;

      if ($block.length) {
        let rotationTimer;

        // Custom AJAX command to override scrolling specifically for this block.
        // We only want to prevent scrolling when the rotation is happening automatically.
        let isRotatingAutomatically = false;

        if (Drupal.AjaxCommands && !Drupal.AjaxCommands.prototype.oldScrollTopForMiniMissions) {
          Drupal.AjaxCommands.prototype.oldScrollTopForMiniMissions = Drupal.AjaxCommands.prototype.scrollTop;
          Drupal.AjaxCommands.prototype.scrollTop = function (ajax, response, status) {
            // If it's our block and we are rotating automatically, do nothing.
            if (isRotatingAutomatically && ajax.element && $(ajax.element).closest(blockSelector).length) {
              return;
            }
            return Drupal.AjaxCommands.prototype.oldScrollTopForMiniMissions.apply(this, arguments);
          };

          // Also override viewsScrollTop if it exists (for backward compatibility in some Drupal versions)
          if (Drupal.AjaxCommands.prototype.viewsScrollTop) {
            Drupal.AjaxCommands.prototype.oldViewsScrollTopForMiniMissions = Drupal.AjaxCommands.prototype.viewsScrollTop;
            Drupal.AjaxCommands.prototype.viewsScrollTop = function (ajax, response, status) {
              if (isRotatingAutomatically && ajax.element && $(ajax.element).closest(blockSelector).length) {
                return;
              }
              return Drupal.AjaxCommands.prototype.oldViewsScrollTopForMiniMissions.apply(this, arguments);
            }
          }
        }

        // Function to get the current page number from the pager
        const getCurrentPage = function() {
          // Try to find the active/current page in the pager
          const $currentItem = $block.find('.pager__item.is-active');
          if ($currentItem.length) {
            const pageText = $currentItem.text().trim();
            const pageNum = parseInt(pageText);
            if (!isNaN(pageNum)) {
              return pageNum;
            }
          }
          
          // If no active item, check the URL of next link to deduce current page
          const $nextLink = $block.find('.pager__item--next a');
          if ($nextLink.length) {
            const href = $nextLink.attr('href');
            const pageMatch = href.match(/page=(\d+)/);
            if (pageMatch) {
              // The next link points to page N, so we're on page N-1
              // But remember page parameter is 0-indexed, so page=5 is actually page 6
              return parseInt(pageMatch[1]);
            }
          }
          
          // Default to page 1 if we can't determine
          return 1;
        };

        // Function to trigger AJAX pagination without scrolling.
        const rotateToNext = function() {
          const currentPage = getCurrentPage();
          const $nextLink = $block.find('.pager__item--next a');

          if ($nextLink.length && currentPage < MAX_PAGE) {
            // Set flag to inform our AJAX command override
            isRotatingAutomatically = true;

            // Click the link
            $nextLink[0].click();

            // Reset flag after a delay to allow manual clicks to still scroll if desired
            // (though normally manual clicks on pager should probably scroll, so we reset quickly)
            setTimeout(function() {
              isRotatingAutomatically = false;
            }, 1000);

            // Set timer for next rotation.
            rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
          } else {
            // We've reached the last page (page 10), stop rotation
            clearTimeout(rotationTimer);
          }
        };

            // Start the rotation.
            rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);

            // Reset the timer when the user manually clicks a pager link.
            $block.on('click', '.pager__item a', function() {
              clearTimeout(rotationTimer);
              const currentPage = getCurrentPage();
              // Only restart rotation if we're not at the max page
              if (currentPage < MAX_PAGE) {
                rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
              }
            });

            // Stop rotation when the user hovers over the block.
            $block.hover(
              function() {
                clearTimeout(rotationTimer);
              },
              function() {
                const currentPage = getCurrentPage();
                // Only restart rotation if we're not at the max page
                if (currentPage < MAX_PAGE) {
                  rotationTimer = setTimeout(rotateToNext, ROTATION_INTERVAL);
                }
              }
            );
          }
    }
  };
})(jQuery, Drupal);
