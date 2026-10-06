<?php
defined('ABSPATH') || exit;

/**
 * « Fiche avant l'appel » : /appel/<id contact>/ — une page mobile, toujours à jour, ouverte depuis le lien « Fiche CRM ISPAG »
 * du contact dans le carnet de l'iPhone (voir ISPAG_Crm_Carddav_Server). Réservée aux commerciaux ISPAG (et administrateurs).
 * Contenu : points d'attention (livraison en retard / à venir, fournisseur en retard, tâches en retard), offres en cours avec montants,
 * projets en livraison, prochaines tâches, dernières actions ; et un formulaire pour consigner une action (note, appel, e-mail, tâche).
 */
class ISPAG_Crm_Call_Brief {

    const SLUG   = 'appel';
    const ACTION = 'ispag_call_brief_log';
    const NONCE  = 'ispag_call_brief';

    public function __construct() {
        add_action('parse_request', [self::class, 'maybe_serve'], 1);
        add_action('wp_ajax_' . self::ACTION, [self::class, 'ajax_log']);
    }

    public static function eligible(?WP_User $user = null): bool {
        $user = $user ?: wp_get_current_user();
        if (!$user || !$user->ID) return false;
        $roles = (array) apply_filters('ispag_sync_invite_roles', ['administrator', 'vente_ispag', 'ispag_commercial']);
        return (bool) array_intersect($roles, (array) $user->roles);
    }

    public static function url(int $contact_id): string {
        return home_url('/' . self::SLUG . '/' . $contact_id . '/');
    }

