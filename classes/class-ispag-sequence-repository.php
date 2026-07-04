<?php
class ISPAG_Sequence_Repository {
    
    const TABLE_SEQUENCES = 'ispag_sequences';
    const TABLE_STEPS     = 'ispag_sequence_steps';

    /**
     * Sauvegarde complète (Séquence + Étapes)
     */
    public function save_full_sequence($data) {
        global $wpdb;
        $table_seq   = $wpdb->prefix . self::TABLE_SEQUENCES;
        $table_steps = $wpdb->prefix . self::TABLE_STEPS;

        // NETTOYAGE : stripslashes_deep est essentiel pour TinyMCE (évite les \" dans le HTML)
        $data = stripslashes_deep($data);

        $sequence_id = !empty($data['id']) ? intval($data['id']) : 0;
        
        $wpdb->query('START TRANSACTION');

        try {
            // 1. GESTION DE LA SÉQUENCE (PARENTE)
            if ($sequence_id > 0) {
                $wpdb->update($table_seq, [
                    'name'        => sanitize_text_field($data['name']),
                    'description' => sanitize_textarea_field($data['description']),
                    'updated_at'  => current_time('mysql')
                ], ['id' => $sequence_id]);
                
                // On vide les anciennes étapes pour réinsérer les nouvelles (propre pour le Drag & Drop)
                $wpdb->delete($table_steps, ['sequence_id' => $sequence_id]);
            } else {
                $wpdb->insert($table_seq, [
                    'name'        => sanitize_text_field($data['name']),
                    'description' => sanitize_textarea_field($data['description']),
                    'is_active'   => 1, // Activée par défaut à la création
                    'created_by'  => get_current_user_id(),
                    'created_at'  => current_time('mysql'),
                    'updated_at'  => current_time('mysql')
                ]);
                $sequence_id = $wpdb->insert_id;
            }

            // 2. GESTION DES ÉTAPES (ENFANTS)
            if (!empty($data['steps']) && is_array($data['steps'])) {
                foreach ($data['steps'] as $index => $step) {
                    
                    $wpdb->insert($table_steps, [
                        'sequence_id' => $sequence_id,
                        'step_number' => $index + 1,
                        'action_type' => sanitize_text_field($step['type']),
                        'delay_days'  => intval($step['delay']),
                        
                        // LOGIQUE DE CONDITION
                        'condition_type'       => sanitize_text_field($step['condition_type']),
                        'condition_operator'   => sanitize_text_field($step['condition_operator']),
                        'condition_value'      => sanitize_text_field($step['condition_value']),
                        'if_false_step_number' => !empty($step['if_false_step']) ? intval($step['if_false_step']) : null, // <--- AJOUTE CETTE LIGNE
                        
                        // TEXTES
                        'objective'   => sanitize_text_field($step['objective']),
                        'value_added' => sanitize_text_field($step['value_added']),
                        'subject'     => sanitize_text_field($step['subject']),
                        'content'     => wp_kses_post($step['content'])
                    ]);
                }
            }

            $wpdb->query('COMMIT');
            return $sequence_id;

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            // error_log("[ISPAG CRM] Erreur sauvegarde séquence : " . $e->getMessage());
            return false;
        }
    }

    /**
     * Récupère une séquence et ses étapes
     */
    public function get_sequence($id) {
        global $wpdb;
        $table_seq = $wpdb->prefix . self::TABLE_SEQUENCES;
        $table_steps = $wpdb->prefix . self::TABLE_STEPS;

        $sequence = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_seq WHERE id = %d", $id));
        if ($sequence) {
            $sequence->steps = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_steps WHERE sequence_id = %d ORDER BY step_number ASC", $id));
        }
        return $sequence;
    }

