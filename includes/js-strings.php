<?php
defined('ABSPATH') || exit;

/**
 * Textes affichés par le JavaScript de ce paquet (messages d'erreur, confirmations, libellés).
 * Le JavaScript les appelle avec ispagT('Texte anglais') ; ce fichier fournit leur traduction dans la langue du site
 * (fichiers languages/ de ce paquet). Un texte absent de la liste reste tel quel (anglais).
 * Après avoir ajouté un texte dans un fichier JS, ajoutez-le ici puis lancez tools/i18n/build.py (ISPAG Project Manager).
 */
if (!function_exists('ispag_crm_js_strings')) {
    function ispag_crm_js_strings() {
        return [
        'Add a company to the project' => __('Add a company to the project', 'ispag-crm'),
        'Add a contact to the project' => __('Add a contact to the project', 'ispag-crm'),
        'Only the contacts of the project company' => __('Only the contacts of the project company', 'ispag-crm'),
        'No result.' => __('No result.', 'ispag-crm'),
        'Remove this company from the project?' => __('Remove this company from the project?', 'ispag-crm'),
        'Remove this contact from the project?' => __('Remove this contact from the project?', 'ispag-crm'),
        'This replaces the current company of the project.' => __('This replaces the current company of the project.', 'ispag-crm'),
        'Search…' => __('Search…', 'ispag-crm'),
        'Close' => __('Close', 'ispag-crm'),
        'Add' => __('Add', 'ispag-crm'),
        'AJAX configuration error.' => __('AJAX configuration error.', 'ispag-crm'),
        'AJAX connection error.' => __('AJAX connection error.', 'ispag-crm'),
        'An error occurred. Please try again.' => __('An error occurred. Please try again.', 'ispag-crm'),
        'Are you sure you want to remove this association?' => __('Are you sure you want to remove this association?', 'ispag-crm'),
        'Associer' => __('Associer', 'ispag-crm'),
        'Connection error while linking.' => __('Connection error while linking.', 'ispag-crm'),
        'Delete this step?' => __('Delete this step?', 'ispag-crm'),
        'Error during bulk update' => __('Error during bulk update', 'ispag-crm'),
        'Error loading AI summary.' => __('Error loading AI summary.', 'ispag-crm'),
        'Error marking notification as read. The page will open anyway.' => __('Error marking notification as read. The page will open anyway.', 'ispag-crm'),
        'Error while deleting.' => __('Error while deleting.', 'ispag-crm'),
        'Error while linking contacts.' => __('Error while linking contacts.', 'ispag-crm'),
        'Error while linking: ' => __('Error while linking: ', 'ispag-crm'),
        'Error while loading the document type modal.' => __('Error while loading the document type modal.', 'ispag-crm'),
        'Error while retrieving the template.' => __('Error while retrieving the template.', 'ispag-crm'),
        'Error: ' => __('Error: ', 'ispag-crm'),
        'Error: Company ID not found.' => __('Error: Company ID not found.', 'ispag-crm'),
        'Error: The entity ID is missing.' => __('Error: The entity ID is missing.', 'ispag-crm'),
        'Error: Unable to load the company panel.' => __('Error: Unable to load the company panel.', 'ispag-crm'),
        'Error: Unable to load the panel.' => __('Error: Unable to load the panel.', 'ispag-crm'),
        'Folder created!' => __('Folder created!', 'ispag-crm'),
        'Give your sequence a name!' => __('Give your sequence a name!', 'ispag-crm'),
        'Inconnue' => __('Inconnue', 'ispag-crm'),
        'Loading error' => __('Loading error', 'ispag-crm'),
        'Marquer comme lue' => __('Marquer comme lue', 'ispag-crm'),
        'Network error lors de l\'upload.' => __('Network error lors de l\'upload.', 'ispag-crm'),
        'Network error lors de la liaison.' => __('Network error lors de la liaison.', 'ispag-crm'),
        'No attachment found.' => __('No attachment found.', 'ispag-crm'),
        'No contact found.' => __('No contact found.', 'ispag-crm'),
        'Please select a sequence.' => __('Please select a sequence.', 'ispag-crm'),
        'Please select a template.' => __('Please select a template.', 'ispag-crm'),
        'Please select at least one company.' => __('Please select at least one company.', 'ispag-crm'),
        'Please select at least one contact.' => __('Please select at least one contact.', 'ispag-crm'),
        'Save' => __('Save', 'ispag-crm'),
        'Save error: ' => __('Save error: ', 'ispag-crm'),
        'Save failed.' => __('Save failed.', 'ispag-crm'),
        'Select a template.' => __('Select a template.', 'ispag-crm'),
        'Start' => __('Start', 'ispag-crm'),
        'Upload error: ' => __('Upload error: ', 'ispag-crm'),
        'Valider' => __('Valider', 'ispag-crm'),
        'You have unsaved changes. Do you really want to leave without saving?' => __('You have unsaved changes. Do you really want to leave without saving?', 'ispag-crm'),
        ];
    }

    /** Dictionnaire {texte anglais → texte traduit} injecté dans la page ; seuls les textes réellement traduits sont envoyés. */
    function ispag_crm_print_js_i18n() {
        $map = array_filter(ispag_crm_js_strings(), function ($translated, $english) { return $translated !== $english; }, ARRAY_FILTER_USE_BOTH);
        echo '<script>window.ISPAG_JS_I18N=Object.assign(window.ISPAG_JS_I18N||{},' . wp_json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ');'
            . 'window.ispagT=function(s){var d=window.ISPAG_JS_I18N||{};return Object.prototype.hasOwnProperty.call(d,s)?d[s]:s};</script>' . "\n";
    }
    add_action('wp_head', 'ispag_crm_print_js_i18n', 1);
    add_action('admin_head', 'ispag_crm_print_js_i18n', 1);
}
