// ispag-data-init.js

jQuery(document).ready(function($) {
    
    // --- Initialisation TinyMCE globale ---
    if (typeof tinymce !== 'undefined' && typeof ispagNoteData !== 'undefined' && ispagNoteData.tinymceUrl) {
        tinymce.baseURL = ispagNoteData.tinymceUrl;
        
        if (typeof window.tinyMCEPreInit !== 'undefined') {
            window.tinyMCEPreInit.baseURL = ispagNoteData.tinymceUrl;
            
            const editorID = 'note-text-area';
            if (typeof window.tinyMCEPreInit.mceInit === 'undefined') {
                window.tinyMCEPreInit.mceInit = {};
            }
            
            if (typeof window.tinyMCEPreInit.mceInit[editorID] === 'undefined') {
                 window.tinyMCEPreInit.mceInit[editorID] = {
                     base_url: ispagNoteData.tinymceUrl, 
                     suffix: '.min',
                     plugins: 'advlist autolink lists link image charmap preview anchor searchreplace code fullscreen insertdatetime media table paste help wordcount',
                     toolbar1: 'undo redo | formatselect | bold italic underline backcolor | alignleft aligncenter alignright alignjustify | bullist numlist | outdent indent | removeformat ',
                     height: 200, 
                     menubar: false,
                     selector: '#' + editorID,
                     skin: 'lightgray', 
                     theme: 'modern'
                 };
            }
        }
    }
    
    // --- Définition de la fonction initializeTinyMCE (laisser ici si elle est appelée par ispag-creation-modal) ---
    window.initializeTinyMCE = function() {
        const editorID = 'note-text-area';
        if (tinymce.get(editorID)) {
            tinymce.get(editorID).remove();
        }
        
        let config = {};
        if (typeof window.tinyMCEPreInit !== 'undefined' && window.tinyMCEPreInit.mceInit && window.tinyMCEPreInit.mceInit[editorID]) {
            config = window.tinyMCEPreInit.mceInit[editorID];
        }
        
        config = $.extend(true, {
            selector: '#' + editorID,
            height: 200,
            // ... (reste de votre configuration TinyMCE) ...
            setup: function (editor) {
                editor.on('init', function () {
                    editor.focus(false);
                });
            }
        }, config); 
        
        tinymce.init(config);
    };

    // --- Fonction utilitaire stripslashes_js ---
    window.stripslashes_js = function(str) {
        if (typeof str !== 'string' || str === null) {
            return '';
        }
        return str.replace(/\\(.)/g, '$1');
    };

});