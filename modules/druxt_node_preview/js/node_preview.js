(function (Drupal, $) {
  Drupal.behaviors.myTheme = {
    attach: function attach(context) {
      if (context === document) {
        $(context).on('drupalViewportOffsetChange.toolbar', function (event, offsets) {
          var top = offsets.top + parseInt($('.node-preview-container').css('height'))
          var right = offsets.right
          var bottom = offsets.bottom
          var left = offsets.left

          $('.druxt-node-preview iframe')
            .css('top', top)
            .css('right', right)
            .css('bottom', bottom)
            .css('left', `var(--ginVerticalToolbarOffset, ${left}px)`)
            .css('height', `calc(100vh - ${top}px - ${bottom}px)`)
            .css('width', `calc(100vw - var(--ginVerticalToolbarOffset, ${left}px) - ${right}px)`)
        });
      }
    }
  };
})(Drupal, jQuery)
