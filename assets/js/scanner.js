/**
 * QR-Code Scanner für Tanzschule Falkensee
 * Dieses Script verwaltet den QR-Code Scanner für Trainer
 */

(function($) {
    'use strict';
    
    // Scanner-Variablen
    let html5QrCode;
    let scannerInitialized = false;
    let selectedKursId = '';
    
    // DOM-Elemente
    const $kursSelect = $('#kurs-select');
    const $scannerContainer = $('#scanner-container');
    const $qrReader = $('#qr-reader');
    const $scanResult = $('#scan-result');
    const $memberInfo = $('#member-info');
    const $confirmBtn = $('#confirm-attendance');
    const $scanStatus = $('#scan-status');
    
    // Beim Laden der Seite
    $(document).ready(function() {
        initEvents();
    });
    
    // Event-Handler initialisieren
    function initEvents() {
        // Kurs auswählen
        $kursSelect.on('change', function() {
            selectedKursId = $(this).val();
            
            if (selectedKursId) {
                $scannerContainer.show();
                initializeScanner();
            } else {
                $scannerContainer.hide();
                stopScanner();
            }
        });
        
        // Anwesenheit bestätigen
        $confirmBtn.on('click', function() {
            const userData = $(this).data('user-data');
            saveAttendance(userData);
        });
    }
    
    // QR-Code-Scanner initialisieren
    function initializeScanner() {
        if (scannerInitialized) {
            return;
        }
        
        // Scanner-Konfiguration
        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 },
            rememberLastUsedCamera: true,
            aspectRatio: 1.0
        };
        
        // HTML5 QR-Code-Scanner erstellen
        html5QrCode = new Html5Qrcode("qr-reader");
        
        // Erfolgreicher Scan
        const onScanSuccess = (decodedText, decodedResult) => {
            // Scanner pausieren
            html5QrCode.pause();
            
            // QR-Code-Daten verarbeiten
            processQrCodeData(decodedText);
        };
        
        // Fehler beim Scannen
        const onScanFailure = (error) => {
            // Fehler nur loggen, nicht anzeigen
            console.log(`Scan-Fehler: ${error}`);
        };
        
        // Kamera starten
        html5QrCode.start(
            { facingMode: "environment" }, 
            config,
            onScanSuccess,
            onScanFailure
        ).then(() => {
            scannerInitialized = true;
            $scanStatus.html('<div class="info-message">Scanner bereit. Bitte QR-Code scannen.</div>');
        }).catch((err) => {
            $scanStatus.html('<div class="error-message">Fehler beim Initialisieren der Kamera: ' + err + '</div>');
            
            // Fallback für Desktop/Geräte ohne Kamera
            $qrReader.append('<div class="fallback-container"><p>Kamera nicht verfügbar. Bitte QR-Code manuell eingeben:</p>' +
                '<input type="text" id="manual-qr-input" placeholder="QR-Code-URL eingeben">' +
                '<button id="manual-scan" class="button">Eingabe prüfen</button></div>');
            
            $('#manual-scan').on('click', function() {
                const manualInput = $('#manual-qr-input').val();
                if (manualInput) {
                    processQrCodeData(manualInput);
                }
            });
        });
    }
    
    // Scanner stoppen
    function stopScanner() {
        if (html5QrCode && scannerInitialized) {
            html5QrCode.stop().then(() => {
                scannerInitialized = false;
                $scanStatus.html('');
            });
        }
    }
  // QR-Code-Daten verarbeiten
    function processQrCodeData(qrCodeData) {
        // URL-Parameter aus QR-Code extrahieren
        try {
            const url = new URL(qrCodeData);
            const params = new URLSearchParams(url.search);
            
            // Parameter auslesen
            const mitgliedsnummer = params.get('mid');
            const userId = params.get('uid');
            const timestamp = params.get('t');
            
            if (!mitgliedsnummer || !userId) {
                showError('Ungültiger QR-Code. Erforderliche Daten fehlen.');
                resetScanner();
                return;
            }
            
            // Mitgliedsdaten vom Server abrufen
            fetchMemberData(mitgliedsnummer, userId);
        } catch (error) {
            showError('Der gescannte Code ist kein gültiger QR-Code für die Tanzschule.');
            resetScanner();
        }
    }
    
    // Mitgliedsdaten vom Server abrufen
    function fetchMemberData(mitgliedsnummer, userId) {
        $scanStatus.html('<div class="info-message">Daten werden abgerufen...</div>');
        
        $.ajax({
            url: tanzvertrag_scanner.ajax_url,
            type: 'POST',
            data: {
                action: 'tanzvertrag_get_member_data',
                security: tanzvertrag_scanner.nonce,
                mitgliedsnummer: mitgliedsnummer,
                user_id: userId
            },
            success: function(response) {
                if (response.success) {
                    showMemberInfo(response.data);
                } else {
                    showError(response.data || 'Fehler beim Abrufen der Mitgliedsdaten.');
                    resetScanner();
                }
            },
            error: function() {
                showError('Verbindungsfehler. Bitte versuche es erneut.');
                resetScanner();
            }
        });
    }
    
    // Mitgliedsinformationen anzeigen
    function showMemberInfo(userData) {
        // Daten für Bestätigungsbutton speichern
        $confirmBtn.data('user-data', userData);
        
        // HTML für Mitgliedsinformationen erstellen
        let html = '<div class="member-card">';
        html += '<p class="member-name">' + userData.name + '</p>';
        html += '<p class="member-number">Mitgliedsnummer: ' + userData.mitgliedsnummer + '</p>';
        html += '<p class="contract-type">Vertragstyp: ' + userData.vertragstyp + '</p>';
        
        if (userData.stundenkontingent !== undefined) {
            let kontingentClass = 'kontingent-ok';
            if (userData.stundenkontingent < 3) {
                kontingentClass = 'kontingent-low';
            }
            if (userData.stundenkontingent <= 0) {
                kontingentClass = 'kontingent-empty';
            }
            
            html += '<p class="hours-left ' + kontingentClass + '">Verbleibende Stunden: ' + userData.stundenkontingent + '</p>';
        }
        
        html += '</div>';
        
        // Mitgliedsinformationen anzeigen
        $memberInfo.html(html);
        $scanResult.show();
        $scanStatus.html('');
    }
    
    // Anwesenheit speichern
    function saveAttendance(userData) {
        $scanStatus.html('<div class="info-message">Anwesenheit wird gespeichert...</div>');
        
        $.ajax({
            url: tanzvertrag_scanner.ajax_url,
            type: 'POST',
            data: {
                action: 'tanzvertrag_process_scan',
                security: tanzvertrag_scanner.nonce,
                mitgliedsnummer: userData.mitgliedsnummer,
                user_id: userData.user_id,
                kurs_id: selectedKursId
            },
            success: function(response) {
                if (response.success) {
                    showSuccess(response.data.message);
                    
                    // Wenn eine Stunde abgezogen wurde, zusätzliche Meldung anzeigen
                    if (response.data.user_data.stunde_abgezogen) {
                        const neuesKontingent = response.data.user_data.stundenkontingent;
                        $scanStatus.append('<div class="info-message">Eine Stunde wurde vom Kontingent abgezogen. Neuer Stand: ' + neuesKontingent + ' Stunden</div>');
                    }
                    
                    // Scanner zurücksetzen nach kurzer Verzögerung
                    setTimeout(resetScanner, 3000);
                } else {
                    showError(response.data || 'Fehler beim Speichern der Anwesenheit.');
                    resetScanner();
                }
            },
            error: function() {
                showError('Verbindungsfehler. Bitte versuche es erneut.');
                resetScanner();
            }
        });
    }
    
    // Erfolgsmeldung anzeigen
    function showSuccess(message) {
        $scanStatus.html('<div class="success-message">' + message + '</div>');
    }
    
    // Fehlermeldung anzeigen
    function showError(message) {
        $scanStatus.html('<div class="error-message">' + message + '</div>');
    }
    
    // Scanner zurücksetzen
    function resetScanner() {
        setTimeout(function() {
            $scanResult.hide();
            $memberInfo.empty();
            
            // Scanner fortsetzen, wenn noch initialisiert
            if (scannerInitialized && html5QrCode) {
                html5QrCode.resume();
                $scanStatus.html('<div class="info-message">Scanner bereit. Bitte QR-Code scannen.</div>');
            }
        }, 2000);
    }
})(jQuery);