    public static function maybe_serve() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/') . '/' . self::SLUG . '/';
        if (strpos($path, $base) !== 0) return;
        $id = (int) trim(substr($path, strlen($base)), '/');
        if ($id <= 0) return;
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url(add_query_arg([]))));
            exit;
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        if (!self::eligible()) { status_header(403); self::shell(__('Restricted', 'ispag-crm'), '<p>' . esc_html__('This page is reserved to ISPAG sales.', 'ispag-crm') . '</p>'); }
        self::page($id);
    }

    // ------------------------------------------------------------------ données

    private static function money($v): string {
        return number_format((float) $v, 0, '.', "'") . ' CHF';
    }

    private static function day($ts): string { return $ts ? wp_date('d.m.Y', (int) $ts) : '—'; }

    /** Offres du contact (ouvertes) + projets en livraison + points d'attention. */
    public static function collect(int $cid): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $now = time(); $today = strtotime('today');
        $alerts = []; $deals = []; $projects = [];

        // Offres ouvertes du contact
        $stages = [];
        foreach ((array) $wpdb->get_results('SELECT stage_key, stage_label FROM ' . ISPAG_Crm_Deal_Constants::TABLE_DEAL_STAGES) as $s) $stages[$s->stage_key] = $s->stage_label;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . ISPAG_Crm_Deal_Constants::TABLE_NAME . " WHERE project_db_status = 0 AND FIND_IN_SET(%d, REPLACE(associated_contact_ids, ' ', '')) > 0 ORDER BY closing_date ASC", $cid));
        foreach ((array) $rows as $d) {
            $eff = ISPAG_Crm_Decision_Date::effective($d);
            $skey = (string) $wpdb->get_var($wpdb->prepare('SELECT current_stage_key FROM ' . ISPAG_Crm_Deal_Constants::TABLE_DEALS_STAGES . ' WHERE deal_group_ref = %s', (string) $d->deal_group_ref));
            $deals[] = [
                'ref' => (string) $d->deal_group_ref, 'name' => wp_strip_all_tags(html_entity_decode((string) $d->project_name, ENT_QUOTES, 'UTF-8')),
                'amount' => (float) $d->total_excl_vat, 'stage' => $stages[$skey] ?? $skey,
                'decision' => $eff['date'] ? strtotime($eff['date']) : 0, 'src' => $eff['source'],
            ];
            if ($eff['date'] && strtotime($eff['date']) < $today) $alerts[] = sprintf(__('Offer "%s": expected decision date is past (%s).', 'ispag-crm'), end($deals)['name'], self::day(strtotime($eff['date'])));
        }

        // Projets du contact (hors offres) : livraisons
        $projs = $wpdb->get_results($wpdb->prepare(
            "SELECT hubspot_deal_id, ObjetCommande, NumCommande FROM {$p}achats_liste_commande
             WHERE (isQotation IS NULL OR isQotation = 0) AND FIND_IN_SET(%d, REPLACE(AssociatedContactIDs, ' ', '')) > 0 ORDER BY hubspot_deal_id DESC LIMIT 30", $cid));
        foreach ((array) $projs as $pr) {
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT d.Id, d.Article, d.Qty, d.Livre, d.TimestampDateDeLivraison AS t1, d.TimestampDateDeLivraisonFin AS t2
                 FROM {$p}achats_details_commande d WHERE d.hubspot_deal_id = %d AND d.archive = 0 AND COALESCE(d.Livre, 0) = 0 AND d.TimestampDateDeLivraison > 0", $pr->hubspot_deal_id));
            if (!$items) continue;
            $name = wp_strip_all_tags(html_entity_decode((string) $pr->ObjetCommande, ENT_QUOTES, 'UTF-8'));
            $list = []; $late = 0; $soon = 0;
            foreach ($items as $it) {
                $due = max((int) $it->t1, (int) $it->t2);
                $status = $due < $today ? 'late' : ($due <= $today + 30 * DAY_IN_SECONDS ? 'soon' : 'later');
                if ($status === 'late') { $late++; $alerts[] = sprintf(__('Late delivery — %1$s (%2$s): was due %3$s.', 'ispag-crm'), wp_strip_all_tags((string) $it->Article), $name, self::day($due)); }
                if ($status === 'soon') $soon++;
                // fournisseur en retard sur cet article
                $sup = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}achats_articles_cmd_fournisseurs WHERE IdCommandeClient = %d AND COALESCE(Recu, 0) = 0 AND TimestampDateLivraisonConfirme > 0 AND TimestampDateLivraisonConfirme < %d", $it->Id, $now));
                if ($sup) $alerts[] = sprintf(__('Supplier late — %1$s (%2$s): confirmed date has passed.', 'ispag-crm'), wp_strip_all_tags((string) $it->Article), $name);
                $list[] = ['article' => wp_strip_all_tags((string) $it->Article), 'qty' => (float) $it->Qty, 'due' => $due, 'status' => $status];
            }
            usort($list, function ($a, $b) { return $a['due'] <=> $b['due']; });
            $projects[] = ['name' => $name, 'num' => (string) $pr->NumCommande, 'items' => array_slice($list, 0, 8), 'late' => $late, 'soon' => $soon, 'url' => home_url('/project-detail/' . (int) $pr->hubspot_deal_id)];
        }

        // Tâches ouvertes + dernières actions
        $notes = ISPAG_Note_Manager::TABLE_NOTE;
        $tasks = $wpdb->get_results($wpdb->prepare("SELECT id, title, content, due_date FROM {$notes} WHERE is_task = 1 AND COALESCE(is_completed, 0) = 0 AND FIND_IN_SET(%d, REPLACE(contact_id, ' ', '')) > 0 ORDER BY due_date ASC LIMIT 5", $cid));
        foreach ((array) $tasks as $t) {
            if (!empty($t->due_date) && strtotime($t->due_date) < $now - DAY_IN_SECONDS) $alerts[] = sprintf(__('Task overdue: %1$s (%2$s).', 'ispag-crm'), self::plain($t->title ?: $t->content, 70), wp_date('d.m.Y', strtotime($t->due_date)));
        }
        $recent = $wpdb->get_results($wpdb->prepare("SELECT type, title, content, created_at FROM {$notes} WHERE FIND_IN_SET(%d, REPLACE(contact_id, ' ', '')) > 0 AND type NOT IN ('SYSTEM','STAGE','HEALTH_REMINDER') ORDER BY created_at DESC LIMIT 4", $cid));

        return compact('alerts', 'deals', 'projects', 'tasks', 'recent');
    }

    private static function plain($t, int $max = 140): string {
        $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags((string) $t), ENT_QUOTES, 'UTF-8')));
        return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
    }

    // ------------------------------------------------------------------ enregistrement d'une action

    public static function ajax_log() {
        if (!is_user_logged_in() || !self::eligible() || !wp_verify_nonce($_POST['nonce'] ?? '', self::NONCE)) wp_send_json_error(['message' => 'Not authorized'], 403);
        global $wpdb;
        $cid  = absint($_POST['contact_id'] ?? 0);
        $type = strtoupper(sanitize_key($_POST['type'] ?? 'note'));
        $map  = ['NOTE' => 'NOTE', 'CALL' => 'CALL', 'EMAIL' => 'LOG_EMAIL', 'MEETING' => 'MEETING', 'TASK' => 'TASK'];
        if (!$cid || !isset($map[$type]) || !get_userdata($cid)) wp_send_json_error(['message' => 'Invalid request'], 400);
        $text = trim(sanitize_textarea_field(wp_unslash($_POST['content'] ?? '')));
        if ($text === '') wp_send_json_error(['message' => __('Write a few words first.', 'ispag-crm')], 400);
        $deal = sanitize_text_field(wp_unslash($_POST['deal_ref'] ?? ''));
        $company = '';
        if (class_exists('ISPAG_Crm_Contacts_Repository')) {
            $c = (new ISPAG_Crm_Contacts_Repository())->get_contact_by_id($cid);
            if ($c && !empty($c->companies) && is_array($c->companies)) $company = (string) (int) ($c->companies[0]->Id ?? 0);
        }
        $is_task = $type === 'TASK';
        $due = null; $remind = null;
        if ($is_task) {
            $d = sanitize_text_field(wp_unslash($_POST['due_date'] ?? ''));
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS);
            $due = $d . ' 17:00:00'; $remind = $d . ' 08:00:00';
        }
        $titles = ['NOTE' => __('Note', 'ispag-crm'), 'CALL' => __('Call', 'ispag-crm'), 'EMAIL' => __('E-mail', 'ispag-crm'), 'MEETING' => __('Meeting', 'ispag-crm'), 'TASK' => __('Task', 'ispag-crm')];
        $ok = $wpdb->insert(ISPAG_Note_Manager::TABLE_NOTE, [
            'contact_id' => (string) $cid, 'company_id' => $company, 'deal_id' => $deal, 'user_id' => get_current_user_id(),
            'type' => $map[$type], 'title' => $titles[$type] . ' — ' . mb_substr($text, 0, 60), 'content' => $text,
            'is_task' => $is_task ? 1 : 0, 'is_completed' => 0, 'due_date' => $due, 'reminder_date' => $remind, 'reminder_offset' => $is_task ? 'none' : null,
            'created_at' => current_time('mysql'),
        ]);
        if (!$ok) wp_send_json_error(['message' => 'Database error'], 500);
        wp_send_json_success(['when' => wp_date('d.m.Y H:i'), 'type' => $titles[$type], 'text' => self::plain($text, 400)]);
    }

    // ------------------------------------------------------------------ page

    private static function shell(string $title, string $body) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
           . '<meta name="robots" content="noindex"><title>' . esc_html($title) . '</title><style>'
           . ':root{--bg:#f4f5f7;--card:#fff;--ink:#1f2937;--mut:#6b7280;--line:#e5e7eb;--red:#d32f2f;--warn:#fde8ea;--warnink:#b32d2e;--ok:#e6f4ea;--okink:#1e7b34}'
           . '@media(prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#1a1d23;--ink:#e5e7eb;--mut:#9ca3af;--line:#2a2f38;--warn:#3a1a1d;--warnink:#ff8a8a;--ok:#12301b;--okink:#7ddf95}}'
           . 'body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.45 -apple-system,system-ui,Segoe UI,Roboto,sans-serif;padding:0 0 40px}'
           . '.w{max-width:640px;margin:0 auto;padding:14px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:12px}'
           . 'h1{font-size:1.35rem;margin:6px 0 2px}h2{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);margin:0 0 8px}.mut{color:var(--mut);font-size:.9rem}'
           . '.alert{background:var(--warn);color:var(--warnink);border-radius:10px;padding:8px 10px;margin:0 0 6px;font-size:.92rem}.tag{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.78rem;font-weight:600;background:var(--ok);color:var(--okink)}.tag.late{background:var(--warn);color:var(--warnink)}'
           . '.row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-top:1px solid var(--line)}.row:first-of-type{border-top:0}.amt{font-weight:700;white-space:nowrap}'
           . '.btn{display:inline-block;background:var(--red);color:#fff!important;text-decoration:none;border:0;border-radius:10px;padding:10px 14px;font-weight:600;font-size:.95rem;margin:2px 6px 2px 0}.btn.o{background:transparent;color:var(--red)!important;border:1px solid var(--red)}'
           . 'textarea,select,input[type=date]{width:100%;box-sizing:border-box;padding:10px;border-radius:10px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;margin:6px 0}'
           . '.types{display:flex;gap:6px;flex-wrap:wrap}.types label{flex:1;min-width:70px;text-align:center;border:1px solid var(--line);border-radius:10px;padding:8px 4px;cursor:pointer;font-size:.9rem}.types input{display:none}.types input:checked+span{font-weight:700;color:var(--red)}.types label:has(input:checked){border-color:var(--red)}'
           . '</style></head><body><div class="w">' . $body . '</div></body></html>';
        exit;
    }

    private static function page(int $cid) {
        $u = get_userdata($cid);
        if (!$u) { status_header(404); self::shell(__('Contact not found', 'ispag-crm'), '<p>' . esc_html__('Contact not found.', 'ispag-crm') . '</p>'); }
        $repo = new ISPAG_Crm_Contacts_Repository();
        $c    = $repo->get_contact_by_id($cid);
        $name = $c && !empty($c->display_name) ? $c->display_name : $u->display_name;
        $co   = $c && !empty($c->company_name) ? $c->company_name : '';
        $fn   = $c && !empty($c->lead_function) ? $c->lead_function : '';
        $tel  = $c && !empty($c->phone) ? $c->phone : '';
        $mail = $c && !empty($c->email) ? $c->email : $u->user_email;
        $last = $c && !empty($c->last_contact_date) ? $c->last_contact_date : '';
        $d    = self::collect($cid);
        $e    = 'esc_html';

        ob_start();
        echo '<div class="card"><h1>' . $e($name) . '</h1><div class="mut">' . $e(trim($co . ($fn ? ' · ' . $fn : ''))) . '</div>';
        if ($last) echo '<div class="mut">' . $e(sprintf(__('Last contact: %s', 'ispag-crm'), wp_date('d.m.Y', strtotime($last)))) . '</div>';
        echo '<div style="margin-top:8px">';
        if ($tel) echo '<a class="btn" href="tel:' . esc_attr(preg_replace('/[^+\d]/', '', $tel)) . '">📞 ' . $e($tel) . '</a>';
        if ($mail) echo '<a class="btn o" href="mailto:' . esc_attr($mail) . '">✉️</a>';
        echo '<a class="btn o" href="' . esc_url(home_url('/contact/' . $cid . '/')) . '">' . $e(__('Open in the CRM', 'ispag-crm')) . '</a></div></div>';

        if ($d['alerts']) {
            echo '<div class="card"><h2>⚠️ ' . $e(__('To keep in mind', 'ispag-crm')) . '</h2>';
            foreach (array_slice(array_unique($d['alerts']), 0, 8) as $a) echo '<div class="alert">' . $e($a) . '</div>';
            echo '</div>';
        }
        if ($d['deals']) {
            echo '<div class="card"><h2>' . $e(__('Open offers', 'ispag-crm')) . '</h2>';
            foreach ($d['deals'] as $x) {
                echo '<div class="row"><div><a href="' . esc_url(home_url('/deal/' . rawurlencode($x['ref']) . '/')) . '"><strong>' . $e($x['name']) . '</strong></a><div class="mut">' . $e($x['stage']) . ' · ' . $e(__('decision', 'ispag-crm')) . ' ' . $e(self::day($x['decision'])) . ($x['src'] !== 'expected' ? ' ' . $e(__('(approx.)', 'ispag-crm')) : '') . '</div></div><div class="amt">' . $e(self::money($x['amount'])) . '</div></div>';
            }
            echo '</div>';
        }
        if ($d['projects']) {
            echo '<div class="card"><h2>' . $e(__('Projects: deliveries to come', 'ispag-crm')) . '</h2>';
            foreach ($d['projects'] as $pr) {
                echo '<div style="margin:6px 0 2px"><a href="' . esc_url($pr['url']) . '"><strong>' . $e($pr['name']) . '</strong></a> <span class="mut">' . $e($pr['num']) . '</span></div>';
                foreach ($pr['items'] as $it) {
                    echo '<div class="row"><div>' . $e(rtrim(rtrim(number_format($it['qty'], 2, '.', ''), '0'), '.') . ' × ' . $it['article']) . '</div><div><span class="tag ' . ($it['status'] === 'late' ? 'late' : '') . '">' . $e(self::day($it['due'])) . '</span></div></div>';
                }
            }
            echo '</div>';
        }
        if ($d['tasks']) {
            echo '<div class="card"><h2>' . $e(__('Next tasks', 'ispag-crm')) . '</h2>';
            foreach ($d['tasks'] as $t) echo '<div class="row"><div>' . $e(self::plain($t->title ?: $t->content, 90)) . '</div><div class="mut">' . $e($t->due_date ? wp_date('d.m.Y', strtotime($t->due_date)) : '') . '</div></div>';
            echo '</div>';
        }
        if ($d['recent']) {
            echo '<div class="card"><h2>' . $e(__('Latest actions', 'ispag-crm')) . '</h2><div id="cb-recent">';
            foreach ($d['recent'] as $r) echo '<div class="row"><div><strong>' . $e(ucfirst(strtolower(str_replace('_', ' ', $r->type)))) . '</strong> — ' . $e(self::plain($r->content ?: $r->title, 160)) . '</div><div class="mut">' . $e(wp_date('d.m.Y', strtotime($r->created_at))) . '</div></div>';
            echo '</div></div>';
        } else {
            echo '<div class="card"><h2>' . $e(__('Latest actions', 'ispag-crm')) . '</h2><div id="cb-recent"><p class="mut">' . $e(__('No action recorded yet.', 'ispag-crm')) . '</p></div></div>';
        }

        // Formulaire
        $nonce = wp_create_nonce(self::NONCE);
        echo '<div class="card"><h2>' . $e(__('Record an action', 'ispag-crm')) . '</h2><form id="cb-form">'
           . '<div class="types">';
        foreach (['note' => __('Note', 'ispag-crm'), 'call' => __('Call', 'ispag-crm'), 'email' => __('E-mail', 'ispag-crm'), 'meeting' => __('Meeting', 'ispag-crm'), 'task' => __('Task', 'ispag-crm')] as $k => $l) {
            echo '<label><input type="radio" name="type" value="' . $k . '"' . ($k === 'call' ? ' checked' : '') . '><span>' . $e($l) . '</span></label>';
        }
        echo '</div><textarea name="content" rows="4" placeholder="' . esc_attr__('What was said, what to do…', 'ispag-crm') . '"></textarea>';
        if ($d['deals']) {
            echo '<select name="deal_ref"><option value="">' . $e(__('Not linked to an offer', 'ispag-crm')) . '</option>';
            foreach ($d['deals'] as $x) echo '<option value="' . esc_attr($x['ref']) . '">' . $e($x['name']) . '</option>';
            echo '</select>';
        }
        echo '<div id="cb-due" hidden><label class="mut">' . $e(__('Due date', 'ispag-crm')) . '</label><input type="date" name="due_date" value="' . esc_attr(wp_date('Y-m-d', time() + 3 * DAY_IN_SECONDS)) . '"></div>'
           . '<button type="submit" class="btn" id="cb-save">' . $e(__('Save', 'ispag-crm')) . '</button><span id="cb-msg" class="mut"></span></form></div>';
        echo '<script>(function(){var f=document.getElementById("cb-form"),due=document.getElementById("cb-due"),msg=document.getElementById("cb-msg");'
           . 'f.addEventListener("change",function(){due.hidden=f.type.value!=="task"});'
           . 'f.addEventListener("submit",function(ev){ev.preventDefault();var fd=new FormData(f);fd.append("action",' . wp_json_encode(self::ACTION) . ');fd.append("nonce",' . wp_json_encode($nonce) . ');fd.append("contact_id",' . (int) $cid . ');'
           . 'var b=document.getElementById("cb-save");b.disabled=true;msg.textContent="…";'
           . 'fetch(' . wp_json_encode(admin_url('admin-ajax.php')) . ',{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(r){b.disabled=false;'
           . 'if(!r.success){msg.textContent=(r.data&&r.data.message)||"Error";return}msg.textContent="✓";var box=document.getElementById("cb-recent");var p=box.querySelector("p");if(p)p.remove();'
           . 'var d=document.createElement("div");d.className="row";var a=document.createElement("div"),t=document.createElement("div");a.innerHTML="<strong></strong> — <span></span>";a.querySelector("strong").textContent=r.data.type;a.querySelector("span").textContent=r.data.text;t.className="mut";t.textContent=r.data.when.split(" ")[0];d.appendChild(a);d.appendChild(t);box.insertBefore(d,box.firstChild);f.content.value=""}).catch(function(){b.disabled=false;msg.textContent="Network error"})});})();</script>';
        self::shell(sprintf(__('Before the call — %s', 'ispag-crm'), $name), (string) ob_get_clean());
    }
}
