/**
 * TVM Tracker Admin Interactivity (Sources Page)
 * Handles:
 * 1. Collapsible Type Groups.
 * 2. Dynamic state and styling for Source/Region selection.
 *
 * @since 2.2.1
 */

jQuery(document).ready(function($) {
    'use strict';

    // --- Utility Function to Update Row State ---

    /**
     * Checks if any region checkbox within a service row is checked and updates the visual state.
     * @param {jQuery} $row The .tvm-service-row element.
     */
    function updateServiceRowState($row) {
        // Find all checkboxes in the region column
        const $regionCheckboxes = $row.find('.tvm-service-regions-col input[type="checkbox"]');
        
        // Check if ANY of them are checked
        const isAnyRegionChecked = $regionCheckboxes.is(':checked');

        // Find the visual elements to update
        const $iconCard = $row.find('.tvm-service-icon-card');

        // Toggle the 'is-enabled' class on the row and the icon column
        $row.toggleClass('is-enabled', isAnyRegionChecked);
        $iconCard.toggleClass('is-enabled', isAnyRegionChecked);

        // NOTE: The individual region flags rely on the .is-enabled class being toggled on the <label> in the click handler below.
    }


    // --- 1. Collapsible Type Group Logic ---
    $('.tvm-source-selection-nested').on('click', '.tvm-region-header', function(e) {
        e.preventDefault();
        
        const $header = $(this);
        const $group = $header.closest('.tvm-type-group');
        const $content = $group.find('>.tvm-region-content');
        const $iconUp = $header.find('.dashicons-arrow-up');
        const $iconDown = $header.find('.dashicons-arrow-down');

        // Toggle visibility of the content
        $content.slideToggle(300, function() {
            // Update class and icons after animation
            $group.toggleClass('is-open');
            if ($group.hasClass('is-open')) {
                $iconUp.show();
                $iconDown.hide();
            } else {
                $iconUp.hide();
                $iconDown.show();
            }
        });
    });


    // --- 2. Service Row Click (Toggle All Regions) ---
    $('.tvm-source-selection-nested').on('click', '.tvm-service-icon-card', function(e) {
        e.preventDefault();
        
        const $iconCard = $(this);
        const $row = $iconCard.closest('.tvm-service-row');
        const $regionCheckboxes = $row.find('.tvm-service-regions-col input[type="checkbox"]');
        const $regionLabels = $row.find('.tvm-service-regions-col .tvm-region-flag');

        // Determine if we should check (true) or uncheck (false). 
        // If the row is currently NOT enabled, we want to enable all.
        const shouldEnable = !$row.hasClass('is-enabled'); 

        // Toggle the checked state of all region checkboxes and their visual labels
        $regionCheckboxes.prop('checked', shouldEnable);
        $regionLabels.toggleClass('is-enabled', shouldEnable);

        // Update the visual state of the parent service row
        updateServiceRowState($row);
    });


    // --- 3. Region Flag Click (Toggle Individual Region) ---
    $('.tvm-source-selection-nested').on('click', '.tvm-region-flag', function(e) {
        // NOTE: The standard browser click still fires the change event on the hidden input,
        // but we manage the visual state here for instant feedback.
        
        const $label = $(this);
        const $checkbox = $label.find('input[type="checkbox"]');
        const $row = $label.closest('.tvm-service-row');

        // Toggle the visual class based on the checkbox's *new* state
        // We use !.is(':checked') because this click happens just *before* the browser natively updates the checkbox state.
        $label.toggleClass('is-enabled', !$checkbox.is(':checked'));

        // Allow the default behavior to change the checkbox state, and then update the parent row's status
        // Use a timeout to ensure the DOM has updated before we read the state
        setTimeout(function() {
            updateServiceRowState($row);
        }, 10); 
    });


    // --- Initialization: Ensure initial collapse state is applied ---
    // Since the PHP renders everything 'is-open', we collapse if the 'is-open' class is not manually removed.
    $('.tvm-type-group:not(.is-open) > .tvm-region-content').hide();
});
