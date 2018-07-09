document.addEventListener('DOMContentLoaded', function() {
    // Handling "Save & Go to Next Form" button.
    if (formRenderSkipLogic.nextStepPath) {
        formRenderSkipLogic.saveNextForm = function() {
            appendHiddenInputToForm('save-and-redirect', formRenderSkipLogic.nextStepPath);
            dataEntrySubmit('submit-btn-savecontinue');
            return false;
        }

        // Overriding submit callback.
        $('[id="submit-btn-savenextform"]').attr('onclick', 'formRenderSkipLogic.saveNextForm()');
    }
    else {
        removeButtons('savenextform');
    }

    // Handling "Ignore and go to next form" button on required fields
    // dialog.
    $('#reqPopup').on('dialogopen', function(event, ui) {
        var buttons = $(this).dialog('option', 'buttons');

        $.each(buttons, function(i, button) {
            if (button.name !== 'Ignore and go to next form') {
                return;
            }

            if (formRenderSkipLogic.nextStepPath) {
                buttons[i] = function() {
                    window.location.href = formRenderSkipLogic.nextStepPath;
                };
            }
            else {
                delete buttons[i];
            }

            return false;
        });

        $(this).dialog('option', 'buttons', buttons);
    });

    /**
     * Removes the given submit buttons set.
     */
    function removeButtons(buttonName) {
        var $buttons = $('button[name="submit-btn-' + buttonName + '"]');

        // Check if buttons are outside the dropdown menu.
        if ($buttons.length !== 0) {
            $.each($buttons, function(index, button) {
                // Get first button in dropdown-menu.
                var replacement = $(button).siblings('.dropdown-menu').find('a')[0];

                // Modify button to behave like $replacement.
                button.id = replacement.id;
                button.name = replacement.name;
                button.onclick = replacement.onclick;
                button.innerHTML = replacement.innerHTML;

                // Get rid of replacement.
                $(replacement).remove();
            });
        }
        else {
            // Disable button inside the dropdown menu.
            // Obs.: yes, this is a weird selector - "#" prefix is not being
            // used - but this approach is needed on this page because there
            // are multiple DOM elements with the same ID - which is
            // totally wrong.
            $('a[id="submit-btn-' + buttonName + '"]').hide();
        }
    }
});