    public function enroll($contact_id, $sequence_id, $deal_id = null) {
        global $wpdb;
        $table_steps = $wpdb->prefix . self::TABLE_STEPS;
        $table_enroll = $wpdb->prefix . 'ispag_sequence_enrollments';

        // 1. Trouver la première étape
        $first_step = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_steps WHERE sequence_id = %d ORDER BY step_number ASC LIMIT 1", 
            $sequence_id
        ));

        if (!$first_step) return false;

        $owner = $contact_id;

        if($deal_id){
            $deal_repo = new ISPAG_Crm_Deals_Repository();
            $deal = $deal_repo->get_deal_by_id($deal_id);
            $owner = $deal->deal_owner;
        }

        // 2. Calculer la date (Aujourd'hui + délai de l'étape 1)
        $scheduled_date = date('Y-m-d H:i:s', strtotime("+{$first_step->delay_days} days"));

        // 3. Inscription (avec deal_id)
        return $wpdb->insert($table_enroll, [
            'contact_id'       => $owner,
            'deal_id'          => $deal_id, // Ajout de la colonne
            'sequence_id'      => $sequence_id,
            'status'           => 'ACTIVE',
            'current_step_id'  => 1,
            'next_step_date'   => $scheduled_date,
            'enrolled_at'      => current_time('mysql')
        ]);
    }

    /**
     * Scanne et exécute les étapes de séquences planifiées (Version Sécurisée)
     */
    public function process_scheduled_steps() {
        global $wpdb;
        $table_enroll = $wpdb->prefix . 'ispag_sequence_enrollments';
        $table_steps  = $wpdb->prefix . self::TABLE_STEPS;
        $now = current_time('mysql');
        
        // 1. Nettoyage des verrous expirés
        $wpdb->query("UPDATE $table_enroll SET status = 'ACTIVE', lock_token = NULL 
                    WHERE status = 'PROCESSING' AND next_step_date < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

        $lock_id = uniqid('ispag_lock_', true);

        // 2. Verrouillage
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_enroll SET status = 'PROCESSING', lock_token = %s 
            WHERE status = 'ACTIVE' AND next_step_date <= %s",
            $lock_id, $now
        ));

        $pending = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_enroll WHERE status = 'PROCESSING' AND lock_token = %s",
            $lock_id
        ));

        if (empty($pending)) return;

        foreach ($pending as $enrollment) {
            $step = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_steps WHERE sequence_id = %d AND step_number = %d",
                $enrollment->sequence_id, $enrollment->current_step_id
            ));

            if (!$step) {
                $wpdb->update($table_enroll, ['status' => 'ERROR'], ['id' => $enrollment->id]);
                continue;
            }

            // --- NOUVELLE LOGIQUE : VÉRIFICATION DE LA CONDITION ---
            if (!$this->check_step_condition($enrollment, $step)) {
                // error_log("[ISPAG CRM] Condition FAUSSE pour l'enrôlement {$enrollment->id}. Saut d'étape.");
                
                // Si la condition est fausse, on ne fait pas l'action, on saute directement
                $this->move_to_next_step($enrollment, $step, true); // true = saut forcé
                continue;
            }

            $item = (object) array_merge((array) $enrollment, (array) $step);
            
            if ($step->action_type === 'EMAIL') {
                $success = $this->execute_email_step($item);
            } else {
                $success = $this->create_manual_task($item);
            }

            if ($success) {
                $this->move_to_next_step($enrollment, $step);
            } else {
                $wpdb->update($table_enroll, ['status' => 'ACTIVE', 'lock_token' => null], ['id' => $enrollment->id]);
            }
        }
    }

    private function execute_email_step($item) {
        // error_log("[ISPAG CRM] execute_email_step : Début");

        if (!class_exists('ISPAG_Crm_Contacts_Repository')) {
            // error_log("[ISPAG CRM] FATAL : La classe ISPAG_Crm_Contacts_Repository n'est pas chargée !");
            return false;
        }

        $contact_repo = new ISPAG_Crm_Contacts_Repository();
        $contact = $contact_repo->get_contact_by_id($item->contact_id);
        
        // --- AJOUT DU LOG COMPLET ICI ---
        if ($contact) {
            // error_log("[ISPAG CRM] DEBUG CONTACT COMPLET : " . print_r($contact, true));
        } else {
            // error_log("[ISPAG CRM] DEBUG CONTACT : Le repository a retourné NULL pour l'ID " . $item->contact_id);
        }
        // --------------------------------

        $email = $contact->email ?? '';
        if (empty($email)) {
            // error_log("[ISPAG CRM] ERREUR : Email vide pour le contact ID {$item->contact_id}."); 
            return false;
        }

        // error_log("[ISPAG CRM] Envoi vers : $email | Sujet : {$item->subject}");

        // $message = str_replace('{{first_name}}', ($contact->display_name ?? 'Client'), $item->content);

        $message = $this->parse_sequence_variables($item->content, $contact, $item->deal_id);
        $subject = $this->parse_sequence_variables($item->subject ?: "Relance ISPAG", $contact, $item->deal_id);

        // On génère le mail complet en passant l'ID du contact pour la signature
        $full_html_email = ISPAG_Crm_Signature_Service::wrap_message($message, $item->sender_id);

        // --- CONFIGURATION DES HEADERS (AVEC COPIE) ---
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'Cc: log@mg.ispag-asp.com',
            'Cc: c.barthel@ispag-asp.ch'
        );
        
        $sent = wp_mail($email, $subject, $full_html_email, $headers);
        
        // error_log($sent ? "[ISPAG CRM] wp_mail : SUCCÈS" : "[ISPAG CRM] wp_mail : ÉCHEC");
        return $sent;
    }

    private function move_to_next_step($enrollment, $current_step, $is_condition_jump = false) {
        global $wpdb;
        $table_enroll = $wpdb->prefix . 'ispag_sequence_enrollments';
        $table_steps  = $wpdb->prefix . self::TABLE_STEPS;

        // Calcul du numéro de la prochaine étape
        $next_step_num = intval($current_step->step_number) + 1;

        // Si la condition a échoué ET qu'un saut est défini
        if ($is_condition_jump && !empty($current_step->if_false_step_number)) {
            $next_step_num = intval($current_step->if_false_step_number);
        }

        $next_step = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_steps WHERE sequence_id = %d AND step_number = %d",
            $current_step->sequence_id,
            $next_step_num
        ));

        if ($next_step) {
            // Si c'est un saut de condition, on peut exécuter l'étape suivante IMMÉDIATEMENT (0 jours)
            // ou garder le délai de l'étape cible. Ici, on prend le délai de l'étape cible.
            $delay = intval($next_step->delay_days);
            $next_date = date('Y-m-d H:i:s', strtotime("+{$delay} days"));
            
            $wpdb->update($table_enroll, [
                'current_step_id' => $next_step_num,
                'next_step_date'  => $next_date,
                'status'          => 'ACTIVE',
                'lock_token'      => null
            ], ['id' => $enrollment->id]);
        } else {
            // Pas de step suivant = Terminé
            $wpdb->update($table_enroll, [
                'status'        => 'COMPLETED',
                'completed_at'  => current_time('mysql'),
                'lock_token'    => null
            ], ['id' => $enrollment->id]);
        }
    }

    private function create_manual_task($item) {
        $contact_repo = new ISPAG_Crm_Contacts_Repository();
        $contact = $contact_repo->get_contact_by_id($item->contact_id);

        $deal_repo = new ISPAG_Crm_Deals_Repository();
        $deal = $deal_repo->get_deal_by_id($item->deal_id);

        $parsed_content = $this->parse_sequence_variables($item->content, $contact, $item->deal_id);

        // Préparation des données avec les VRAIS noms de colonnes SQL
        $task_data = [
            'contact_id' => $deal->associated_contact_ids, // La DB attend du varchar(255)
            'company_id' => $deal->associated_company_id, // La DB attend du varchar(255)
            'deal_id'    => $deal->deal_group_ref,             // Correspond à la DB
            'created_by' => $deal->deal_owner,                          // CORRIGÉ (était created_by)
            'type'       => 'TASK',                     // Correspond à la DB
            'title'      => 'SEQUENCE',                 // Correspond à la DB
            'content'    => "Séquence : " . ($item->objective ?? 'Suivi') . "\n\n" . $parsed_content,
            'is_task'    => 1,                          // INDISPENSABLE pour ta table
            'is_completed'     => 0,                  // Correspond à la DB (en minuscule selon tes logs)
            'due_date'   => current_time('mysql'),      // AJOUTÉ pour l'affichage dans le planning
            'created_at' => current_time('mysql')       // AJOUTÉ par sécurité
        ];

      
        // On appelle directement le manager de notes
        if (class_exists('ISPAG_Note_Manager')) {
            // Si tu as une instance globale ou accessible, utilise-la, 
            // sinon on instancie le repository nécessaire pour le manager
            $note_repo = new ISPAG_Note_Repository(); 
            $note_manager = new ISPAG_Note_Ajax_Handler($note_repo);
            
            // error_log("[ISPAG CRM] Appel direct de handle_save_note pour : " . $item->contact_id);
            $note_manager->handle_save_note($task_data, null, $item->contact_id, true);
        }
        
        return true; 
    }

    /**
     * Remplace les variables {{tag}} par les vraies valeurs
     */
    private function parse_sequence_variables($content, $contact, $deal_id = null) {
        // Valeurs par défaut pour le contact
        $variables = [
            '{{contact_first_name}}'    => !empty($contact->first_name) ? $contact->first_name : ($contact->display_name ?? 'Client'),
            '{{last_name}}'             => !empty($contact->last_name) ? $contact->last_name : '',
            '{{contact_email}}'         => $contact->email ?? '',
            '{{contact_phone}}'         => $contact->phone ?? '',
        ];

        // Si un deal_id est lié à l'enrôlement, on peut ajouter des variables liées au Deal
        if ($deal_id) {
            // global $wpdb;
            // $table_deals = $wpdb->prefix . 'ispag_deals';
            // $deal = $wpdb->get_row($wpdb->prepare("SELECT name, offer_num FROM $table_deals WHERE id = %d", $deal_id));
            $deal_repo = new ISPAG_Crm_Deals_Repository();
            $deal = $deal_repo->get_deal_by_id($deal_id);
            
            if ($deal) {
                $variables['{{deal_name}}']         = $deal->project_name;
                $variables['{{deal_offer_num}}']    = $deal->offer_num ?? '';
                $variables['{{deal_project_num}}']  = $deal->project_num ?? '';
                $variables['{{deal_closing_date}}'] = $deal->closing_date  ?? '';
            }
        }

        // On applique tous les remplacements
        return str_replace(array_keys($variables), array_values($variables), $content);
    }

    private function check_step_condition($enrollment, $step) {
        if (empty($step->condition_type)) return true; // Pas de condition = on exécute

        global $wpdb;
        $note_table =ISPAG_Note_Manager::TABLE_NOTE;

        switch ($step->condition_type) {
            case 'DEAL_AMOUNT':
                $deal_repo = new ISPAG_Crm_Deals_Repository();
                $deal = $deal_repo->get_deal_by_id($enrollment->deal_id);
                if (!$deal) return true;

                $amount = floatval($deal->amount); // Assure-toi que la colonne s'appelle 'amount'
                $val = floatval($step->condition_value);
                $op = $step->condition_operator;

                if ($op === '>') return ($amount > $val);
                if ($op === '<') return ($amount < $val);
                if ($op === '=') return ($amount == $val);
                break;

            case 'LAST_CONTACT':
                // On vérifie la date de la dernière note/activité pour ce contact
                $last_contact_date = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(created_at) FROM {$note_table} WHERE contact_id = %d",
                    $enrollment->contact_id
                ));
                
                if (!$last_contact_date) return true;

                $days_since = (strtotime(current_time('mysql')) - strtotime($last_contact_date)) / (60 * 60 * 24);
                $val = intval($step->condition_value);
                
                // Si condition est "> 10 jours", on retourne vrai si $days_since > 10
                return ($days_since > $val);

            case 'MAIL_OPENED':
                // Logique de tracking d'email (nécessite une table de logs d'ouverture)
                $opened = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ispag_email_logs WHERE enrollment_id = %d AND opened = 1",
                    $enrollment->id
                ));
                return ($opened > 0);
        }

        return true;
    }
}