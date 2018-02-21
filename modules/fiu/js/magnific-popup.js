(function ($, Drupal) {

  'use strict';

  Drupal.behaviors.magnific_popup = {
    attach: function (context, settings) {
      var items = [];

      $('.fiu-image-info').each(function () {
        items.push({
          src: $(this).html(),
          type: 'inline'
        });
      });

      $('.fiu-image-details').magnificPopup({
        items: items,
        gallery: {
          enabled: true
        },
        callbacks: {
          change: function () {
            this.content.on('change', function (event) {
              var val = event.target.value;
              var id = event.target.id;
              var itemNumber = event.target.getAttribute('data-item-number');
              $('.fiu-image-info .attr #' + id).attr('value', val);

              /* Change item */
              var changedItem = $('.fiu-image-info .attr #' + id).parents('.fiu-image-info').html();
              mfp.items[itemNumber].src = changedItem;
            });
          }
        }
      });

      var mfp = $.magnificPopup.instance;

    }
  };

})(jQuery, Drupal);
