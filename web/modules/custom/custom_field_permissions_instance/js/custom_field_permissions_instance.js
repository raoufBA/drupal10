/**
 * @file
 * Hide the permissions grid for all field permission types except custom_instance.
 */

(function ($) {

  Drupal.behaviors.customFieldPermissionsInstance = {
    attach: function (context, settings) {

      var PemTable = $(context).find('#instance_perms');
      var PermDefaultType = $(context).find('#edit-type input:checked');
      var PermInputType = $(context).find('#edit-type input');
      /*init*/
      if (PermDefaultType.val() != 'custom_instance') {
        PemTable.hide();
      }
      /*change*/
      PermInputType.on('change', function () {
        var typeVal = $(this).val();
        if (typeVal != 'custom_instance') {
          PemTable.hide();
        }
        else {
          PemTable.show();
        }
      });

    }};
})(jQuery);
