<?php
/**
 * Admin-Interface für die Tanzschul-Mitgliederverwaltung
 */

// Direktzugriff verhindern
if (!defined('ABSPATH')) exit;

/**
 * Admin-Menü für Mitgliederverwaltung hinzufügen
 */
function tanzvertrag_admin_menu() {
    add_menu_page(
        'Tanzschule Mitgliederverwaltung',
        'Mitglieder',
        'manage_options',
        'tanzschule-mitglieder',
        'tanzvertrag_mitglieder_page',
        'dashicons-groups',
        30
    );
    
    add_submenu_page(
        'tanzschule-mitglieder',
        'Alle Mitglieder',
        'Alle Mitglieder',
        'manage_options',
        'tanzschule-mitglieder',
        'tanzvertrag_mitglieder_page'
    );
    
    add_submenu_page(
        'tanzschule-mitglieder',
        'Neues Mitglied',
        'Neues Mitglied',
        'manage_options',
        'tanzschule-mitglied-neu',
        'tanzvertrag_mitglied_neu_page'
    );
    
    add_submenu_page(
        'tanzschule-mitglieder',
        'Anwesenheiten',
        'Anwesenheiten',
        'manage_options',
        'tanzschule-anwesenheiten',
        'tanzvertrag_anwesenheiten_page'
    );
    
    add_submenu_page(
        'tanzschule-mitglieder',
        'Stundenkontingente',
        'Stundenkontingente',
        'manage_options',
        'tanzschule-kontingente',
        'tanzvertrag_kontingente_page'
    );
    
    add_submenu_page(
        'tanzschule-mitglieder',
        'Import',
        'Import',
        'manage_options',
        'tanzschule-import',
        'tanzvertrag_import_page'
    );
    
    add_submenu_page(
        'tanzschule-mitglieder',
        'Einstellungen',
        'Einstellungen',
        'manage_options',
        'tanzschule-einstellungen',
        'tanzvertrag_einstellungen_page'
    );
}
add_action('admin_menu', 'tanzvertrag_admin_menu');

/**
 * Admin Styles für die Mitgliederverwaltung
 */
function tanzvertrag_admin_styles() {
    $screen = get_current_screen();
    
    // Nur auf Mitglieder-Seiten laden
    if (strpos($screen->id, 'tanzschule-') !== false) {
        wp_enqueue_style('tanzvertrag-admin', TANZVERTRAG_PLUGIN_URL . 'assets/css/style.css');
    }
}
add_action('admin_enqueue_scripts', 'tanzvertrag_admin_styles');

/**
 * Hauptseite für Mitgliederverwaltung
 */
