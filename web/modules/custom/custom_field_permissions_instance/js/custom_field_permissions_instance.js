/**
 * @file
 * Hide the permissions grid for all field permission types except custom_group.
 */

(function ($) {

  Drupal.behaviors.customFieldPermissionsInsance = {
    attach: function (context, settings) {

      var PemTable = $(context).find('#group_perms');
      var PermDefaultType = $(context).find('#edit-type input:checked');
      var PermInputType = $(context).find('#edit-type input');
      /*init*/
      if (PermDefaultType.val() != 'custom_group') {
        PemTable.hide();
      }
      /*change*/
      PermInputType.on('change', function () {
        var typeVal = $(this).val();
        if (typeVal != 'custom_group') {
          PemTable.hide();
        }
        else {
          PemTable.show();
        }
      });

    }};
})(jQuery);
