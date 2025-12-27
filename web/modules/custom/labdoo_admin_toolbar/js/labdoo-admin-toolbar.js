/**
 * @file
 * JavaScript behaviors for the Labdoo Admin Toolbar.
 */

(function ($, Drupal, once) {
  'use strict';

  /**
   * Behavior for the Labdoo Admin Toolbar.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.labdooAdminToolbar = {
    attach: function (context, settings) {
      once('labdoo-admin-toolbar', '.labdoo-admin-toolbar', context).forEach(function (toolbar) {
        // Initialize the toolbar.
        initToolbar(toolbar);
      });
    }
  };

  /**
   * Initialize the toolbar.
   *
   * @param {HTMLElement} toolbar
   *   The toolbar element.
   */
  function initToolbar(toolbar) {
    var $toolbar = $(toolbar);
    var $menuItems = $toolbar.find('.labdoo-admin-menu-item');
    var $submenuItems = $toolbar.find('.labdoo-admin-submenu-item');
    var closeTimeout;

    // Add hover functionality with delay.
    $menuItems.add($submenuItems).hover(
      function () {
        // On mouse enter.
        var $item = $(this);
        
        // Clear any existing timeout.
        if (closeTimeout) {
          clearTimeout(closeTimeout);
          closeTimeout = null;
        }
        
        // Close all other open submenus at the same level.
        if ($item.hasClass('labdoo-admin-menu-item')) {
          $menuItems.not($item).removeClass('hover').find('.labdoo-admin-submenu').hide();
        } else {
          $item.siblings().removeClass('hover').find('.labdoo-admin-submenu-level2').hide();
        }
        
        // Open this submenu.
        $item.addClass('hover');
        $item.children('ul').show();
      },
      function () {
        // On mouse leave.
        var $item = $(this);
        
        // Set a timeout to close the submenu.
        closeTimeout = setTimeout(function () {
          $item.removeClass('hover');
          $item.children('ul').hide();
        }, 300); // 300ms delay before closing
      }
    );

    // Add keyboard navigation.
    $toolbar.find('a').on('keydown', function (e) {
      var $link = $(this);
      var $item = $link.parent();
      var $parentMenu = $item.parent();
      var $parentItem = $parentMenu.parent();
      
      // Handle arrow keys, enter, escape, and tab.
      switch (e.keyCode) {
        case 37: // Left arrow.
          if ($parentItem.hasClass('labdoo-admin-menu-item') || $parentItem.hasClass('labdoo-admin-submenu-item')) {
            $parentItem.removeClass('hover');
            $parentMenu.hide();
            $parentItem.children('a').focus();
            e.preventDefault();
          }
          break;
          
        case 38: // Up arrow.
          var $prevItem = $item.prev();
          if ($prevItem.length) {
            $prevItem.children('a').focus();
          } else {
            $parentItem.children('a').focus();
            $parentItem.removeClass('hover');
            $parentMenu.hide();
          }
          e.preventDefault();
          break;
          
        case 39: // Right arrow.
          if ($item.hasClass('has-children')) {
            $item.addClass('hover');
            $item.children('ul').show();
            $item.children('ul').find('a').first().focus();
            e.preventDefault();
          }
          break;
          
        case 40: // Down arrow.
          if ($item.hasClass('has-children') && !$item.hasClass('hover')) {
            // Open submenu and focus first item.
            $item.addClass('hover');
            $item.children('ul').show();
            $item.children('ul').find('a').first().focus();
            e.preventDefault();
          } else {
            // Move to next item.
            var $nextItem = $item.next();
            if ($nextItem.length) {
              $nextItem.children('a').focus();
              e.preventDefault();
            }
          }
          break;
          
        case 27: // Escape.
          if ($parentItem.hasClass('labdoo-admin-menu-item') || $parentItem.hasClass('labdoo-admin-submenu-item')) {
            $parentItem.removeClass('hover');
            $parentMenu.hide();
            $parentItem.children('a').focus();
            e.preventDefault();
          }
          break;
      }
    });

    // Close submenus when clicking outside.
    $(document).on('click', function (e) {
      if (!$(e.target).closest('.labdoo-admin-toolbar').length) {
        $toolbar.find('.hover').removeClass('hover');
        $toolbar.find('.labdoo-admin-submenu, .labdoo-admin-submenu-level2').hide();
      }
    });
  }

})(jQuery, Drupal, once);