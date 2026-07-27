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

                $overlay.find('input.form-autocomplete').each(function() {
                    const $autocompleteInput = $(this);
                    const instance = $autocompleteInput.data('ui-autocomplete');

                    if (!instance || $autocompleteInput.data('labdooAutocompleteTuned')) {
                        return;
                    }

                    const originalSource = instance.source;
                    let pendingRequest = null;
                    let requestInFlight = false;

                    $autocompleteInput.autocomplete('option', 'minLength', 3);
                    $autocompleteInput.autocomplete('option', 'delay', 450);

                    $autocompleteInput.autocomplete('option', 'source', function(request, response) {
                        if (pendingRequest && pendingRequest.readyState !== 4) {
                            pendingRequest.abort();
                            pendingRequest = null;
                            requestInFlight = false;
                        }

                        if (requestInFlight) {
                            return;
                        }

                        requestInFlight = true;

                        const limitedResponse = (items) => {
                            requestInFlight = false;
                            if (Array.isArray(items)) {
                                response(items.slice(0, 5));
                                return;
                            }
                            response(items);
                        };

                        if (typeof originalSource !== 'function') {
                            requestInFlight = false;
                            return;
                        }

                        const sourceResult = originalSource.call(instance, request, limitedResponse);
                        const currentInstance = $(this).data('ui-autocomplete');
                        const xhr = sourceResult && typeof sourceResult.abort === 'function'
                            ? sourceResult
                            : (currentInstance && currentInstance.xhr && typeof currentInstance.xhr.abort === 'function'
                                ? currentInstance.xhr
                                : null);

                        if (!xhr) {
                            requestInFlight = false;
                            return;
                        }

                        pendingRequest = xhr;

                        if (typeof xhr.always === 'function') {
                            xhr.always(() => {
                                if (pendingRequest === xhr) {
                                    requestInFlight = false;
                                }
                            });
                        }
                    });

                    $autocompleteInput.data('labdooAutocompleteTuned', true);
                });

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

            // Actions block toggle logic for mobile
            const actionBlocks = once('actions-toggle', '.block-actions-block-block', context);
            actionBlocks.forEach((el) => {
                const $block = $(el);
                const $toggle = $block.find('.block-actions-toggle');
                const $content = $block.find('.block-actions-content');

                $toggle.on('click', function() {
                    if (window.innerWidth <= 1024) {
                        $toggle.toggleClass('is-active');
                        $content.toggleClass('is-expanded');
                    }
                });
            });

            // Sidebar visibility logic
            const sidebarElements = once('sidebar-visibility', '.region-sidebar', context);
            sidebarElements.forEach((el) => {
                const $sidebar = $(el);
                const checkVisibility = () => {
                    // Check if there are any visible blocks inside the sidebar
                    const hasVisibleContent = $sidebar.find('.block').filter(function() {
                        return $(this).css('display') !== 'none' && !$(this).hasClass('hidden');
                    }).length > 0;

                    if (hasVisibleContent) {
                        $sidebar.show();
                    } else {
                        $sidebar.hide();
                    }
                };

                // Run initially
                checkVisibility();

                // Observe changes in the sidebar to handle dynamic updates (like AJAX facets)
                const observer = new MutationObserver((mutations) => {
                    checkVisibility();
                });

                observer.observe(el, {
                    attributes: true,
                    childList: true,
                    subtree: true,
                    attributeFilter: ['class', 'style']
                });
            });
        }
    }
})(jQuery, Drupal, drupalSettings, once);
