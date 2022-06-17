(function (Drupal, $) {
    Drupal.behaviors.myTheme = {
      attach: function attach(context) {
        if (context === document) {
          $(context).on('drupalViewportOffsetChange.toolbar', function (event, offsets) {
            $('.druxt-node-preview iframe')
              .css('top', offsets.top + parseInt($('.node-preview-container').css('height')))
              .css('right', offsets.right)
              .css('bottom', offsets.bottom)
              .css('left', offsets.left)
          });
        }
      }
    };
  })(Drupal, jQuery)
  