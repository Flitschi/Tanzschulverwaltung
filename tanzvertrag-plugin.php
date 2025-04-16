<?php
/*
Plugin Name: Tanzvertrag Plugin
Description: Plugin zur Verwaltung von Tanzverträgen inkl. Online-Vertragsabschluss, Mitgliedsnummer, PDF, E-Mail, Mitgliederbereich und QR-Code-System.
Version: 1.1.0
Author: Christian Schuh
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Direktzugriff verhindern

// Plugin-Verzeichnis definieren
define('TANZVERTRAG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TANZVERTRAG_PLUGIN_URL', plugin_dir_url(__FILE__));

// Abhängigkeiten laden
require_once TANZVERTRAG_PLUGIN_DIR . 'vendor/autoload.php';

// Admin-Interface laden
require_once TANZVERTRAG_PLUGIN_DIR . 'admin/admin-interface.php';

// Hauptklasse für das Plugin
class TanzvertragPlugin {
    /**
     * Konstruktor - Initialisiert das Plugin
     */
    public function __construct() {
        // Hooks für die Plugin-Initialisierung
        add_action('init', array($this, 'register_post_types'));
        add_action('init', array($this, 'modify_existing_processing'));
        add_action('plugins_loaded', array($this, 'hook_into_form_processing'));
        
        // Hooks für das Frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('login_redirect', array($this, 'login_redirect'), 10, 3);
        
        // Shortcodes registrieren
        add_shortcode('tanzschule_dashboard', array($this, 'member_dashboard_shortcode'));
        add_shortcode('tanzschule_qr_scanner', array($this, 'qr_scanner_shortcode'));
        add_shortcode('tanzschule_kuendigung', array($this, 'kuendigung_shortcode'));
        
        // REST API Endpunkte registrieren
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // AJAX Handler registrieren
        add_action('wp_ajax_tanzvertrag_process_scan', array($this, 'process_scan'));
        add_action('wp_ajax_tanzvertrag_get_member_data', array($this, 'get_member_data'));
    }
    
    /**
     * Registriert den Custom Post Type für Verträge
     */
    public function register_post_types() {
        $labels = array(
            'name' => 'Verträge',
            'singular_name' => 'Vertrag',
            'menu_name' => 'Verträge',
            'name_admin_bar' => 'Vertrag',
            'add_new' => 'Neuen Vertrag hinzufügen',
            'add_new_item' => 'Neuen Vertrag anlegen',
            'edit_item' => 'Vertrag bearbeiten',
            'new_item' => 'Neuer Vertrag',
            'view_item' => 'Vertrag ansehen',
            'search_items' => 'Vertrag suchen',
            'not_found' => 'Keine Verträge gefunden',
            'not_found_in_trash' => 'Keine Verträge im Papierkorb',
        );

        $args = array(
            'label' => 'Verträge',
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'supports' => array('title', 'custom-fields'),
            'menu_icon' => 'dashicons-clipboard',
            'capability_type' => 'post',
            'has_archive' => false,
            'rewrite' => false,
        );

        register_post_type('vertrag', $args);
    }
    
    /**
     * Modifiziert die bestehende Formularverarbeitung
     */
    public function modify_existing_processing() {
        // Hook in die bestehende wp_mail-Funktion
        add_filter('wp_mail', function($args) {
            // Überprüfen, ob es sich um eine Vertragsbestätigungs-E-Mail handelt
            if (strpos($args['subject'], 'Vertrag bei der Tanzschule') !== false) {
                // Vertragsdaten aus dem Betreff extrahieren
                preg_match('/Mitgliedsnummer: (TSF-[0-9]+-[0-9]+)/i', $args['message'], $matches);
                if (!empty($matches[1])) {
                    $mitgliedsnummer = $matches[1];
                    
                    // Vertrag in der Datenbank suchen
                    $args = apply_filters('tanzvertrag_mail_args', $args, $mitgliedsnummer);
                }
            }
            
            return $args;
        });
    }
    
    /**
     * Hook in die bestehende Formularverarbeitung
     */
    public function hook_into_form_processing() {
        // Originale Formularverarbeitung modifizieren
        add_action('init', function() {
            // Nur ausführen, wenn die ursprüngliche Funktion existiert
            if (function_exists('tanzvertrag_formular_verarbeitung')) {
                // Original-Funktion entfernen
                remove_action('init', 'tanzvertrag_formular_verarbeitung');
                
                // Unsere modifizierte Version hinzufügen
                add_action('init', array($this, 'extended_form_processing'));
            }
        }, 5); // Niedrigere Priorität, um vor der Original-Funktion zu laufen
    }
  /**
     * Erweiterte Formularverarbeitung
     */
    public function extended_form_processing() {
        if (isset($_POST['tanzvertrag_submit'])) {
            $date = date('Ym');
            $count = wp_count_posts('vertrag')->publish + 1;
            $mitgliedsnummer = 'TSF-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);

            $post_id = wp_insert_post(array(
                'post_type' => 'vertrag',
                'post_title' => $_POST['teilnehmer_name'],
                'post_status' => 'publish',
            ));

            update_post_meta($post_id, 'mitgliedsnummer', $mitgliedsnummer);
            update_post_meta($post_id, 'teilnehmer_name', sanitize_text_field($_POST['teilnehmer_name']));
            update_post_meta($post_id, 'eltern_name', sanitize_text_field($_POST['eltern_name']));
            update_post_meta($post_id, 'geburtstag', $_POST['geburtstag']);
            update_post_meta($post_id, 'strasse', sanitize_text_field($_POST['strasse']));
            update_post_meta($post_id, 'ort', sanitize_text_field($_POST['ort']));
            update_post_meta($post_id, 'telefon', sanitize_text_field($_POST['telefon']));
            update_post_meta($post_id, 'email', sanitize_email($_POST['email']));
            update_post_meta($post_id, 'kurs', $_POST['kurs']);
            update_post_meta($post_id, 'startdatum', $_POST['startdatum']);

            // Benutzer anlegen und Metadaten setzen
            $teilnehmer_name = sanitize_text_field($_POST['teilnehmer_name']);
            $email = sanitize_email($_POST['email']);
            $kurs = $_POST['kurs'];
            
            $user_id = $this->create_user_on_contract($post_id, $mitgliedsnummer, $teilnehmer_name, $email);
            
            // Vertragstyp speichern und ggf. Stundenkontingent
            if ($user_id) {
                update_user_meta($user_id, 'vertragstyp', $kurs);
                
                // Prüfen, ob der Kurs ein Stundenkontingent hat
                $stundenkurse = array('10er-Karte', '20er-Karte');
                if (in_array($kurs, $stundenkurse)) {
                    $kontingent = (strpos($kurs, '10er') !== false) ? 10 : 20;
                    update_user_meta($user_id, 'stundenkontingent', $kontingent);
                    
                    // Kontingent auch in der DB-Tabelle speichern
                    global $wpdb;
                    $wpdb->insert(
                        $wpdb->prefix . 'tanzschule_kontingent',
                        array(
                            'user_id' => $user_id,
                            'kontingent_typ' => $kurs,
                            'stunden_gesamt' => $kontingent,
                            'stunden_verbraucht' => 0,
                            'gueltig_bis' => date('Y-m-d', strtotime('+1 year'))
                        )
                    );
                }
            }

            // PDF-Generierung wie im Original
            require_once TANZVERTRAG_PLUGIN_DIR . 'includes/mpdf/vendor/autoload.php';
            $mpdf = new \Mpdf\Mpdf();
            $vertrag_html = "
            <style>
                body {
                    font-family: sans-serif;
                    font-size: 10.5pt;
                    line-height: 1.5;
                    word-wrap: break-word;
                }
                h2 { color: #2c3e50; }
                .vertrag-box { border: 1px solid #ccc; padding: 15px; border-radius: 6px; margin-top: 10px; }
                .vertrag-box p { margin: 5px 0; }
            </style>

            <img src='" . TANZVERTRAG_PLUGIN_URL . "assets/logo.png' width='200' style='margin-bottom: 20px;'>

            <h2>Vertrag – Tanzschule Falkensee</h2>

            <div class='vertrag-box'>
            <p><strong>Mitgliedsnummer:</strong> $mitgliedsnummer</p>
            <p><strong>Teilnehmer:</strong> {$_POST['teilnehmer_name']}</p>
            <p><strong>Erziehungsberechtigt:</strong> {$_POST['eltern_name']}</p>
            <p><strong>Geburtsdatum:</strong> {$_POST['geburtstag']}</p>
            <p><strong>Adresse:</strong> {$_POST['strasse']}, {$_POST['ort']}</p>
            <p><strong>Telefon:</strong> {$_POST['telefon']}</p>
            <p><strong>E-Mail:</strong> {$_POST['email']}</p>
            <p><strong>Kurs:</strong> {$_POST['kurs']}</p>
            <p><strong>Startdatum:</strong> {$_POST['startdatum']}</p>
            </div>

            <hr style='margin: 20px 0;'>

            <p>Bitte richten Sie einen Dauerauftrag mit dem entsprechenden Beitrag auf folgendes Konto ein:</p>

            <div class='vertrag-box'>
            <p><strong>Sabrina Schuh</strong><br>
            IBAN: DE65 1009 0000 2003 8330 14<br>
            Verwendungszweck: $mitgliedsnummer – {$_POST['teilnehmer_name']}</p>
            </div>

            <p style='margin-top: 20px;'>Der Vertrag läuft mindestens 3 Monate und kann danach monatlich auf den Tag genau gekündigt werden.</p>

            <p style='font-size: 9pt; color: #777; text-align: center; margin-top: 50px;'>
            Tanzschule Falkensee – Karl-Marx-Str. 64–66 – 14612 Falkensee – www.tanzschule-falkensee.de
            </p>
            ";

            $upload_dir = wp_upload_dir();
            $pdf_path = $upload_dir['basedir'] . "/vertrag-$mitgliedsnummer.pdf";
            $mpdf->WriteHTML($vertrag_html);
            $mpdf->Output($pdf_path, \Mpdf\Output\Destination::FILE);

            $agb_path = TANZVERTRAG_PLUGIN_DIR . 'assets/agb.pdf';

            // QR-Code-Pfad für den Anhang
            $qr_code_path = null;
            if ($user_id) {
                $qr_code_path = get_user_meta($user_id, 'qr_code_path', true);
                // URL zu lokalem Pfad konvertieren
                if ($qr_code_path) {
                    $site_url = site_url();
                    $upload_url = $upload_dir['baseurl'];
                    $upload_path = $upload_dir['basedir'];
                    $qr_code_local_path = str_replace($upload_url, $upload_path, $qr_code_path);
                }
            }

            $to = array(sanitize_email($_POST['email']), 'info@tanzschule-falkensee.de');
            $subject = "Dein Vertrag bei der Tanzschule Falkensee";
            $body = "Hallo {$_POST['teilnehmer_name']},\n\ndeine Anmeldung war erfolgreich.\n\nDeine Mitgliedsnummer: $mitgliedsnummer\n\n";
            
            // Zugangsdaten hinzufügen, wenn ein neuer Benutzer erstellt wurde
            if ($user_id && isset($password)) {
                $body .= "Deine Zugangsdaten für den Mitgliederbereich:\n";
                $body .= "Benutzername: $email\n";
                $body .= "Passwort: $password\n\n";
                $body .= "Du kannst dich hier einloggen: " . site_url('/mitglieder-login/') . "\n\n";
            }
            
            $body .= "Im Anhang findest du deinen Vertrag sowie unsere aktuellen AGB als PDF";
            
            if ($qr_code_path) {
                $body .= " und deinen persönlichen QR-Code für die Kurse";
            }
            
            $body .= ".\n\nViele Grüße\nDeine Tanzschule Falkensee";
            
            $headers = array('Content-Type: text/plain; charset=UTF-8');
            require_once ABSPATH . WPINC . '/pluggable.php';
            
            $attachments = array($pdf_path, $agb_path);
            if ($qr_code_path && isset($qr_code_local_path) && file_exists($qr_code_local_path)) {
                $attachments[] = $qr_code_local_path;
            }
            
            wp_mail($to, $subject, $body, $headers, $attachments);

            add_action('wp_footer', function() use ($mitgliedsnummer) {
                echo "<script>alert('Vielen Dank! Deine Anmeldung war erfolgreich. Deine Mitgliedsnummer lautet: $mitgliedsnummer');</script>";
            });
            
            // Hook für andere Funktionen
            do_action('tanzvertrag_after_contract_created', $post_id, $mitgliedsnummer);
        }
    }
    
    /**
     * Benutzeranlage bei Vertragsabschluss
     */
    public function create_user_on_contract($post_id, $mitgliedsnummer, $teilnehmer_name, $email) {
        // Überprüfen, ob bereits ein Benutzer mit dieser E-Mail existiert
        $user = get_user_by('email', $email);
        
        if (!$user) {
            // Benutzernamen aus E-Mail generieren
            $username = $email;
            
            // Zufälliges Passwort generieren
            $password = wp_generate_password(12, true);
            
            // Benutzer anlegen
            $user_id = wp_create_user($username, $password, $email);
            
            if (!is_wp_error($user_id)) {
                // Benutzerrolle zuweisen
                $user = new WP_User($user_id);
                $user->set_role('mitglied');
                
                // Benutzerdaten speichern
                update_user_meta($user_id, 'mitgliedsnummer', $mitgliedsnummer);
                update_user_meta($user_id, 'first_name', $teilnehmer_name);
                
                // Vertragspost mit Benutzer verknüpfen
                update_post_meta($post_id, 'user_id', $user_id);
                
                // QR-Code generieren
                $qr_code_path = $this->generate_qr_code($mitgliedsnummer, $user_id);
                update_user_meta($user_id, 'qr_code_path', $qr_code_path);
                
                // Benachrichtigungs-E-Mail mit Zugangsdaten senden
                $this->send_welcome_email($email, $teilnehmer_name, $username, $password, $mitgliedsnummer, $qr_code_path);
                
                return $user_id;
            }
        } else {
            // Bestehenden Benutzer mit Vertrag verknüpfen
            update_post_meta($post_id, 'user_id', $user->ID);
            update_user_meta($user->ID, 'mitgliedsnummer', $mitgliedsnummer);
            
            // QR-Code generieren, wenn noch nicht vorhanden
            $qr_code_path = get_user_meta($user->ID, 'qr_code_path', true);
            if (!$qr_code_path) {
                $qr_code_path = $this->generate_qr_code($mitgliedsnummer, $user->ID);
                update_user_meta($user->ID, 'qr_code_path', $qr_code_path);
            }
            
            return $user->ID;
        }
        
        return false;
    }
  /**
     * QR-Code generieren
     */
    public function generate_qr_code($mitgliedsnummer, $user_id) {
        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/qrcodes';
        
        // Verzeichnis erstellen, falls nicht vorhanden
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }
        
        // QR-Code-Daten (enthält Mitgliedsnummer und eine eindeutige ID)
        $qr_data = site_url('/check-in/') . '?mid=' . $mitgliedsnummer . '&uid=' . $user_id . '&t=' . time();
        
        // QR-Code erstellen
        $qrCode = new \Endroid\QrCode\QrCode($qr_data);
        $qrCode->setSize(300);
        $qrCode->setMargin(10);
        
        // QR-Code als PNG speichern
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);
        
        $filename = 'qrcode-' . $mitgliedsnummer . '.png';
        $filepath = $qr_dir . '/' . $filename;
        
        // QR-Code-Datei speichern
        file_put_contents($filepath, $result->getString());
        
        // URL zum QR-Code zurückgeben (für Speicherung in der Datenbank)
        return $upload_dir['baseurl'] . '/qrcodes/' . $filename;
    }
    
    /**
     * Willkommens-E-Mail mit Zugangsdaten senden
     */
    public function send_welcome_email($email, $name, $username, $password, $mitgliedsnummer, $qr_code_path) {
        $subject = 'Willkommen bei der Tanzschule Falkensee - Deine Zugangsdaten';
        
        $message = "Hallo $name,\n\n";
        $message .= "vielen Dank für deine Anmeldung bei der Tanzschule Falkensee. Dein Online-Zugang wurde erfolgreich eingerichtet.\n\n";
        $message .= "Deine Zugangsdaten für den Mitgliederbereich:\n";
        $message .= "Benutzername: $username\n";
        $message .= "Passwort: $password\n\n";
        $message .= "Deine Mitgliedsnummer: $mitgliedsnummer\n\n";
        $message .= "Du kannst dich hier einloggen: " . site_url('/mitglieder-login/') . "\n\n";
        $message .= "Im Mitgliederbereich findest du deinen persönlichen QR-Code, mit dem du dich zu den Kursen anmelden kannst.\n\n";
        $message .= "Viele Grüße\nDeine Tanzschule Falkensee";
        
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        
        return wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Scripts und Styles laden
     */
    public function enqueue_scripts() {
        // Nur für eingeloggte Benutzer laden
        if (is_user_logged_in()) {
            wp_enqueue_style('tanzvertrag-frontend', TANZVERTRAG_PLUGIN_URL . 'assets/css/frontend.css');
            
            $user = wp_get_current_user();
            
            // Scanner-Scripts nur für Trainer und Admins laden
            if (in_array('trainer', (array) $user->roles) || in_array('administrator', (array) $user->roles)) {
                wp_enqueue_script('html5-qrcode', 'https://unpkg.com/html5-qrcode@2.3.4/dist/html5-qrcode.min.js', array(), '2.3.4', true);
                wp_enqueue_script('tanzvertrag-scanner', TANZVERTRAG_PLUGIN_URL . 'assets/js/scanner.js', array('jquery', 'html5-qrcode'), '1.0.0', true);
                
                wp_localize_script('tanzvertrag-scanner', 'tanzvertrag_scanner', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('tanzschule_qr_scan'),
                ));
            }
        }
    }
    
    /**
     * Login-Weiterleitung für Mitglieder zum Dashboard
     */
    public function login_redirect($redirect_to, $request, $user) {
        if (isset($user->roles) && is_array($user->roles)) {
            if (in_array('mitglied', $user->roles)) {
                $dashboard_url = get_option('tanzschule_dashboard_url', '');
                if ($dashboard_url) {
                    return site_url($dashboard_url);
                }
            }
        }
        return $redirect_to;
    }
    
    /**
     * REST API-Endpunkte registrieren
     */
    public function register_rest_routes() {
        register_rest_route('tanzschule/v1', '/check-in', array(
            'methods' => 'POST',
            'callback' => array($this, 'api_check_in'),
            'permission_callback' => function() {
                return current_user_can('scan_qr_codes');
            }
        ));
        
        register_rest_route('tanzschule/v1', '/member-info', array(
            'methods' => 'GET',
            'callback' => array($this, 'api_member_info'),
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ));
    }
    
    /**
     * REST API Callback für Check-in
     */
    public function api_check_in($request) {
        $params = $request->get_params();
        
        if (!isset($params['mitgliedsnummer']) || !isset($params['user_id']) || !isset($params['kurs_id'])) {
            return new WP_Error('missing_params', 'Fehlende Parameter.', array('status' => 400));
        }
        
        $mitgliedsnummer = sanitize_text_field($params['mitgliedsnummer']);
        $user_id = intval($params['user_id']);
        $kurs_id = sanitize_text_field($params['kurs_id']);
        
        // Benutzer überprüfen
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'Benutzer nicht gefunden.', array('status' => 404));
        }
        
        // Mitgliedsnummer überprüfen
        $stored_mitgliedsnummer = get_user_meta($user_id, 'mitgliedsnummer', true);
        if ($mitgliedsnummer !== $stored_mitgliedsnummer) {
            return new WP_Error('invalid_member', 'Ungültige Mitgliedsnummer.', array('status' => 403));
        }
        
        // Anwesenheit speichern
        global $wpdb;
        $table_name = $wpdb->prefix . 'tanzschule_anwesenheit';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'kurs_id' => $kurs_id,
                'trainer_id' => get_current_user_id(),
            )
        );
        
        if ($result === false) {
            return new WP_Error('db_error', 'Fehler beim Speichern der Anwesenheit.', array('status' => 500));
        }
        
        // Stundenkontingent prüfen und aktualisieren
        $vertragstyp = get_user_meta($user_id, 'vertragstyp', true);
        $stundenkontingent = get_user_meta($user_id, 'stundenkontingent', true);
        
        $response = array(
            'success' => true,
            'message' => 'Anwesenheit erfolgreich gespeichert.',
            'user_data' => array(
                'name' => $user->display_name,
                'mitgliedsnummer' => $mitgliedsnummer,
                'vertragstyp' => $vertragstyp,
            )
        );
        
        // Wenn Stundenkontingent vorhanden, eine Stunde abziehen
        if ($stundenkontingent && $stundenkontingent > 0) {
            $new_kontingent = $stundenkontingent - 1;
            update_user_meta($user_id, 'stundenkontingent', $new_kontingent);
            
            // Anwesenheitsdatensatz aktualisieren
            $wpdb->update(
                $table_name,
                array('stunden_abgezogen' => 1),
                array('id' => $wpdb->insert_id)
            );
            
            // Auch in der Kontingent-Tabelle aktualisieren
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}tanzschule_kontingent 
                 SET stunden_verbraucht = stunden_verbraucht + 1 
                 WHERE user_id = %d 
                 ORDER BY gueltig_bis DESC 
                 LIMIT 1",
                $user_id
            ));
            
            $response['user_data']['stundenkontingent'] = $new_kontingent;
            $response['user_data']['stunde_abgezogen'] = true;
        } else {
            $response['user_data']['stundenkontingent'] = $stundenkontingent;
            $response['user_data']['stunde_abgezogen'] = false;
        }
        
        return $response;
    }
    
    /**
     * REST API Callback für Mitgliedsinformationen
     */
    public function api_member_info($request) {
        $user_id = get_current_user_id();
        
        // Nur eigene Daten oder als Trainer/Admin Daten anderer Benutzer abrufen
        $requested_user_id = $request->get_param('user_id');
        if ($requested_user_id && $requested_user_id != $user_id) {
            if (!current_user_can('scan_qr_codes')) {
                return new WP_Error('permission_denied', 'Keine Berechtigung für diese Aktion.', array('status' => 403));
            }
            $user_id = intval($requested_user_id);
        }
        
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'Benutzer nicht gefunden.', array('status' => 404));
        }
        
        // Benutzerdaten abrufen
        $mitgliedsnummer = get_user_meta($user_id, 'mitgliedsnummer', true);
        $vertragstyp = get_user_meta($user_id, 'vertragstyp', true);
        $stundenkontingent = get_user_meta($user_id, 'stundenkontingent', true);
        $qr_code_url = get_user_meta($user_id, 'qr_code_path', true);
        
        // Letzte Anwesenheiten abrufen
        global $wpdb;
        $anwesenheiten = $wpdb->get_results($wpdb->prepare(
            "SELECT datum, kurs_id, stunden_abgezogen 
             FROM {$wpdb->prefix}tanzschule_anwesenheit 
             WHERE user_id = %d 
             ORDER BY datum DESC 
             LIMIT 10",
            $user_id
        ));
        
        return array(
            'user_id' => $user_id,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'mitgliedsnummer' => $mitgliedsnummer,
            'vertragstyp' => $vertragstyp,
            'stundenkontingent' => $stundenkontingent,
            'qr_code_url' => $qr_code_url,
            'anwesenheiten' => $anwesenheiten
        );
    }
    
    /**
     * AJAX-Handler für QR-Code-Scan
     */
    public function process_scan() {
        // Nonce-Überprüfung
        check_ajax_referer('tanzschule_qr_scan', 'security');
        
        // Zugriffskontrolle
        if (!current_user_can('scan_qr_codes')) {
            wp_send_json_error('Keine Berechtigung.');
            return;
        }
        
        $mitgliedsnummer = isset($_POST['mitgliedsnummer']) ? sanitize_text_field($_POST['mitgliedsnummer']) : '';
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        $kurs_id = isset($_POST['kurs_id']) ? sanitize_text_field($_POST['kurs_id']) : '';
        
        if (!$mitgliedsnummer || !$user_id || !$kurs_id) {
            wp_send_json_error('Unvollständige Daten.');
            return;
        }
        
        // Benutzer überprüfen
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            wp_send_json_error('Benutzer nicht gefunden.');
            return;
        }
        
        // Mitgliedsnummer überprüfen
        $stored_mitgliedsnummer = get_user_meta($user_id, 'mitgliedsnummer', true);
        if ($mitgliedsnummer !== $stored_mitgliedsnummer) {
            wp_send_json_error('Ungültige Mitgliedsnummer.');
            return;
        }
        
        // Anwesenheit speichern
        global $wpdb;
        $table_name = $wpdb->prefix . 'tanzschule_anwesenheit';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'kurs_id' => $kurs_id,
                'trainer_id' => get_current_user_id(),
            )
        );
        
        if ($result === false) {
            wp_send_json_error('Fehler beim Speichern der Anwesenheit.');
            return;
        }
        
        // Stundenkontingent prüfen und aktualisieren
        $vertragstyp = get_user_meta($user_id, 'vertragstyp', true);
        $stundenkontingent = get_user_meta($user_id, 'stundenkontingent', true);
        
        $response = array(
            'success' => true,
            'message' => 'Anwesenheit erfolgreich gespeichert.',
            'user_data' => array(
                'name' => $user->display_name,
                'mitgliedsnummer' => $mitgliedsnummer,
                'vertragstyp' => $vertragstyp,
            )
        );
        
        // Wenn Stundenkontingent vorhanden, eine Stunde abziehen
        if ($stundenkontingent && $stundenkontingent > 0) {
            $new_kontingent = $stundenkontingent - 1;
            update_user_meta($user_id, 'stundenkontingent', $new_kontingent);
            
            // Anwesenheitsdatensatz aktualisieren
            $wpdb->update(
                $table_name,
                array('stunden_abgezogen' => 1),
                array('id' => $wpdb->insert_id)
            );
            
            $response['user_data']['stundenkontingent'] = $new_kontingent;
            $response['user_data']['stunde_abgezogen'] = true;
        } else {
            $response['user_data']['stundenkontingent'] = $stundenkontingent;
            $response['user_data']['stunde_abgezogen'] = false;
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * AJAX-Handler für Mitgliedsdaten-Abruf
     */
    public function get_member_data() {
        // Nonce-Überprüfung
        check_ajax_referer('tanzschule_qr_scan', 'security');
        
        // Zugriffskontrolle
        if (!current_user_can('scan_qr_codes')) {
            wp_send_json_error('Keine Berechtigung.');
            return;
        }
        
        $mitgliedsnummer = isset($_POST['mitgliedsnummer']) ? sanitize_text_field($_POST['mitgliedsnummer']) : '';
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        
        if (!$mitgliedsnummer || !$user_id) {
            wp_send_json_error('Unvollständige Daten.');
            return;
        }
        
        // Benutzer überprüfen
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            wp_send_json_error('Benutzer nicht gefunden.');
            return;
        }
        
        // Mitgliedsnummer überprüfen
        $stored_mitgliedsnummer = get_user_meta($user_id, 'mitgliedsnummer', true);
        if ($mitgliedsnummer !== $stored_mitgliedsnummer) {
            wp_send_json_error('Ungültige Mitgliedsnummer.');
            return;
        }
        
        // Mitgliedsdaten abrufen
        $vertragstyp = get_user_meta($user_id, 'vertragstyp', true);
        $stundenkontingent = get_user_meta($user_id, 'stundenkontingent', true);
        
        $response = array(
            'user_id' => $user_id,
            'mitgliedsnummer' => $mitgliedsnummer,
            'name' => $user->display_name,
            'vertragstyp' => $vertragstyp ?: 'Nicht angegeben',
            'stundenkontingent' => $stundenkontingent !== '' ? intval($stundenkontingent) : null
        );
        
        wp_send_json_success($response);
    }
    
    /**
     * Shortcode für das Mitglieder-Dashboard
     */
    public function member_dashboard_shortcode() {
        // Zugriffskontrolle
        if (!is_user_logged_in()) {
            return '<div class="error-message">Bitte logge dich ein, um auf den Mitgliederbereich zuzugreifen. <a href="' . wp_login_url(get_permalink()) . '">Zum Login</a></div>';
        }
        
        $user = wp_get_current_user();
        
        // Überprüfen, ob Benutzer ein Mitglied ist
        if (!in_array('mitglied', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
            return '<div class="error-message">Du hast keine Berechtigung, auf diesen Bereich zuzugreifen.</div>';
        }
        
        // Mitgliedsdaten abrufen
        $mitgliedsnummer = get_user_meta($user->ID, 'mitgliedsnummer', true);
        $qr_code_path = get_user_meta($user->ID, 'qr_code_path', true);
        $vertragstyp = get_user_meta($user->ID, 'vertragstyp', true);
        $stundenkontingent = get_user_meta($user->ID, 'stundenkontingent', true);
        
        // Wenn der QR-Code nicht existiert, neu generieren
        if (!$qr_code_path || !file_exists(str_replace(site_url(), ABSPATH, $qr_code_path))) {
            if ($mitgliedsnummer) {
                $qr_code_path = $this->generate_qr_code($mitgliedsnummer, $user->ID);
                update_user_meta($user->ID, 'qr_code_path', $qr_code_path);
            }
        }
        
        // Anwesenheiten abrufen
        global $wpdb;
        $table_attendance = $wpdb->prefix . 'tanzschule_anwesenheit';
        
        $anwesenheiten = $wpdb->get_results($wpdb->prepare(
            "SELECT datum, kurs_id, stunden_abgezogen 
             FROM $table_attendance 
             WHERE user_id = %d 
             ORDER BY datum DESC 
             LIMIT 5",
            $user->ID
        ));
        
        // Letzte News für Mitglieder abrufen (z.B. aus einer Kategorie "Intern")
        $args = array(
            'post_type' => 'post',
            'posts_per_page' => 3,
            'category_name' => 'intern', // Anpassen je nach tatsächlicher Kategorie
        );
        $internal_news = new WP_Query($args);
        
        // HTML für das Dashboard erstellen
        $output = '<div class="tanzschule-dashboard">';
        
        // Header-Bereich
        $output .= '<div class="dashboard-header">';
        $output .= '<h2>Willkommen, ' . esc_html($user->display_name) . '!</h2>';
        $output .= '<div class="member-id">Mitgliedsnummer: ' . esc_html($mitgliedsnummer) . '</div>';
        $output .= '</div>';
        
        // Grid-Layout
        $output .= '<div class="dashboard-grid">';
        
        // QR-Code-Karte
        if ($qr_code_path) {
            $output .= '<div class="dashboard-card">';
            $output .= '<h3>Dein persönlicher Check-in Code</h3>';
            $output .= '<div class="qr-code-container">';
            $output .= '<img src="' . esc_url($qr_code_path) . '" alt="Dein QR-Code" />';
            $output .= '<p class="qr-code-note">Zeige diesen QR-Code beim Kursbesuch vor</p>';
            $output .= '</div>';
            $output .= '</div>';
        }
        
        // Vertragsdaten-Karte
        $output .= '<div class="dashboard-card">';
        $output .= '<h3>Deine Vertragsdaten</h3>';
        $output .= '<dl class="contract-data">';
        $output .= '<dt>Name:</dt><dd>' . esc_html($user->display_name) . '</dd>';
        $output .= '<dt>Mitgliedsnummer:</dt><dd>' . esc_html($mitgliedsnummer) . '</dd>';
        $output .= '<dt>Vertragstyp:</dt><dd>' . esc_html($vertragstyp) . '</dd>';
        
        // Stundenkontingent anzeigen, falls vorhanden
        if ($stundenkontingent !== '') {
            $kontingent_klasse = 'hours-ok';
            if ($stundenkontingent < 3) {
                $kontingent_klasse = 'hours-low';
            }
            if ($stundenkontingent <= 0) {
                $kontingent_klasse = 'hours-empty';
            }
            
            // Prozentangabe berechnen (bei 10er- oder 20er-Karte)
            $max_stunden = 10;
            if (strpos($vertragstyp, '20er') !== false) {
                $max_stunden = 20;
            }
            $prozent = min(100, max(0, ($stundenkontingent / $max_stunden) * 100));
            
            $output .= '<dt>Stundenkontingent:</dt><dd>' . esc_html($stundenkontingent) . ' Stunden</dd>';
            $output .= '</dl>';
            $output .= '<div class="hours-container ' . $kontingent_klasse . '">';
            $output .= '<div class="hours-progress">';
            $output .= '<div class="hours-bar" style="width: ' . $prozent . '%;"></div>';
            $output .= '<div class="hours-text">' . esc_html($stundenkontingent) . ' von ' . $max_stunden . ' Stunden</div>';
            $output .= '</div>';
            $output .= '</div>';
        } else {
            $output .= '</dl>';
        }
        
        $output .= '</div>';
        
        // Anwesenheiten-Karte
        $output .= '<div class="dashboard-card">';
        $output .= '<h3>Deine letzten Besuche</h3>';
        
        if ($anwesenheiten) {
            $output .= '<ul class="attendance-list">';
            foreach ($anwesenheiten as $anwesenheit) {
                $output .= '<li>';
                $output .= '<span class="attendance-date">' . date('d.m.Y H:i', strtotime($anwesenheit->datum)) . '</span><br>';
                $output .= '<span class="attendance-course">' . esc_html($anwesenheit->kurs_id) . '</span>';
                if ($anwesenheit->stunden_abgezogen) {
                    $output .= ' <small>(1 Stunde verbraucht)</small>';
                }
                $output .= '</li>';
            }
            $output .= '</ul>';
        } else {
            $output .= '<p>Es wurden noch keine Besuche erfasst.</p>';
        }
        
        $output .= '</div>';
        
        // News/Mitteilungen-Karte
        $output .= '<div class="dashboard-card">';
        $output .= '<h3>Neuigkeiten für Mitglieder</h3>';
        
        if ($internal_news->have_posts()) {
            while ($internal_news->have_posts()) {
                $internal_news->the_post();
                $output .= '<div class="news-item">';
                $output .= '<h4><a href="' . get_permalink() . '">' . get_the_title() . '</a></h4>';
                $output .= '<p class="news-date">' . get_the_date() . '</p>';
                $output .= '<div class="news-excerpt">' . get_the_excerpt() . '</div>';
                $output .= '</div>';
            }
            wp_reset_postdata();
        } else {
            $output .= '<p>Keine aktuellen Mitteilungen vorhanden.</p>';
        }
        
        $output .= '</div>';
        
        // Ende des Grid-Layouts
        $output .= '</div>';
        
        // Profildaten bearbeiten und Kündigung
        $output .= '<div class="dashboard-card">';
        $output .= '<h3>Profiloptionen</h3>';
        $output .= '<p>Hier kannst du deine Kontaktdaten aktualisieren oder deinen Vertrag kündigen.</p>';
        
        $output .= '<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">';
        $output .= '<a href="' . esc_url(get_edit_profile_url()) . '" class="button">Profil bearbeiten</a>';
        
        // Kündigung-Button nur anzeigen, wenn nicht 10er oder 20er Karte
        if (strpos($vertragstyp, 'Karte') === false) {
            $output .= '<a href="' . esc_url(site_url('/mitglieder/kuendigung/')) . '" class="button button-warning">Vertrag kündigen</a>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
        
        $output .= '</div>'; // Ende .tanzschule-dashboard
        
        // JavaScript für dynamische Elemente
        $output .= '<script>
            jQuery(document).ready(function($) {
                // Automatisch aktualisieren, falls nötig
            });
        </script>';
        
        return $output;
    }
    
    /**
     * Shortcode für QR-Code-Scanner für Trainer
     */
    public function qr_scanner_shortcode() {
        // Zugriffskontrolle
        if (!is_user_logged_in()) {
            return '<div class="error-message">Bitte logge dich ein, um auf den Scanner zuzugreifen.</div>';
        }
        
        $user = wp_get_current_user();
        
        // Überprüfen, ob Benutzer ein Trainer oder Admin ist
        if (!in_array('trainer', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
            return '<div class="error-message">Du hast keine Berechtigung, auf diesen Bereich zuzugreifen.</div>';
        }
        
        // HTML und JavaScript für den QR-Scanner erstellen
        $output = '<div class="qr-scanner-container">';
        $output .= '<h2>QR-Code Scanner für Trainer</h2>';
        
        // Kurse zur Auswahl laden
        $output .= '<div class="kurs-auswahl">';
        $output .= '<label for="kurs-select">Kurs auswählen:</label>';
        $output .= '<select id="kurs-select">';
        $output .= '<option value="">Bitte wählen...</option>';
        
        // Verfügbare Kurse aus den Einstellungen laden
        $verfuegbare_kurse = get_option('tanzschule_verfuegbare_kurse', "Gesellschaftstanz\nZumba\nKindertanzen");
        $kurse = explode("\n", $verfuegbare_kurse);
        foreach ($kurse as $kurs) {
            $kurs = trim($kurs);
            if ($kurs) {
                $output .= '<option value="' . esc_attr($kurs) . '">' . esc_html($kurs) . '</option>';
            }
        }
        
        $output .= '</select>';
        $output .= '</div>';
        
        // Scanner-Bereich
        $output .= '<div id="scanner-container" style="display: none;">';
        $output .= '<div id="qr-reader" style="width: 100%;"></div>';
        $output .= '</div>';
        
        // Ergebnis-Bereich
        $output .= '<div id="scan-result" style="display: none;">';
        $output .= '<h3>Scan-Ergebnis:</h3>';
        $output .= '<div id="member-info"></div>';
        $output .= '<button id="confirm-attendance" class="button">Anwesenheit bestätigen</button>';
        $output .= '</div>';
        
        // Status-Bereich
        $output .= '<div id="scan-status"></div>';
        
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Shortcode für Vertrags-Kündigung
     */
    public function kuendigung_shortcode() {
        // Zugriffskontrolle
        if (!is_user_logged_in()) {
            return '<div class="error-message">Bitte logge dich ein, um auf diese Funktion zuzugreifen. <a href="' . wp_login_url(get_permalink()) . '">Zum Login</a></div>';
        }
        
        $user = wp_get_current_user();
        
        // Überprüfen, ob Benutzer ein Mitglied ist
        if (!in_array('mitglied', (array) $user->roles) && !in_array('administrator', (array) $user->roles)) {
            return '<div class="error-message">Du hast keine Berechtigung, auf diesen Bereich zuzugreifen.</div>';
        }
        
        // Mitgliedsdaten abrufen
        $mitgliedsnummer = get_user_meta($user->ID, 'mitgliedsnummer', true);
        $vertragstyp = get_user_meta($user->ID, 'vertragstyp', true);
        
        // Formular verarbeiten
        $message = '';
        if (isset($_POST['kuendigung_submit']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'tanzschule_kuendigung')) {
            $grund = sanitize_textarea_field($_POST['kuendigung_grund']);
            $datum = isset($_POST['kuendigung_datum']) ? sanitize_text_field($_POST['kuendigung_datum']) : date('Y-m-d', strtotime('+3 months'));
            
            // Kündigungsdaten speichern
            update_user_meta($user->ID, 'kuendigung_beantragt', '1');
            update_user_meta($user->ID, 'kuendigung_datum', $datum);
            update_user_meta($user->ID, 'kuendigung_grund', $grund);
            
            // Admin per E-Mail benachrichtigen
            $admin_email = get_option('admin_email');
            $subject = 'Neue Kündigung - Mitglied: ' . $mitgliedsnummer;
            $body = "Ein Mitglied hat eine Kündigung beantragt:\n\n";
            $body .= "Name: " . $user->display_name . "\n";
            $body .= "E-Mail: " . $user->user_email . "\n";
            $body .= "Mitgliedsnummer: " . $mitgliedsnummer . "\n";
            $body .= "Vertragstyp: " . $vertragstyp . "\n";
            $body .= "Gewünschtes Kündigungsdatum: " . date('d.m.Y', strtotime($datum)) . "\n\n";
            $body .= "Kündigungsgrund:\n" . $grund;
            
            wp_mail($admin_email, $subject, $body);
            
            // Bestätigung an Mitglied senden
            $subject = 'Bestätigung deiner Kündigungsanfrage';
            $body = "Hallo " . $user->display_name . ",\n\n";
            $body .= "wir haben deine Kündigungsanfrage erhalten und werden sie bearbeiten.\n\n";
            $body .= "Deine Angaben:\n";
            $body .= "Mitgliedsnummer: " . $mitgliedsnummer . "\n";
            $body .= "Vertragstyp: " . $vertragstyp . "\n";
            $body .= "Gewünschtes Kündigungsdatum: " . date('d.m.Y', strtotime($datum)) . "\n\n";
            $body .= "Ein Mitarbeiter wird sich bei Fragen mit dir in Verbindung setzen.\n\n";
            $body .= "Viele Grüße\nDeine Tanzschule Falkensee";
            
            wp_mail($user->user_email, $subject, $body);
            
            $message = '<div class="success-message">Deine Kündigungsanfrage wurde erfolgreich übermittelt. Du erhältst in Kürze eine Bestätigung per E-Mail.</div>';
        }
        
        // Prüfen, ob bereits eine Kündigung vorliegt
        $kuendigung_beantragt = get_user_meta($user->ID, 'kuendigung_beantragt', true);
        if ($kuendigung_beantragt) {
            $kuendigung_datum = get_user_meta($user->ID, 'kuendigung_datum', true);
            
            $output = '<div class="tanzschule-dashboard">';
            $output .= '<div class="dashboard-card">';
            $output .= '<h3>Kündigung</h3>';
            $output .= '<div class="info-message">Du hast bereits eine Kündigung eingereicht. Dein Vertrag endet zum ' . date('d.m.Y', strtotime($kuendigung_datum)) . '.</div>';
            $output .= '<p>Wenn du Fragen zu deiner Kündigung hast oder sie zurücknehmen möchtest, kontaktiere uns bitte direkt.</p>';
            $output .= '<p><a href="' . esc_url(site_url('/mitglieder/')) . '" class="button">Zurück zum Dashboard</a></p>';
            $output .= '</div>';
            $output .= '</div>';
            
            return $output;
        }
        
        // Kündigungsformular anzeigen
        $output = '<div class="tanzschule-dashboard">';
        
        // Erfolgsmeldung anzeigen, falls vorhanden
        if ($message) {
            $output .= $message;
        }
        
        $output .= '<div class="dashboard-card">';
        $output .= '<h3>Vertrag kündigen</h3>';
        
        $output .= '<p>Hier kannst du deinen Vertrag kündigen. Bitte beachte, dass die Kündigungsfrist mindestens 3 Monate beträgt.</p>';
        
        $output .= '<form method="post" action="">';
        $output .= wp_nonce_field('tanzschule_kuendigung', '_wpnonce', true, false);
        
        $output .= '<div class="form-field" style="margin-bottom: 20px;">';
        $output .= '<label for="kuendigung_datum">Kündigungsdatum:</label><br>';
        $output .= '<input type="date" name="kuendigung_datum" id="kuendigung_datum" value="' . date('Y-m-d', strtotime('+3 months')) . '" min="' . date('Y-m-d', strtotime('+3 months')) . '" required>';
        $output .= '</div>';
        
        $output .= '<div class="form-field" style="margin-bottom: 20px;">';
        $output .= '<label for="kuendigung_grund">Grund der Kündigung (optional):</label><br>';
        $output .= '<textarea name="kuendigung_grund" id="kuendigung_grund" rows="5" style="width: 100%;"></textarea>';
        $output .= '</div>';
        
        $output .= '<div class="form-field" style="margin-bottom: 20px;">';
        $output .= '<p>Deine Vertragsdaten:</p>';
        $output .= '<ul>';
        $output .= '<li><strong>Name:</strong> ' . esc_html($user->display_name) . '</li>';
        $output .= '<li><strong>Mitgliedsnummer:</strong> ' . esc_html($mitgliedsnummer) . '</li>';
        $output .= '<li><strong>Vertragstyp:</strong> ' . esc_html($vertragstyp) . '</li>';
        $output .= '</ul>';
        $output .= '</div>';
        
        $output .= '<div style="display: flex; gap: 10px; margin-top: 20px;">';
        $output .= '<input type="submit" name="kuendigung_submit" class="button button-warning" value="Vertrag kündigen" onclick="return confirm(\'Bist du sicher, dass du deinen Vertrag kündigen möchtest?\');">';
        $output .= '<a href="' . esc_url(site_url('/mitglieder/')) . '" class="button">Abbrechen</a>';
        $output .= '</div>';
        
        $output .= '</form>';
        $output .= '</div>';
        $output .= '</div>';
        
        return $output;
    }
}

// Benutzerdefinierte Rolle für Mitglieder erstellen
function tanzvertrag_add_roles() {
    add_role(
        'mitglied',
        'Mitglied',
        array(
            'read' => true,
            'edit_profile' => true,
            'upload_files' => false,
            'publish_posts' => false,
        )
    );
    
    add_role(
        'trainer',
        'Trainer',
        array(
            'read' => true,
            'edit_profile' => true,
            'upload_files' => true,
            'publish_posts' => false,
            'edit_posts' => true,
            'scan_qr_codes' => true, // Benutzerdefinierte Capability
        )
    );
}
register_activation_hook(__FILE__, 'tanzvertrag_add_roles');

// Datenmodell für Anwesenheiten und Stundenkontingente
function tanzvertrag_create_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabelle für Anwesenheiten
    $table_attendance = $wpdb->prefix . 'tanzschule_anwesenheit';
    $sql = "CREATE TABLE $table_attendance (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        kurs_id varchar(100) NOT NULL,
        datum datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        stunden_abgezogen tinyint(1) DEFAULT 0 NOT NULL,
        trainer_id bigint(20) NULL,
        kommentar text NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY kurs_id (kurs_id),
        KEY datum (datum)
    ) $charset_collate;";
    
    // Tabelle für Stundenkontingente
    $table_kontingent = $wpdb->prefix . 'tanzschule_kontingent';
    $sql2 = "CREATE TABLE $table_kontingent (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        kontingent_typ varchar(100) NOT NULL,
        stunden_gesamt int(11) NOT NULL,
        stunden_verbraucht int(11) DEFAULT 0 NOT NULL,
        gueltig_bis date NULL,
        erstellt_am datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        gemeinschafts_id mediumint(9) NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    dbDelta($sql2);
}
register_activation_hook(__FILE__, 'tanzvertrag_create_tables');

// Export von Anwesenheitsdaten als CSV
function tanzvertrag_export_anwesenheiten() {
    if (isset($_GET['page']) && $_GET['page'] === 'tanzschule-anwesenheiten' && isset($_GET['export']) && $_GET['export'] === 'csv') {
        // Sicherstellen, dass der Benutzer berechtigt ist
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Parameter abrufen
        $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
        $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
        $kurs_filter = isset($_GET['kurs']) ? sanitize_text_field($_GET['kurs']) : '';
        
        global $wpdb;
        $table_attendance = $wpdb->prefix . 'tanzschule_anwesenheit';
        
        // SQL für Anwesenheiten mit Benutzerdaten
        $sql = "
            SELECT a.*, u.display_name, u.user_email, um.meta_value as mitgliedsnummer 
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
        
        $sql .= " ORDER BY a.datum DESC";
        
        $anwesenheiten = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // CSV-Header senden
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=anwesenheiten-' . date('Y-m-d') . '.csv');
        
        // CSV erstellen
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM für Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header-Zeile
        fputcsv($output, array('Datum', 'Name', 'E-Mail', 'Mitgliedsnummer', 'Kurs', 'Stunde abgezogen', 'Kommentar'));
        
        // Daten schreiben
        foreach ($anwesenheiten as $anwesenheit) {
            fputcsv($output, array(
                date('d.m.Y H:i', strtotime($anwesenheit->datum)),
                $anwesenheit->display_name,
                $anwesenheit->user_email,
                $anwesenheit->mitgliedsnummer,
                $anwesenheit->kurs_id,
                $anwesenheit->stunden_abgezogen ? 'Ja' : 'Nein',
                $anwesenheit->kommentar
            ));
        }
        
        fclose($output);
        exit;
    }
    
    // CSV-Vorlage herunterladen
    if (isset($_GET['page']) && $_GET['page'] === 'tanzschule-import' && isset($_GET['download_template'])) {
        // Sicherstellen, dass der Benutzer berechtigt ist
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // CSV-Header senden
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=tanzschule-mitglieder-vorlage.csv');
        
        // CSV erstellen
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM für Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Header und Beispieldaten
        fputcsv($output, array('Name', 'Email', 'Mitgliedsnummer', 'Vertragstyp', 'Stunden'));
        fputcsv($output, array('Max Mustermann', 'max@beispiel.de', '', 'Gesellschaftstanz', ''));
        fputcsv($output, array('Erika Musterfrau', 'erika@beispiel.de', '', 'Zumba', ''));
        fputcsv($output, array('Peter Test', 'peter@beispiel.de', '', '10er-Karte', '10'));
        
        fclose($output);
        exit;
    }
}
add_action('admin_init', 'tanzvertrag_export_anwesenheiten');

// Plugin initialisieren
$tanzvertrag_plugin = new TanzvertragPlugin();
