/**
 * @file
 * Hide the permissions grid for all field permission types except custom_instance.
 */

document.addEventListener('DOMContentLoaded', () => {
  Drupal.behaviors.customFieldPermissionsInstance = {
    attach: function (context) {
      const PemTable = context.querySelector('#instance_perms');
      const PermDefaultType = context.querySelector('#edit-type input:checked');
      const PermInputTypes = context.querySelectorAll('#edit-type input');

      // Initialize
      if (PermDefaultType && PermDefaultType.value !== 'custom_instance') {
        PemTable.style.display = 'none';
      }

      // Add event listener for changes
      PermInputTypes.forEach((input) => {
        input.addEventListener('change', function () {
          if (this.value !== 'custom_instance') {
            PemTable.style.display = 'none';
          } else {
            PemTable.style.display = '';
          }
        });
      });


      once('customFieldPermissionsInstance', 'table[id*=edit-instance-perms]').forEach((table) => {
        console.log('fdf');
        // On a site with many roles and permissions, detach the table for performance.
        let ancestor;
        let method;

        if (table.previousElementSibling) {
          ancestor = table.previousElementSibling;
          method = 'after';
        } else {
          ancestor = table.parentElement;
          method = 'appendChild';
        }

        // Detach the table from the DOM.
        const detachedTable = table.parentNode.removeChild(table);

        // Create dummy checkboxes.
        const dummyCheckbox = document.createElement('input');
        dummyCheckbox.type = 'checkbox';
        dummyCheckbox.classList.add('dummy-checkbox', 'js-dummy-checkbox');
        dummyCheckbox.disabled = true;
        dummyCheckbox.checked = true;
        dummyCheckbox.title = Drupal.t('This permission is inherited from the authenticated user role.');
        dummyCheckbox.style.display = 'none';

        // Process real checkboxes in the table.
        detachedTable
          .querySelectorAll('input[type="checkbox"]:not(.js-rid-anonymous, .js-rid-authenticated)')
          .forEach((checkbox) => {
            checkbox.classList.add('real-checkbox', 'js-real-checkbox');
            checkbox.parentNode.insertBefore(dummyCheckbox.cloneNode(true), checkbox.nextSibling);
          });

        // Initialize the authenticated user checkbox.
        detachedTable
          .querySelectorAll('input[type="checkbox"].js-rid-authenticated')
          .forEach((authCheckbox) => {
            authCheckbox.addEventListener('click', togglePermission);
            togglePermission.call(authCheckbox); // Simulate triggering the toggle handler.
          });

        // Re-insert the table into the DOM.
        if (method === 'after') {
          ancestor.parentNode.insertBefore(detachedTable, ancestor.nextSibling);
        } else if (method === 'appendChild') {
          ancestor.appendChild(detachedTable);
        }
      });

      /**
       * Function to handle permission toggling.
       */
      function togglePermission() {
        // Example of toggle functionality; replace with actual logic if needed.
        const isChecked = this.checked;
        const relatedInputs = this.closest('tr').querySelectorAll('.js-real-checkbox:not(.js-rid-administrator)');
        relatedInputs.forEach((input) => {

          input.checked = isChecked;
        });
      }

    },
  };
});
