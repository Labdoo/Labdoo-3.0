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
                // Save current scroll position
                const scrollX = window.scrollX;
                const scrollY = window.scrollY;

                // Prevent any scroll attempts - capture phase to intercept early
                const preventScroll = function(e) {
                  e.preventDefault();
                  e.stopImmediatePropagation();
                  window.scrollTo(scrollX, scrollY);
                };

                // Block scroll events in capture phase
                window.addEventListener('scroll', preventScroll, { passive: false, capture: true });
                document.addEventListener('scroll', preventScroll, { passive: false, capture: true });

                // Override scrollTo, scrollIntoView, and scroll
                const originalScrollTo = window.scrollTo;
                const originalScrollIntoView = Element.prototype.scrollIntoView;
                const originalScroll = window.scroll;

                window.scrollTo = function() { return; };
                window.scroll = function() { return; };
                Element.prototype.scrollIntoView = function() { return; };

                // Use requestAnimationFrame to force position during any reflow
                let rafId;
                const forcePosition = function() {
                  if (window.scrollX !== scrollX || window.scrollY !== scrollY) {
                    originalScrollTo.call(window, scrollX, scrollY);
                  }
                  rafId = requestAnimationFrame(forcePosition);
                };
                rafId = requestAnimationFrame(forcePosition);

                // Click the link
                $nextLink[0].click();

                // Restore everything after AJAX completes
                setTimeout(function() {
                  cancelAnimationFrame(rafId);
                  window.scrollTo = originalScrollTo;
                  window.scroll = originalScroll;
                  Element.prototype.scrollIntoView = originalScrollIntoView;
                  window.removeEventListener('scroll', preventScroll, { capture: true });
                  document.removeEventListener('scroll', preventScroll, { capture: true });
                  originalScrollTo.call(window, scrollX, scrollY);
                }, 500);

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