function tanzvertrag_mitglieder_page() {
    // Sicherstellen, dass der Benutzer berechtigt ist
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Aktionen verarbeiten (Löschen, etc.)
    if (isset($_GET['action']) && isset($_GET['user_id']) && isset($_GET['_wpnonce'])) {
        $action = $_GET['action'];
        $user_id = intval($_GET['user_id']);
        $nonce = $_GET['_wpnonce'];
        
        if (wp_verify_nonce($nonce, 'tanzschule_member_' . $action . '_' . $user_id)) {
            switch ($action) {
                case 'delete':
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                    wp_delete_user($user_id);
                    add_settings_error('tanzschule_messages', 'member_deleted', 'Mitglied erfolgreich gelöscht.', 'success');
                    break;
                    
                case 'reset_password':
                    $password = wp_generate_password(12, true);
                    wp_set_password($password, $user_id);
                    $user = get_user_by('ID', $user_id);
                    
                    // Passwort per E-Mail senden
                    $subject = 'Tanzschule Falkensee - Neues Passwort';
                    $message = "Hallo " . $user->display_name . ",\n\n";
                    $message .= "Dein Passwort für den Mitgliederbereich wurde zurückgesetzt. Hier sind deine neuen Zugangsdaten:\n\n";
                    $message .= "Benutzername: " . $user->user_email . "\n";
                    $message .= "Passwort: " . $password . "\n\n";
                    $message .= "Du kannst dich hier einloggen: " . site_url('/mitglieder-login/') . "\n\n";
                    $message .= "Viele Grüße\nDeine Tanzschule Falkensee";
                    
                    wp_mail($user->user_email, $subject, $message);
                    
                    add_settings_error('tanzschule_messages', 'password_reset', 'Passwort zurückgesetzt und per E-Mail versendet.', 'success');
                    break;
            }
        }
    }
    
    // Suchanfrage verarbeiten
    $search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    
    // Filterung nach Vertragstyp
    $filter_vertragstyp = isset($_GET['vertragstyp']) ? sanitize_text_field($_GET['vertragstyp']) : '';
    
    // Paginierung
    $users_per_page = 20;
    $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $offset = ($paged - 1) * $users_per_page;
    
    // Mitglieder abrufen
    $args = array(
        'role' => 'mitglied',
        'number' => $users_per_page,
        'offset' => $offset,
        'search' => $search_query ? '*' . $search_query . '*' : '',
        'search_columns' => array('user_login', 'user_email', 'display_name'),
        'orderby' => 'display_name',
        'order' => 'ASC',
        'meta_query' => array()
    );
    
    // Vertragstyp-Filter hinzufügen
    if ($filter_vertragstyp) {
        $args['meta_query'][] = array(
            'key' => 'vertragstyp',
            'value' => $filter_vertragstyp,
            'compare' => '='
        );
    }
    
    $user_query = new WP_User_Query($args);
    $members = $user_query->get_results();
    $total_users = $user_query->get_total();
    $total_pages = ceil($total_users / $users_per_page);
    
    // Verfügbare Vertragstypen für Filter
    global $wpdb;
    $vertragstypen = $wpdb->get_col("
        SELECT DISTINCT meta_value 
        FROM $wpdb->usermeta 
        WHERE meta_key = 'vertragstyp' 
        AND meta_value != '' 
        ORDER BY meta_value ASC
    ");
    
    // Admin-UI ausgeben
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Mitgliederverwaltung</h1>
        <a href="<?php echo admin_url('admin.php?page=tanzschule-mitglied-neu'); ?>" class="page-title-action">Neues Mitglied</a>
        <hr class="wp-header-end">
        
        <?php settings_errors('tanzschule_messages'); ?>
        
        <form method="get">
            <input type="hidden" name="page" value="tanzschule-mitglieder">
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="vertragstyp">
                        <option value="">Alle Vertragstypen</option>
                        <?php foreach ($vertragstypen as $typ): ?>
                            <option value="<?php echo esc_attr($typ); ?>" <?php selected($filter_vertragstyp, $typ); ?>><?php echo esc_html($typ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="submit" class="button" value="Filter anwenden">
                </div>
                
                <div class="alignright">
                    <p class="search-box">
                        <label class="screen-reader-text" for="member-search-input">Mitglieder durchsuchen:</label>
                        <input type="search" id="member-search-input" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="Name, E-Mail, Mitgliedsnummer...">
                        <input type="submit" id="search-submit" class="button" value="Suchen">
                    </p>
                </div>
                
                <br class="clear">
            </div>
            
            <?php if ($members): ?>
                <table class="wp-list-table widefat fixed striped users">
                    <thead>
                        <tr>
                            <th scope="col" class="manage-column column-name">Name</th>
                            <th scope="col" class="manage-column column-email">E-Mail</th>
                            <th scope="col" class="manage-column column-mitgliedsnummer">Mitgliedsnummer</th>
                            <th scope="col" class="manage-column column-vertragstyp">Vertragstyp</th>
                            <th scope="col" class="manage-column column-stunden">Stundenkontingent</th>
                            <th scope="col" class="manage-column column-actions">Aktionen</th>
                        </tr>
                    </thead>
                    
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <?php
                            $mitgliedsnummer = get_user_meta($member->ID, 'mitgliedsnummer', true);
                            $vertragstyp = get_user_meta($member->ID, 'vertragstyp', true);
                            $stundenkontingent = get_user_meta($member->ID, 'stundenkontingent', true);
                            ?>
                            <tr>
                                <td class="column-name">
                                    <strong>
                                        <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $member->ID)); ?>">
                                            <?php echo esc_html($member->display_name); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td class="column-email"><?php echo esc_html($member->user_email); ?></td>
                                <td class="column-mitgliedsnummer"><?php echo esc_html($mitgliedsnummer); ?></td>
                                <td class="column-vertragstyp"><?php echo esc_html($vertragstyp); ?></td>
                                <td class="column-stunden">
                                    <?php 
                                    if ($stundenkontingent !== '') {
                                        echo esc_html($stundenkontingent) . ' Stunden';
                                    } else {
                                        echo '–';
                                    }
                                    ?>
                                </td>
                                <td class="column-actions">
                                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $member->ID)); ?>" class="button button-small">Bearbeiten</a>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=tanzschule-mitglieder&action=reset_password&user_id=' . $member->ID), 'tanzschule_member_reset_password_' . $member->ID); ?>" class="button button-small">Passwort zurücksetzen</a>
                                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=tanzschule-mitglieder&action=delete&user_id=' . $member->ID), 'tanzschule_member_delete_' . $member->ID); ?>" class="button button-small button-link-delete" onclick="return confirm('Sicher? Dieser Vorgang kann nicht rückgängig gemacht werden.');">Löschen</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    
                    <tfoot>
                        <tr>
                            <th scope="col" class="manage-column column-name">Name</th>
                            <th scope="col" class="manage-column column-email">E-Mail</th>
                            <th scope="col" class="manage-column column-mitgliedsnummer">Mitgliedsnummer</th>
                            <th scope="col" class="manage-column column-vertragstyp">Vertragstyp</th>
                            <th scope="col" class="manage-column column-stunden">Stundenkontingent</th>
                            <th scope="col" class="manage-column column-actions">Aktionen</th>
                        </tr>
                    </tfoot>
                </table>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php printf(_n('%s Mitglied', '%s Mitglieder', $total_users), number_format_i18n($total_users)); ?></span>
                            <span class="pagination-links">
                                <?php
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%'),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $total_pages,
                                    'current' => $paged
                                ));
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="no-items">
                    <p>Keine Mitglieder gefunden.</p>
                </div>
            <?php endif; ?>
        </form>
    </div>
    <?php
}
/**
 * Seite für neues Mitglied hinzufügen
 */
