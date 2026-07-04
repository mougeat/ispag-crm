document.addEventListener('DOMContentLoaded', function() {
    const profilPlaceholder   = document.querySelector('.ispag-ai-profil-placeholder');
    const placeholder         = document.querySelector('.ispag-ai-placeholder');
    const actionPlaceholder   = document.querySelector('.ispag-ai-actions-placeholder');
    if (!placeholder) return;

    const companyId      = placeholder.getAttribute('data-company-id');
    const contactId      = placeholder.getAttribute('data-contact-id');
    const tabIntelligence = document.getElementById('ispag-tab-intelligence');
    const tabAbout        = document.getElementById('ispag-tab-about');

    // ── Résumé général (comportement existant) ────────────────────────────
    function loadGeminiSummary() {
        if (!companyId && !contactId) return;

        const data = new URLSearchParams();
        if (companyId) {
            data.append('action', 'ispag_load_gemini_company_summary');
        } else if (contactId) {
            data.append('action', 'ispag_load_gemini_contact_summary');
        }
        data.append('company_id', companyId);
        data.append('contact_id', contactId);
        data.append('_ajax_nonce', ispag_ajax.nonce);

        fetch(ispag_ajax.ajax_url, { method: 'POST', body: data })
            .then(r => r.json())
            .then(result => {
                if (result.success && result.data.html) {
                    placeholder.innerHTML = result.data.html;
                    placeholder.classList.remove('ispag-ai-placeholder');
                    actionPlaceholder.innerHTML = result.data.actions;
                    profilPlaceholder.innerHTML = result.data.profil;

                    const scoreContainer = document.getElementById('health_score');
                    if (scoreContainer) scoreContainer.innerText = result.data.health_score;

                    const infoIcon = document.querySelector('.ispag-info-icon');
                    if (infoIcon) infoIcon.title = result.data.explication_health_score;
                } else {
                    placeholder.innerHTML     = '<p class="ispag-ai-error">Error loading AI summary.</p>';
                    actionPlaceholder.innerHTML = '<p class="ispag-ai-error">Error loading AI actions.</p>';
                }
            })
            .catch(error => {
                console.error('AI AJAX error:', error);
                placeholder.innerHTML = '<p class="ispag-ai-error">Network error loading AI summary.</p>';
            });
    }

    // ── Préparation de réunion ────────────────────────────────────────────
    function loadMeetingPrep() {
        if (!contactId) return;

        const btn = document.querySelector('.btn-meeting-prep');

        // État de chargement
        if (btn) {
            jQuery('#meeting-preparation').prop('disabled', true).val(ispag_ajax.i18n?.preparing + '...');

        }

        const data = new URLSearchParams({
            action:      'ispag_load_contact_meeting_prep',
            contact_id:  contactId,
            _ajax_nonce: ispag_ajax.nonce,
        });

        fetch(ispag_ajax.ajax_url, { method: 'POST', body: data })
            .then(r => r.json())
            .then(result => {
                if (!result.success) throw new Error(result.data?.message ?? 'Error');

                const d = result.data;

                // ── Affichage des cartes dans le panneau intelligence ──
                const meetingContainer = document.querySelector('.ispag-meeting-prep-container');
                if (meetingContainer) {
                    meetingContainer.innerHTML = `
                        <h4 class="ispag-meeting-title">${d.title}</h4>
                        ${d.objectives}
                        ${d.questions}
                        ${d.attention}
                        ${d.agenda}
                        ${d.hook}
                    `;
                    meetingContainer.style.display = 'block';
                }

                // ── Injection dans TinyMCE ────────────────────────────
                const editorId = 'note-text-area'; // ← adaptez si besoin
                if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
                    tinymce.get(editorId).setContent(d.tinymce_content);
                } else {
                    const textarea = document.getElementById(editorId);
                    if (textarea) textarea.value = d.tinymce_content;
                }

                // ── Scroll vers l'éditeur ─────────────────────────────
                document.getElementById(editorId)?.closest('.ispag-note-editor')
                    ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(error => {
                console.error('Meeting prep error:', error);
                const meetingContainer = document.querySelector('.ispag-meeting-prep-container');
                if (meetingContainer) {
                    meetingContainer.innerHTML = '<p class="ispag-ai-error">Error loading meeting preparation.</p>';
                }
            })
            .finally(() => {
                if (btn) {
                    // btn.disabled = false;
                    // btn.innerHTML = '<span class="dashicons dashicons-calendar-alt"></span> ' + (ispag_ajax.i18n?.prepare_meeting ?? 'Prepare meeting');
                    jQuery('#meeting-preparation').prop('disabled', false).val(ispag_ajax.i18n?.prepare_meeting ?? 'Prepare meeting');
                }
            });
    }

    // ── Écouteurs d'événements ────────────────────────────────────────────

    // Résumé IA au clic sur l'onglet intelligence
    document.querySelector('button[data-tab="intelligence"]')
        ?.addEventListener('click', function() {
            if (!placeholder.classList.contains('ispag-loaded')) {
                loadGeminiSummary();
                placeholder.classList.add('ispag-loaded');
            }
        });

    // Bouton "Préparer la réunion"
    document.querySelector('.btn-meeting-prep')
        ?.addEventListener('click', loadMeetingPrep);

    // Chargement automatique si onglet actif par défaut
    if (tabIntelligence?.classList.contains('active') || tabAbout?.classList.contains('active')) {
        loadGeminiSummary();
        placeholder.classList.add('ispag-loaded');
    }
});