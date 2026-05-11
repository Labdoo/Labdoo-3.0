(function ($, Drupal, once) {
  Drupal.behaviors.chartMapToggle = {
    attach: function (context, settings) {
      const $region = $(once('chart-map-toggle', '.region-content', context));
      if (!$region.length) return;

      const $chartBlock = $region.find('[id^="block-"][id$="chart"], [class*="chart-block"], .block-labdoo-dootronicchart').first().closest('.region-content > *');
      const $mapBlock = $region.find('.labdoo-map-container, [id*="map"]').first().closest('.region-content > *');

      if ($chartBlock.length && $mapBlock.length) {
        // Contenedor para botones de restauración si ambos se ocultan (opcional, pero mejor manejamos visibilidad)
        
        // Función para inyectar botón
        const addToggleButton = ($block, targetName, toggleClass) => {
          if (!$block.find('.' + toggleClass).length) {
            const $btn = $('<button class="toggle-visibility ' + toggleClass + '" title="Ocultar ' + targetName + '">×</button>');
            $block.prepend($btn);
          }
        };

        addToggleButton($chartBlock, 'gráfico', 'hide-chart');
        addToggleButton($mapBlock, 'mapa', 'hide-map');

        // Botón de restauración para el gráfico (si está oculto)
        const $restoreChartBtn = $('<button class="restore-visibility restore-chart" style="display:none">Mostrar Gráfico</button>');
        const $restoreMapBtn = $('<button class="restore-visibility restore-map" style="display:none">Mostrar Mapa</button>');
        
        // Insertar botones de restauración antes de la región si no existen
        let $restoreControls = $('.restore-controls');
        if (!$restoreControls.length) {
            $restoreControls = $('<div class="restore-controls"></div>');
            $region.before($restoreControls);
        }
        $restoreControls.append($restoreChartBtn).append($restoreMapBtn);

        $region.find('.hide-chart').on('click', function() {
          $chartBlock.addClass('is-hidden');
          $mapBlock.addClass('is-expanded');
          $restoreChartBtn.show();
          
          // Trigger resize for map
          setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
          }, 100);
        });

        $region.find('.hide-map').on('click', function() {
          $mapBlock.addClass('is-hidden');
          $chartBlock.addClass('is-expanded');
          $restoreMapBtn.show();
        });

        $restoreChartBtn.on('click', function() {
          $chartBlock.removeClass('is-hidden');
          $mapBlock.removeClass('is-expanded');
          $restoreChartBtn.hide();
        });

        $restoreMapBtn.on('click', function() {
          $mapBlock.removeClass('is-hidden');
          $chartBlock.removeClass('is-expanded');
          $restoreMapBtn.hide();

          // Trigger resize for map
          setTimeout(function() {
            window.dispatchEvent(new Event('resize'));
          }, 100);
        });
      }
    }
  };
})(jQuery, Drupal, once);