function tanzvertrag_mitglied_neu_page() {
    // Sicherstellen, dass der Benutzer berechtigt ist
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Formular verarbeiten
    if (isset($_POST['tanzschule_add_member']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tanzschule_add_member')) {
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $vertragstyp = sanitize_text_field($_POST['vertragstyp']);
        $stundenkontingent = isset($_POST['stundenkontingent']) ? intval($_POST['stundenkontingent']) : '';
        
        // Überprüfen, ob die E-Mail bereits existiert
        if (email_exists($email)) {
            add_settings_error('tanzschule_messages', 'email_exists', 'Ein Benutzer mit dieser E-Mail existiert bereits.', 'error');
        } else {
            // Mitgliedsnummer generieren
            $date = date('Ym');
            $count = wp_count_posts('vertrag')->publish + 1;
            $mitgliedsnummer = 'TSF-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
            
            // Zufälliges Passwort generieren
            $password = wp_generate_password(12, true);
            
            // Benutzer anlegen
            $user_id = wp_create_user($email, $password, $email);
            
            if (!is_wp_error($user_id)) {
                // Benutzerrolle zuweisen
                $user = new WP_User($user_id);
                $user->set_role('mitglied');
                
                // Benutzerdaten speichern
                update_user_meta($user_id, 'mitgliedsnummer', $mitgliedsnummer);
                update_user_meta($user_id, 'first_name', $name);
                wp_update_user(array('ID' => $user_id, 'display_name' => $name));
                update_user_meta($user_id, 'vertragstyp', $vertragstyp);
                
                if ($stundenkontingent !== '') {
                    update_user_meta($user_id, 'stundenkontingent', $stundenkontingent);
                    
                    // Stundenkontingent in der DB-Tabelle speichern
                    global $wpdb;
                    $wpdb->insert(
                        $wpdb->prefix . 'tanzschule_kontingent',
                        array(
                            'user_id' => $user_id,
                            'kontingent_typ' => $vertragstyp,
                            'stunden_gesamt' => $stundenkontingent,
                            'stunden_verbraucht' => 0,
                            'gueltig_bis' => date('Y-m-d', strtotime('+1 year'))
                        )
                    );
                }
                
                // QR-Code generieren
                if (class_exists('TanzvertragPlugin')) {
                    $plugin = new TanzvertragPlugin();
                    $qr_code_path = $plugin->generate_qr_code($mitgliedsnummer, $user_id);
                    update_user_meta($user_id, 'qr_code_path', $qr_code_path);
                }
                
                // Vertrag erstellen
                $post_id = wp_insert_post(array(
                    'post_type' => 'vertrag',
                    'post_title' => $name,
                    'post_status' => 'publish',
                ));
                
                update_post_meta($post_id, 'mitgliedsnummer', $mitgliedsnummer);
                update_post_meta($post_id, 'teilnehmer_name', $name);
                update_post_meta($post_id, 'email', $email);
                update_post_meta($post_id, 'kurs', $vertragstyp);
                update_post_meta($post_id, 'user_id', $user_id);
                
                // Willkommens-E-Mail mit Zugangsdaten senden
                $subject = 'Willkommen bei der Tanzschule Falkensee - Deine Zugangsdaten';
                $message = "Hallo $name,\n\n";
                $message .= "du wurdest erfolgreich bei der Tanzschule Falkensee registriert. Dein Online-Zugang wurde eingerichtet.\n\n";
                $message .= "Deine Zugangsdaten für den Mitgliederbereich:\n";
                $message .= "Benutzername: $email\n";
                $message .= "Passwort: $password\n\n";
                $message .= "Deine Mitgliedsnummer: $mitgliedsnummer\n\n";
                $message .= "Du kannst dich hier einloggen: " . site_url('/mitglieder-login/') . "\n\n";
                $message .= "Im Mitgliederbereich findest du deinen persönlichen QR-Code, mit dem du dich zu den Kursen anmelden kannst.\n\n";
                $message .= "Viele Grüße\nDeine Tanzschule Falkensee";
                
                wp_mail($email, $subject, $message);
                
                add_settings_error('tanzschule_messages', 'member_added', 'Mitglied erfolgreich angelegt. Die Zugangsdaten wurden per E-Mail versendet.', 'success');
            } else {
                add_settings_error('tanzschule_messages', 'user_error', 'Fehler beim Anlegen des Benutzers: ' . $user_id->get_error_message(), 'error');
            }
        }
    }
    
    // Verfügbare Kurse aus den Einstellungen laden
    $verfuegbare_kurse = get_option('tanzschule_verfuegbare_kurse', "Gesellschaftstanz\nZumba\nKindertanzen\n10er-Karte\n20er-Karte");
    $kurse = explode("\n", $verfuegbare_kurse);
    
    // Admin-UI ausgeben
    ?>
    <div class="wrap">
        <h1>Neues Mitglied anlegen</h1>
        
        <?php settings_errors('tanzschule_messages'); ?>
        
        <form method="post" action="">
            <?php wp_nonce_field('tanzschule_add_member'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="name">Name</label></th>
                    <td><input name="name" type="text" id="name" class="regular-text" required></td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="email">E-Mail</label></th>
                    <td><input name="email" type="email" id="email" class="regular-text" required></td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="vertragstyp">Vertragstyp</label></th>
                    <td>
                        <select name="vertragstyp" id="vertragstyp" required>
                            <option value="">Bitte wählen...</option>
                            <?php foreach ($kurse as $kurs): ?>
                                <?php $kurs = trim($kurs); ?>
                                <?php if ($kurs): ?>
                                    <option value="<?php echo esc_attr($kurs); ?>"><?php echo esc_html($kurs); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                
                <tr id="stundenkontingent-row" style="display: none;">
                    <th scope="row"><label for="stundenkontingent">Stundenkontingent</label></th>
                    <td>
                        <input name="stundenkontingent" type="number" id="stundenkontingent" min="0" class="small-text">
                        <p class="description">Anzahl der verfügbaren Stunden (leer lassen für unbegrenzt).</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="tanzschule_add_member" class="button button-primary" value="Mitglied anlegen">
            </p>
        </form>
        
        <script>
            jQuery(document).ready(function($) {
                $('#vertragstyp').on('change', function() {
                    var vertragstyp = $(this).val();
                    if (vertragstyp.indexOf('Karte') !== -1) {
                        $('#stundenkontingent-row').show();
                        var stunden = vertragstyp.indexOf('10er') !== -1 ? 10 : 20;
                        $('#stundenkontingent').val(stunden);
                    } else {
                        $('#stundenkontingent-row').hide();
                        $('#stundenkontingent').val('');
                    }
                });
            });
        </script>
    </div>
    <?php
}

/**
 * Seite für Anwesenheiten anzeigen
 */
function tanzvertrag_anwesenheiten_page() {
    // Sicherstellen, dass der Benutzer berechtigt ist
    if (!current_user_can('manage_options')) {
        return;
    }
    
    global $wpdb;
    $table_attendance = $wpdb->prefix . 'tanzschule_anwesenheit';
    
    // Datumsfilter
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
    
    // Kursfilter
    $kurs_filter = isset($_GET['kurs']) ? sanitize_text_field($_GET['kurs']) : '';
    
    // Paginierung
    $items_per_page = 50;
    $paged = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $offset = ($paged - 1) * $items_per_page;
    
    // SQL für Anwesenheiten mit Benutzerdaten
    $sql = "
        SELECT a.*, u.display_name, um.meta_value as mitgliedsnummer 
        FROM $table_attendance a
        JOIN $wpdb->users u ON a.user_id = u.ID
        LEFT JOIN $wpdb->usermeta um ON a.user_id = um.user_id AND um.meta_key = 'mitgliedsnummer'
        WHERE a.datum BETWEEN %s AND %s
    ";
    $params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if ($kurs_filter) {
        $sql .= " AND a.kurs_id = %s";
        $params[] = $kurs_filter;
    }
    
    $sql .= " ORDER BY a.datum DESC LIMIT %d OFFSET %d";
    $params[] = $items_per_page;
    $params[] = $offset;
    
    $anwesenheiten = $wpdb->get_results($wpdb->prepare($sql, $params));
    
    // Gesamtanzahl für Paginierung
    $count_sql = "
        SELECT COUNT(*) 
        FROM $table_attendance a
        WHERE a.datum BETWEEN %s AND %s
    ";
    $count_params = array($start_date . ' 00:00:00', $end_date . ' 23:59:59');
    
    if ($kurs_filter) {
        $count_sql .= " AND a.kurs_id = %s";
        $count_params[] = $kurs_filter;
    }
    
    $total_items = $wpdb->get_var($wpdb->prepare($count_sql, $count_params));
    $total_pages = ceil($total_items / $items_per_page);
    
    // Verfügbare Kurse für Filter
    $kurse = $wpdb->get_col("SELECT DISTINCT kurs_id FROM $table_attendance ORDER BY kurs_id ASC");
    
    // Admin-UI ausgeben
    ?>
    <div class="wrap">
        <h1>Anwesenheiten</h1>
        
        <form method="get">
            <input type="hidden" name="page" value="tanzschule-anwesenheiten">
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <label for="start_date">Von:</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo esc_attr($start_date); ?>">
                    
                    <label for="end_date">Bis:</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo esc_attr($end_date); ?>">
                    
                    <select name="kurs">
                        <option value="">Alle Kurse</option>
                        <?php foreach ($kurse as $kurs): ?>
                            <option value="<?php echo esc_attr($kurs); ?>" <?php selected($kurs_filter, $kurs); ?>><?php echo esc_html($kurs); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <input type="submit" class="button" value="Filter anwenden">
                </div>
                
                <div class="alignright">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=tanzschule-anwesenheiten&export=csv&start_date=' . $start_date . '&end_date=' . $end_date . ($kurs_filter ? '&kurs=' . $kurs_filter : ''))); ?>" class="button">Als CSV exportieren</a>
                </div>
                
                <br class="clear">
            </div>
            
            <?php if ($anwesenheiten): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col">Datum</th>
                            <th scope="col">Name</th>
                            <th scope="col">Mitgliedsnummer</th>
                            <th scope="col">Kurs</th>
                            <th scope="col">Stunde abgezogen</th>
                            <th scope="col">Kommentar</th>
                        </tr>
                    </thead>
                    
                    <tbody>
                        <?php foreach ($anwesenheiten as $anwesenheit): ?>
                            <tr>
                                <td><?php echo date('d.m.Y H:i', strtotime($anwesenheit->datum)); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $anwesenheit->user_id)); ?>">
                                        <?php echo esc_html($anwesenheit->display_name); ?>
                                    </a>
                                </td>
                                <td><?php echo esc_html($anwesenheit->mitgliedsnummer); ?></td>
                                <td><?php echo esc_html($anwesenheit->kurs_id); ?></td>
                                <td><?php echo $anwesenheit->stunden_abgezogen ? 'Ja' : 'Nein'; ?></td>
                                <td><?php echo esc_html($anwesenheit->kommentar); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <span class="displaying-num"><?php printf(_n('%s Eintrag', '%s Einträge', $total_items), number_format_i18n($total_items)); ?></span>
                            <span class="pagination-links">
                                <?php
                                echo paginate_links(array(
                                    'base' => add_query_arg('paged', '%#%'),
                                    'format' => '',
                                    'prev_text' => '&laquo;',
                                    'next_text' => '&raquo;',
                                    'total' => $total_pages,
                                    'current' => $paged
                                ));
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="no-items">
                    <p>Keine Anwesenheiten im ausgewählten Zeitraum gefunden.</p>
                </div>
            <?php endif; ?>
        </form>
    </div>
    <?php
}
