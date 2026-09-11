# EhrenSache – Testplan (v1.1.x)

## Teststrategie & Vorgehen

**Prioritätenreihenfolge:**
1. Authentifizierung & Session (alles hängt davon ab)
2. Rollenbasierte Zugriffssteuerung (RBAC) – Quer durch alle Features
3. Kernfunktionen: Mitglieder → Termine → Anwesenheitserfassung → Ausnahmen
4. Check-in-Wege (Auto, TOTP)
5. Statistik & Auswertung
6. Import/Export
7. Einstellungen, Geräteverwaltung, Benutzerverwaltung

**Testrollen (Accounts bereitstellen):**
- `admin_test` – Rolle: admin, mit verknüpftem Mitglied
- `manager_test` – Rolle: manager, mit verknüpftem Mitglied
- `user_test` – Rolle: user, mit verknüpftem Mitglied
- `user_nolink` – Rolle: user, **ohne** verknüpftes Mitglied
- `device_totp` – Rolle: device, Typ: totp_location
- `device_auth` – Rolle: device, Typ: auth_device

---

## 1. Installation & Erreichbarkeit

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| SETUP-1 | `GET /api/api.php?resource=ping` vor Installation | HTTP 503, `status: "not_installed"` |
| SETUP-2 | `GET /api/api.php?resource=ping` nach Installation | HTTP 200, `status: "ok"`, Version vorhanden |
| SETUP-3 | Direktzugriff auf `private/` im Browser | HTTP 403 / Forbidden – niemals Dateiinhalt |
| SETUP-4 | Direktzugriff auf `install/` nach Installation | HTTP 403 (via `.htaccess` gesperrt) |
| SETUP-5 | `config.php` im Browser abrufbar | HTTP 403 / Forbidden |

---

## 2. Authentifizierung & Session

### 2.1 Login (Session-basiert – Web)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AUTH-1 | Gültige Zugangsdaten | 200, `success: true`, `csrf_token` in Antwort, Session-Cookie gesetzt |
| AUTH-2 | Falsches Passwort | 401, `"Ungültige Anmeldedaten"` |
| AUTH-3 | Unbekannte E-Mail | 401, `"Ungültige Anmeldedaten"` |
| AUTH-4 | Deaktivierter Account (`is_active=0`) | 403, `"Account deaktiviert"` |
| AUTH-5 | Gesperrter Account (`account_status='suspended'`) | 403, `"Account wurde gesperrt"` |
| AUTH-6 | Ausstehender Account (`account_status='pending'`) | 403, `"Account wurde noch nicht aktiviert"` |
| AUTH-7 | 5× falsche Anmeldung in 15 Min. | 429, `"Zu viele Login-Versuche. Bitte versuchen Sie es in 15 Minuten erneut."` |
| AUTH-8 | Login als Device-User (role=device) | Muss fehlschlagen oder eingeschränkten Zugang liefern |

### 2.2 Login (Token-basiert – PWA/API)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AUTH-9 | Gültige Zugangsdaten per Token-Login | 200, `api_token` in Antwort |
| AUTH-10 | Abgelaufenes Token | Neues Token wird generiert, `expires_at` auf +1 Jahr |
| AUTH-11 | Bearer Token im Header (`Authorization: Bearer …`) | Authentifizierung erfolgreich |
| AUTH-12 | `X-API-Key` Header | Authentifizierung erfolgreich |
| AUTH-13 | `?api_token=…` Query-Parameter | Authentifizierung erfolgreich (IoT-Geräte) |

### 2.3 Session-Management

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| SESSION-1 | Nach Login: `GET /me` | 200, user_id, email, role, `auth_type: "session"` |
| SESSION-2 | Nach Token-Auth: `GET /me` | 200, zusätzlich member_id, `auth_type: "token"` |
| SESSION-3 | Inaktivität > 30 Min. (Frontend-Timeout) | Redirect zu Login; Session-Warnung erscheint vorher |
| SESSION-4 | Inaktivität > 60 Min. (Server-Session) | 401 `"Session expired due to inactivity"` bei nächstem API-Call |
| SESSION-5 | Logout | Session zerstört; folgende Requests liefern 401 |
| SESSION-6 | POST ohne CSRF-Token (Session-Auth) | 403 `"Invalid CSRF token"` |
| SESSION-7 | DELETE ohne CSRF-Token als Query-Param | 403 `"Invalid CSRF token"` |
| SESSION-8 | Token-Auth: CSRF-Prüfung | Wird übersprungen (CSRF nur für Sessions) |

---

## 3. Rate Limiting

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| RATE-1 | > 150 API-Requests/Minute von gleicher IP | 429, Rate-Limit-Meldung |
| RATE-2 | Request nach Ablauf des Zeitfensters | Normal verarbeitet |

---

## 4. Rollenbasierte Zugriffssteuerung (RBAC)

Diese Tests systematisch mit allen Rollen durchführen:

| ID | Ressource & Methode | admin | manager | user | device |
|----|---------------------|-------|---------|------|--------|
| RBAC-1 | GET /members (alle) | ✓ | ✓ | Nur eigene Daten | 403 |
| RBAC-2 | POST /members | ✓ | ✓ | 403 | 403 |
| RBAC-3 | DELETE /members | ✓ | ✓ | 403 | 403 |
| RBAC-4 | GET /users (Liste) | ✓ | 403 | 403 | 403 |
| RBAC-5 | POST /users | ✓ | 403 | 403 | 403 |
| RBAC-6 | GET /settings | ✓ | 403 | 403 | 403 |
| RBAC-7 | POST /import | ✓ | 403 | 403 | 403 |
| RBAC-8 | GET /export | ✓ | ✓ | 403 | 403 |
| RBAC-9 | GET /statistics | Alle Gruppen | Alle Gruppen | Nur eigene | 403 |
| RBAC-10 | POST /auto_checkin für anderes Mitglied | ✓ | ✓ | 403 (Warnung + eigener Check-in) | ✓ |
| RBAC-11 | DELETE /exceptions (fremde Anfrage) | ✓ | ✓ | 403 | 403 |

---

## 5. Mitgliederverwaltung

### 5.1 Abrufen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| MEM-GET-1 | Admin: alle Mitglieder | Vollständige Liste, inkl. Gruppen |
| MEM-GET-2 | `include_inactive=true` | Inaktive Mitglieder enthalten |
| MEM-GET-3 | Filter `group_id=X` | Nur Mitglieder der Gruppe X |
| MEM-GET-4 | Filter `year=2025` (mit membership_dates) | Nur Mitglieder aktiv in 2025 |
| MEM-GET-5 | Filter `year=2025` (ohne membership_dates) | Alle aktiven Mitglieder |
| MEM-GET-6 | User: `GET /members/{fremde_id}` | 403 Access denied |
| MEM-GET-7 | User: `GET /members/{eigene_mitglied_id}` | Eingeschränkte Daten (Name, Nummer, Gruppen) |
| MEM-GET-8 | User ohne Mitgliedsverknüpfung | Leeres Array |

### 5.2 Erstellen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| MEM-POST-1 | Gültige Pflichtfelder (name, surname) | 201, member_id zurück |
| MEM-POST-2 | Fehlendes `name` | 400 Fehler |
| MEM-POST-3 | Fehlendes `surname` | 400 Fehler |
| MEM-POST-4 | Doppelte Mitgliedsnummer | Erlaubt (kein UNIQUE-Constraint) |

### 5.3 Bearbeiten & Löschen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| MEM-PUT-1 | Name/Vorname ändern | 200, aktualisiert |
| MEM-PUT-2 | `active=0` setzen | 200, Mitglied inaktiv |
| MEM-PUT-3 | Nicht existierende ID | 404 `"Member not found"` |
| MEM-DEL-1 | Mitglied löschen | 200; CASCADE: records, exceptions, membership_dates gelöscht |

---

## 6. Terminverwaltung

### 6.1 Abrufen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| APT-GET-1 | Admin: alle Termine | Vollständige Liste, sortiert nach Datum DESC |
| APT-GET-2 | User: Termine abrufen | Nur Termine der Gruppen, in denen der User Mitglied ist |
| APT-GET-3 | User in keiner Gruppe | Leeres Array |
| APT-GET-4 | Filter `year=2025` | Nur 2025er Termine |
| APT-GET-5 | Filter `year=2025&month=3` | Nur März 2025 |
| APT-GET-6 | Filter `from_date` / `to_date` | Datumsbereich korrekt |
| APT-GET-7 | Filter `type_id=X` | Nur Termine dieses Typs |
| APT-GET-8 | Einzeltermin per ID | Vollständig inkl. type_name, color, description |

### 6.2 Erstellen & Bearbeiten

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| APT-POST-1 | Gültig (title, date, start_time) | 201 erstellt |
| APT-POST-2 | Fehlendes `title` | 400 |
| APT-POST-3 | Ungültiges Datumsformat | 400 |
| APT-PUT-1 | `type_id` ändern | 200, Gruppenfilterung aktualisiert |
| APT-DEL-1 | Termin löschen | 200; CASCADE: Records und Exceptions gelöscht |

---

## 7. Anwesenheitserfassung (Records)

### 7.1 Abrufen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| REC-GET-1 | Admin: alle Records | Vollständig inkl. Mitglieds- und Termindetails |
| REC-GET-2 | User: eigene Records | Nur eigene member_id |
| REC-GET-3 | User: fremde Record-ID | 403 Access denied |
| REC-GET-4 | Filter `appointment_id=X` | Nur Records für diesen Termin |
| REC-GET-5 | Filter `status=excused` | Nur entschuldigte Einträge |
| REC-GET-6 | User ohne Mitglied | Leeres Array |

### 7.2 Erstellen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| REC-POST-1 | Gültig (member_id, appointment_id) | 201; arrival_time aus Termin-start_time |
| REC-POST-2 | Mit eigenem `arrival_time` | 201; angegebene Zeit gespeichert |
| REC-POST-3 | Duplikat (gleiche member_id + appointment_id) | 409 `"Already checked in"` |
| REC-POST-4 | Fehlendes `member_id` | 400 |
| REC-POST-5 | Fehlendes `appointment_id` | 400 |
| REC-POST-6 | Termin ohne start_time | Fallback auf NOW() |

### 7.3 Bearbeiten & Löschen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| REC-PUT-1 | `arrival_time` korrigieren | 200 aktualisiert |
| REC-PUT-2 | `status` auf 'excused' setzen | 200 aktualisiert |
| REC-PUT-3 | Änderung würde Duplikat erzeugen | 409 Konflikt |
| REC-DEL-1 | Einzelnen Record löschen | 200 |
| REC-DEL-2 | Bulk-Delete per `member_id` | Alle Records gelöscht, Anzahl zurück |
| REC-DEL-3 | Bulk-Delete mit `before_date` | Nur Records vor Datum gelöscht |

---

## 8. Ausnahmen (Abwesenheits-/Zeitkorrekturanträge)

### 8.1 Abrufen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| EXC-GET-1 | Admin: alle Ausnahmen | Vollständig inkl. Mitglied, Termin, Antragsteller |
| EXC-GET-2 | User: eigene Ausnahmen | Nur eigene member_id |
| EXC-GET-3 | User: fremde Ausnahme per ID | 403 Access denied |
| EXC-GET-4 | Filter `status=pending` | Nur ausstehende |
| EXC-GET-5 | Filter `type=time_correction` | Nur Zeitkorrekturen |

### 8.2 Erstellen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| EXC-POST-1 | User: eigener Abwesenheitsantrag | 201, `status=pending` |
| EXC-POST-2 | User: Zeitkorrektur mit `requested_arrival_time` | 201, Zeit gespeichert |
| EXC-POST-3 | User: Antrag für fremdes Mitglied | 403 `"You can only create requests for yourself"` |
| EXC-POST-4 | Admin: Antrag für beliebiges Mitglied | 201 erlaubt |
| EXC-POST-5 | Fehlendes `exception_type` | 400 |
| EXC-POST-6 | Fehlendes `reason` | 400 |

### 8.3 Bearbeiten (Genehmigen/Ablehnen)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| EXC-PUT-1 | Admin genehmigt Abwesenheit | `status=approved`; Record mit `status=excused` erstellt |
| EXC-PUT-2 | Admin genehmigt Zeitkorrektur | `status=approved`; Record mit korrigierter `arrival_time` |
| EXC-PUT-3 | Admin lehnt ab | `status=rejected` |
| EXC-PUT-4 | Manager genehmigt | Erlaubt |
| EXC-PUT-5 | User versucht eigene Ausnahme zu genehmigen | 403 |

### 8.4 Löschen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| EXC-DEL-1 | User löscht eigene ausstehende Anfrage | 200 |
| EXC-DEL-2 | User löscht bereits genehmigte Anfrage | 403 (nur pending löschbar) |

---

## 9. Check-in

### 9.1 Auto-Checkin

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| CIN-1 | User: Self-Check-in | Record mit `checkin_source='auto_checkin'` |
| CIN-2 | Admin: Check-in für Mitglied per `member_id` | Record mit `checkin_source='admin_session'` |
| CIN-3 | Admin: Check-in per `member_number` | Mitglied aufgelöst, Record erstellt |
| CIN-4 | Device: Check-in per `member_number` | Record mit `checkin_source='device_auth'` |
| CIN-5 | User ohne Mitgliedsverknüpfung | 403 `"No member linked to your account"` |
| CIN-6 | User gibt fremde `member_id` an | Warnung; eigene member_id verwendet |
| CIN-7 | Fehlendes `arrival_time` | 400 `"arrival_time is required"` |
| CIN-8 | Ungültiges `arrival_time`-Format | 400 |
| CIN-9 | Unbekannte `member_number` | 404 `"Member not found"` |
| CIN-10 | Zweiter Check-in desselben Mitglieds beim gleichen Termin | 409 Konflikt |
| CIN-11 | Automatik aus (`checkin_auto_create_appointment='0'`), Check-in ohne Treffer | Toast „Kein passender Termin gefunden", kein neuer Termin |
| CIN-12 | Automatik an, Check-in ohne Treffer | Toast nennt den angelegten Termin, Badge in der Verwaltung |
| CIN-13 | Termin in der PWA-Auswahl wählen, einchecken | Record hängt an diesem Termin |
| CIN-14 | Hinweistext unter der Terminauswahl bei beiden Schalterstellungen prüfen | Zweiter Satz wechselt je nach `checkin_auto_create_appointment` |
| CIN-15 | PWA nach 22:00 Uhr öffnen | Auswahl zeigt heutige Termine, nicht die von morgen |
| CIN-16 | `checkin_tolerance_hours` auf 4 setzen, Termin 3 h entfernt | Check-in trifft ihn |
| CIN-17 | Termin von 10:00 Uhr in der PWA wählen, um 16:47 Uhr einchecken | 409 `appointment_outside_tolerance`, kein Check-in |
| CIN-18 | Erfassen-Tab bei zwei Absichten öffnen, dann erneut antippen | Zurück zur Absichtswahl, kein Zurück-Pfeil sichtbar |
| CIN-19 | Anwesenheits-Ansicht öffnen, Terminauswahl steht am Ende | Scan/NFC/Code/Antrag vor der Auswahl, keine optische Pflichtfeld-Wirkung |
| CIN-20 | Anwesenheits-Ansicht öffnen, Termin im Toleranzfenster vorhanden | Banner „📍 Du checkst ein für: …" erscheint, Auswahl vorbelegt |
| CIN-21 | Anwesenheits-Ansicht öffnen, kein Termin im Fenster | Kein Banner, Auswahl leer, bestehender Hinweistext unverändert |
| CIN-22 | Anderen Termin von Hand wählen | Banner wechselt auf den neu gewählten Termin |
| CIN-23 | Tab verlassen (z. B. Verlauf) und zu „Erfassen" zurückkehren, nachdem von Hand gewählt wurde | Auswahl bleibt erhalten, wird nicht durch die automatische Suche überschrieben |
| CIN-24 | Erfolgreichen Check-in durchführen | Liste und Banner aktualisieren sich mit dem neuen Stand |
| CIN-25 | Antragsdialog öffnen | Unverändert zu 1.2.4, verlangt weiterhin einen bestehenden Termin |

### 9.2 TOTP-Checkin

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| TOTP-1 | Gültiger 6-stelliger TOTP-Code | 200; Record mit `checkin_source='user_totp'` |
| TOTP-2 | Ungültiger Code | 401 `"Ungültiger oder abgelaufener TOTP Code"` |
| TOTP-3 | Code kürzer/länger als 6 Stellen | 400 `"Code must be exactly 6 digits"` |
| TOTP-4 | Keine TOTP-Stationen konfiguriert | 400 `"Keine TOTP-Stationen konfiguriert"` |
| TOTP-5 | Benutzer ohne Mitglied | 403 |

---

## 10. Benutzerverwaltung

### 10.1 Abrufen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| USR-GET-1 | Admin: alle Benutzer | Liste mit role_name, status_text |
| USR-GET-2 | Admin: Geräte (`user_type=device`) | Liste mit device_type, is_active |
| USR-GET-3 | Non-Admin: User-Liste | 403 `"Admin Access required"` |
| USR-GET-4 | Jeder: eigenes Profil per ID | 200, vollständige Daten |
| USR-GET-5 | User: fremdes Profil per ID | 403 |

### 10.2 Erstellen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| USR-POST-1 | Admin erstellt Benutzer | 201; `api_token` generiert; `account_status=active` |
| USR-POST-2 | Doppelte E-Mail | 409 `"Diese E-Mail-Adresse ist bereits registriert"` |
| USR-POST-3 | Ungültige E-Mail | 400 `"Ungültige Email-Adresse"` |
| USR-POST-4 | Fehlendes Passwort | 400 `"Passwort darf nicht leer sein"` |
| USR-POST-5 | Passwort < 6 Zeichen | 400 `"Passwort muss mindestens 6 Zeichen lang sein"` |
| USR-POST-6 | Ungültige Rolle | 400 |
| DEV-POST-1 | Gerät erstellen (`action=create_device`) | 201, `role=device`, TOTP-Secret generiert |

### 10.3 Bearbeiten & Löschen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| USR-PUT-1 | Admin: Rolle ändern | 200 |
| USR-PUT-2 | Admin: Benutzer deaktivieren | `is_active=0` |
| USR-PUT-3 | Admin: Mitglied verknüpfen | `member_id` gesetzt |
| USR-PUT-4 | Non-Admin: fremden User ändern | 403 |
| USR-DEL-1 | Admin löscht Benutzer | 200 |
| USR-DEL-2 | Non-Admin löscht | 403 |

---

## 11. Gruppen & Termintypen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| GRP-1 | Gruppe erstellen | 201, `group_name`, `is_default` |
| GRP-2 | `is_default=true` setzen | Alle anderen Gruppen auf `false` gesetzt |
| GRP-3 | Mitglieder einer Gruppe hinzufügen | Verknüpfung in `member_group_assignments` |
| GRP-4 | Gruppe löschen | CASCADE: Zuordnungen gelöscht; Mitglieder bleiben |
| APTTYPE-1 | Termintyp erstellen | `type_name`, `color`, `is_default` |
| APTTYPE-2 | Termintyp mit Gruppen verknüpfen | `appointment_type_groups` befüllt |
| APTTYPE-3 | Termine nach Typ filtern | Korrekt auf Gruppen gefiltert |

---

## 12. Mitgliedschaftszeiträume

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| MEMD-1 | Zeitraum erstellen (start_date, end_date optional) | 201 |
| MEMD-2 | Mehrere überlappende Zeiträume | Erlaubt |
| MEMD-3 | Jahresfilter bei Mitgliedern | Nur aktive Zeiträume im Jahr |

---

## 13. Statistik

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| STAT-1 | Admin: alle Gruppen | Gesamt: Termine, Anwesend, Entschuldigt, Unentschuldigt |
| STAT-2 | User: eigene Statistik | Nur eigene member_id |
| STAT-3 | User: fremde member_id | 403 oder Warnung; nur eigene Daten |
| STAT-4 | Filter nach `group_id` | Nur Gruppenmitiglieder und -termine |
| STAT-5 | Filter `appointment_type_id` | Nur dieser Typ |
| STAT-6 | Kein Datenjahr | Nullwerte zurück |
| STAT-7 | Prozentberechnung | `(present / total) * 100` korrekt |
| STAT-8 | Verfügbare Jahre | Aktuelles Jahr immer enthalten; sortiert DESC |
| STAT-9 | Gruppe mit mehreren Terminarten öffnen | Je Terminart eine Spalte, Namen stimmen |
| STAT-10 | Terminart ohne Termine im gewählten Jahr | Spalte vorhanden, Wert „–" |
| STAT-11 | Summe der `by_type`-Werte eines Mitglieds | ergibt seine Gesamtzahlen |
| STAT-12 | Kopfzahlen gegen die Summe der Gruppentabellen | gleich, solange sich keine Terminarten überschneiden |

### 13.1 Anwesenheitsbericht (Druckansicht)

Automatisiert abgedeckt in `tests/suites/report_api.php`. Hier steht, was ein Testlauf nicht
sehen kann: das Druckbild, das Verhalten echter Browser und die Rolle `device`.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| BER-1 | Admin: Statistik öffnen, Jahr und Gruppe wählen, „📄 Bericht" | Neuer Tab; die Kennzahlen des Berichts stimmen mit den Karten auf dem Bildschirm überein |
| BER-2 | Gruppe wechseln, erneut öffnen | Der Bericht folgt der Filterauswahl |
| BER-3 | Admin: ein Mitglied im Filter wählen | Abschnitt „Termine im Einzelnen" erscheint; ohne Mitgliedsfilter fehlt er |
| BER-4 | User: Statistik öffnen, „📄 Bericht" | Knopf sichtbar; Bericht zeigt nur die eigene Person, mit Terminliste |
| BER-5 | Herkunftsspalte an einem von Hand angelegten Eintrag | `nachgetragen`; bei einem Stations-Check-in `gemessen`; bei genehmigter Zeitkorrektur `korrigiert` |
| BER-6 | Zeile mit Status „Entschuldigt" | Ankunft und Herkunft bleiben leer |
| BER-7 | Druckvorschau in Chrome **und** Firefox | Kopfzeile, Logo, Tabellen und Fußnoten sitzen; keine abgeschnittenen Spalten |
| BER-8 | Bericht ohne hinterlegtes Vereinslogo | Kopfzeile bleibt sauber, kein leeres Bild |
| BER-9 | Bericht auf einer Demo-Installation | Hinweisblock „kein gültiger Nachweis" erscheint |
| BER-10 | Fußnote zur ausgewerteten Terminart | Nennt je Gruppe eine Terminart; **keine** interne Vorgangsnummer auf dem Blatt |
| BER-11 | **Geräte-Token:** `?resource=statistics_report` aufrufen | 403 |
| BER-12 | **Geräte-Token:** `?resource=export&type=worktime_member&format=html` | 403 |

BER-11 und BER-12 haben bewusst keinen automatischen Test: `apiToken()` meldet sich mit E-Mail
und Passwort an, Gerätekonten authentifizieren sich per Token. Ein erfundener `device`-Eintrag
in `tests/config.php` zerbräche beim nächsten Lauf des Demo-Generators.

### 13.2 Stundennachweis in der Rolle `user`

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| BER-13 | User: Zeiterfassung → „📄 Bericht" | Dialogtitel „Mein Stundennachweis"; keine Berichtsart-Auswahl, kein Mitgliedsfeld, kein CSV-Knopf |
| BER-14 | User: Druckansicht auslösen, Adresse ansehen | Enthält `format=html`, **kein** `member_id` |
| BER-15 | Im selben Tab abmelden, als Admin anmelden, Dialog öffnen | Dialog wieder vollständig — keine Einschränkung bleibt hängen |
| BER-16 | Admin: Berichtsart auf „Summen nach Tätigkeit" und zurück | Mitgliedsfeld verschwindet und erscheint wieder |

---

## 14. Import / Export

### Export

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| EXP-1 | Mitglieder exportieren | CSV, UTF-8 BOM; `name;surname;member_number;active;groups` |
| EXP-2 | Termine exportieren | `date;start_time;title;type_name;groups;description` — **`type_name`**, nicht `type` |
| EXP-3 | Anwesenheiten exportieren (Jahresfilter) | `member_name;member_surname;member_number;appointment_date;appointment_start_time;appointment_type;appointment_title;arrival_date_time;status;checkin_source` |
| EXP-4 | Gruppen pipe-separiert | `GROUP_CONCAT` korrekt |
| EXP-5 | Terminschlüssel in EXP-3 gefüllt | `appointment_date`, `appointment_start_time` und `appointment_type` tragen Werte, nicht nur Überschriften |

### Import

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| IMP-1 | Gültige Mitglieder-CSV | Mitglieder erstellt/aktualisiert; import_log Eintrag |
| IMP-2 | Datei > 5 MB | 413 `"File too large"` |
| IMP-3 | Falscher MIME-Typ (z.B. .xlsx) | 400 `"Invalid file type"` |
| IMP-4 | Datei mit `<?php` Tag | 400 `"File contains forbidden code"` |
| IMP-5 | Ungültiges CSV-Format | Fehler im Log; Partial-Import |
| IMP-6 | `import_logs` Eintrag | total_rows, successful_rows, failed_rows, errors korrekt |

### Round-Trip: Export → Import

> **Nur auf einem Testsystem durchführen.** Diese Fälle schreiben in die Datenbank, und
> RT-6 legt bewusst Termine an. Die automatisierte Suite `export_import` prüft deshalb nur
> die Kopfzeilen — der Beleg, dass eine exportierte Datei tatsächlich wieder eingeht, bleibt
> dieser manuelle Durchgang.

Vorlage für die Struktur sind die drei Dateien, mit denen der Import zuletzt geprüft wurde
(je ein Datensatz, Spalten in Projektreihenfolge).

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| RT-1 | Mitglieder exportieren, unverändert reimportieren | Kein neuer Datensatz, keine Fehlerzeile; Gruppen bleiben zugeordnet (`\|`-Trennung greift in beide Richtungen) |
| RT-2 | Termine exportieren, unverändert reimportieren | Keine Duplikate, keine Fehlerzeile — insbesondere **kein** „missing required columns" |
| RT-3 | Anwesenheiten exportieren, unverändert reimportieren | Jede Zeile landet wieder an **demselben** Termin wie zuvor |
| RT-4 | **Kernfall:** Zwei Termine *verschiedener Art* am selben Abend anlegen (z. B. Probe 19:00, Vorstandssitzung 20:00), je eine Anwesenheit erfassen, exportieren, reimportieren | Beide Anwesenheiten kehren an ihren eigenen Termin zurück. Vor dem Terminschlüssel wären beide am zeitlich nächstgelegenen gelandet |
| RT-5 | Datei mit den alten Spaltennamen `type` bzw. `arrival_time` einlesen | Wird angenommen — archivierte Exporte bleiben lesbar |
| RT-6 | Anwesenheits-CSV mit einem Termin, den es im Ziel nicht gibt: **ohne** Haken importieren | Fehlerzeile „No appointment found within … hours"; **kein** Termin angelegt |
| RT-7 | Dieselbe Datei **mit** `create_missing_appointments` | Termin wird mit Datum, Zeit, Art und Titel aus der Datei angelegt; Antwort meldet `appointments_created: 1` |
| RT-8 | RT-7 mit einer Terminart, die im Ziel **nicht** existiert | Fehlerzeile „Unknown appointment type …"; kein Termin, keine Terminart angelegt |
| RT-9 | `extract_appointments` auf dieselbe Datei | Liefert nur `suggestions`; die Terminliste ist danach **unverändert** |
| RT-10 | Verschobener Termin: Startzeit im Ziel um 3 h ändern, dann reimportieren | Weder exakter Treffer noch Toleranzfenster greifen → Fehlerzeile. Bewusst so; siehe Restrisiko in OI-24 |

---

## 15. Systemeinstellungen

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| SET-1 | Admin: Einstellungen abrufen | Alle Key-Value-Paare |
| SET-2 | Non-Admin: Einstellungen abrufen | 403 |
| SET-3 | Organisationsname ändern | `INSERT … ON DUPLICATE KEY UPDATE`; sofort wirksam |
| SET-4 | Logo hochladen | Pfad gespeichert; korrekt ausgeliefert |
| SET-5 | SMTP-Konfiguration speichern | 200 |
| SET-6 | SMTP-Test-E-Mail senden | E-Mail bei Empfänger angekommen |

---

## 16. Passwort & Token

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| PWD-1 | Passwort ändern (eigenes) | 200; neues Passwort funktioniert |
| PWD-2 | Passwort-Reset via E-Mail | Token generiert; Reset-Link zugestellt |
| TOKEN-1 | API-Token regenerieren | Neuer Token; alter Token ungültig |
| TOKEN-2 | Token mit abgelaufenem `expires_at` | 401 Unauthorized |

---

## 17. Frontend-Querschnittstests

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| UI-1 | Seite nach Session-Ablauf (30 Min.) | Warnung erscheint; Redirect zu Login |
| UI-2 | Caching: Mitgliederliste nach Änderung | Cache invalidiert; frische Daten geladen |
| UI-3 | Caching: Termine jahresweise | Nur das geänderte Jahr invalidiert |
| UI-4 | Navigation ohne Login | Redirect zur Login-Seite |
| UI-5 | PWA: Service Worker registriert | Offline-Funktionalität vorhanden |
| UI-6 | Statistik-Chart: Daten korrekt | Anzeige stimmt mit API-Werten überein |

---

## 18. Kritische End-to-End-Szenarien

| ID | Szenario | Schritte | Erwartetes Ergebnis |
|----|----------|----------|---------------------|
| E2E-1 | **Vollständiger Onboarding-Prozess** | Admin erstellt Benutzer → verknüpft Mitglied → User loggt ein → sieht eigene Statistik | Zugang zu eigenen Daten, keine fremden |
| E2E-2 | **Ausnahmen-Workflow** | User stellt Abwesenheitsantrag → Admin genehmigt → Record mit status=excused erscheint | Statistik zeigt korrekt "entschuldigt" |
| E2E-3 | **TOTP Check-in komplett** | Gerät konfiguriert → User scannt Code → Record erstellt → Anwesenheitsliste aktualisiert | Zuverlässige Anwesenheitserfassung |
| E2E-4 | **Parallelzugriff** | Zwei gleichzeitige Check-ins für dasselbe Mitglied beim gleichen Termin | Zweiter Request liefert 409 |
| E2E-5 | **Import → Export Konsistenz** | Mitglieder exportieren → CSV anpassen → re-importieren → Daten prüfen | Änderungen korrekt übernommen |
| E2E-6 | **Rollenrechte-Eskalationsversuch** | User sendet `POST /users` mit gültigen Daten | 403 `"Zugriff verweigert"` – keine Umgehung möglich |
| E2E-7 | **Mitglied löschen mit Daten** | Mitglied mit Records/Exceptions/membership_dates löschen | Alle verknüpften Daten per CASCADE gelöscht |
| E2E-8 | **Jahreswechsel** | Termine für neues Jahr anlegen | `available_years` enthält neues Jahr; Statistik nur für jeweiliges Jahr |

---

## 19. Bekannte Bugs (aus CLAUDE.md)

Diese sollten explizit getestet werden:

| ID | Bug | Test |
|----|-----|------|
| BUG-1 | Dropdowns Records/Exceptions nicht gegenseitig gefiltert | Record für Termin A eintragen, dann Exception für Termin B desselben Mitglieds anlegen → sollte auf gleiche Termine beschränkt sein |

---

## Priorisierung der Testdurchführung

```
Sofort (Blocker):   SETUP, AUTH, RBAC, E2E-4 (Concurrency), BUG-1
Hoch:               MEMBERS, APPOINTMENTS, RECORDS, CHECKIN, EXCEPTIONS
Mittel:             STATISTICS, USERS, GROUPS, MEMBERSHIP_DATES
Niedrig:            IMPORT_EXPORT, SETTINGS, PWD/TOKEN, UI-Tests
```

---

## Update-Wizard (Migrationskette)

### Automatisiert

```bash
php tests/run.php
```

Reine Logik ohne Datenbank: Versionsbestimmung, Normalisierung, Manifest, Kettenauflösung.

```bash
php tests/db/verify_migration_chain.php "mysql:host=127.0.0.1;port=3306" root ""
```

Bildet UPD-1 bis UPD-6 nach: Versionserkennung, Ausführung und Stempelung der Kette,
Umbenennung auf das Präfix, Umbau der `config.php`, Folgenlosigkeit eines zweiten Laufs.

```bash
php tests/db/verify_schema_convergence.php "mysql:host=127.0.0.1;port=3306" root "" ez_
```

Beantwortet die Frage, die der Kettentest offen lässt: Er spielt für den Fall „Stand 1.0.0"
das **heutige** Schema ein, Migrationen laufen dort also gegen Spalten, die es schon gibt.
Dieses Skript startet mit dem echten Schema aus dem Tag `v1.1.3`, füllt es mit Daten in den
Spalten von damals, fährt die Kette bis zur Version aus `version.json` und vergleicht das
Ergebnis Tabelle für Tabelle mit einer Neuinstallation — Spalten samt Typ, Nullbarkeit,
Vorgabe, `EXTRA` und Generierungsausdruck, dazu alle Indizes. Nötig wird das, weil beide Wege
sonst unbemerkt auseinanderlaufen: Ein Verein, der aktualisiert, und einer, der neu
installiert, hätten dann verschiedene Schemata.

Die Zugriffssperren von Installer und Assistent:

```bash
php tests/run.php htaccess_locks
```

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| SP-1 | `public/update/.htaccess` und `public/install/.htaccess` | beide Syntaxen, je im `<IfModule>`-Wächter |
| SP-2 | Direktiven der Datei gegen die des erzeugenden Skripts | identisch — sonst laufen beide Fassungen auseinander |
| SP-3 | `GET /public/update/` und `/public/install/` im Browser | **403**, nicht 500; im Apache-Log `authz_core AH01630` |

Gegen eine echte Datenbank. Legt die Wegwerf-Datenbank `ehrensache_chaintest` an und
entfernt sie am Ende; bestehende Datenbanken und `private/config/config.php` bleiben
unberührt. Deckt UPD-1 bis UPD-5a ab.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| UPD-1 | Neuinstallation, dann `schema_version` lesen | Genau eine Zeile mit der Version aus `version.json` |
| UPD-2 | Kette bei aktuellem Stand auflösen | „bereits auf Stand X", keine Warnungen |
| UPD-3 | Kette bei Stand 1.0.0 | `1.0.0 → 1.1.3`, Tabellen erhalten das Prefix, `config.php` bekommt `$prefix` und `table()`, beide Versionen gestempelt |
| UPD-4 | Zweiter Lauf | Folgenlos, keine Fehler, keine Warnungen |
| UPD-5 | Leere Datenbank | `detectDbVersion` meldet `unbekannt`, klare Fehlermeldung statt PHP-Fatal |
| UPD-5a | Echte 1.0.0-Datenbank (Tag `v1.0.0`, Tabellen ohne Präfix), Erkennung mit **leerem** Präfix | `1.0.0` erkannt, Kette läuft bis zur Zielversion. Der Fall aus der Wirklichkeit, den UPD-3 nicht abbildet: Dort wird ein Präfix mitgegeben, obwohl eine 1.0.0-`config.php` keines kennt |
| UPD-6 | `php tests/run.php` | Alle Suites bestehen, Exit-Code 0 |

### Manuell (Oberfläche)

Die Wizard-Oberfläche selbst ist nicht automatisiert: mehrstufig, mit Session-Zustand und
`.htaccess`-Selbstsperre. Vor einem Release einmal von Hand durchgehen.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| UPD-7 | `public/update/.htaccess` öffnen, Wizard aufrufen, Schritte 1–3 | Schritt 1 zeigt erkannte und Zielversion, Schritt 3 protokolliert die Kette |
| UPD-8 | Nach erfolgreichem Lauf `public/update/.htaccess` prüfen | Enthält `Deny from all` — der Wizard hat sich selbst gesperrt |
| UPD-9 | Wizard ohne `install.lock` aufrufen | Systemprüfung schlägt fehl, keine Migration möglich |

---

## Zeiterfassung (Kern)

Das Feature hängt an `worktime_enabled`. Ist es aus, antworten beide Ressourcen mit `404`
und der PWA-Tab bleibt verborgen.

### Automatisiert

```bash
php tests/run.php
```

86 Tests: Migrationslogik, Zeitrechnung und Validierung ohne Datenbank, dazu alle Endpunkte
gegen die laufende Instanz. Die API-Suite räumt alles wieder ab, was sie anlegt.

```bash
php tests/db/verify_stale_sessions.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
```

Der automatische Abschluss überfälliger Sitzungen. Eigenes Skript, weil dafür `start_time`
direkt in der Datenbank verschoben werden muss — im Handler gibt es dafür bewusst keine
Testhintertür.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| WT-1 | `php tests/run.php` | 86 Tests bestehen, Exit-Code 0 |
| WT-2 | `worktime_enabled = 0`, dann `GET activity_types` / `GET work_sessions` | Beide `404` |
| WT-3 | Timer-Start ohne Termin | `source = timer`, `status = confirmed` — zählt ohne Freigabe |
| WT-4 | Zweiter Start bei laufender Sitzung | `409`, der Unique-Index greift |
| WT-5 | Start mit Termin ohne vorhandenen Check-in | `records`-Eintrag mit `checkin_source = timer` entsteht |
| WT-6 | Start mit Termin nach einem früheren Check-in | `arrival_time` und `checkin_source` bleiben unverändert |
| WT-7 | Manueller Eintrag | `status = submitted`, zählt erst nach Freigabe |
| WT-8 | Mitglied ändert eigenen bestätigten Eintrag | Fällt auf `submitted` zurück |
| WT-9 | Manager ändert einen bestätigten Eintrag | Bleibt `confirmed` |
| WT-10 | Ende vor Beginn, Pause ≥ Bruttodauer, Zeiten in der Zukunft | Jeweils `400` |
| WT-11 | Löschen durch Manager / durch Admin | `403` / `200` |
| WT-12 | Tätigkeitsart löschen, an der Sitzungen hängen | `409` mit Hinweis auf `is_active = 0` |
| WT-13 | Sitzung älter als `worktime_max_session_hours` | Gekappt auf die Obergrenze, `status = submitted`, Vermerk `auto_closed` im Log |
| WT-14 | Auditspur nach dem Löschen einer Sitzung | Einträge bleiben, `delete` hält den letzten Stand |

### Manuell (PWA)

Die Oberfläche lässt sich nicht sinnvoll automatisieren. Vor einem Release einmal durchgehen,
angemeldet als Mitglied mit verknüpftem `member_id`.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| WT-P1 | `worktime_enabled = 0`, PWA öffnen | Tab „Zeit" nicht sichtbar, keine Konsolenfehler außer dem bekannten Service-Worker-Hinweis über HTTP |
| WT-P2 | Tab „Zeit", Tätigkeit wählen, **Start** | Anzeige wechselt auf die laufende Uhr mit Tätigkeitsnamen |
| WT-P3 | **Pause** | Knopf wird zu **Weiter**, Uhr läuft weiter |
| WT-P4 | Seite neu laden, Tab „Zeit" | Sitzung ist noch da **und** noch pausiert — der Zustand kommt vom Server |
| WT-P5 | **Weiter**, Notiz eintragen, **Stopp** | Meldung mit erfassten Minuten, Eintrag erscheint in „Erfasste Zeiten" mit Notiz und „✓ bestätigt" |
| WT-P6 | Erneut starten nach dem Stoppen | Funktioniert, keine `409` |

**Testdaten:** Mindestens eine Tätigkeitsart muss existieren, sonst ist die Auswahl leer.
Die automatisierten Suites legen eigene an und entfernen sie wieder — für die manuelle
Prüfung eine dauerhafte Art anlegen.

---

## Zeiterfassung: Ortsnachweis (Stufe 2)

Setzt mindestens eine aktive TOTP-Station voraus (Dashboard → Geräte, Typ
`totp_location` mit Secret). Ohne Station beendet das Prüfskript sich mit Exit-Code 3.

### Automatisiert

```bash
php tests/db/verify_totp_location.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
```

Eigenes Skript, weil gültige Codes nur mit dem Secret der Station erzeugt werden können.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| OT-1 | `resolveTotpLocation` mit gültigem Code | Liefert die Station mit `device_name` |
| OT-2 | Mit falschem Code, falschem Format, leerem String | Jeweils `null` |
| OT-3 | Mit einem Code aus fünf Zeitfenstern zurück | `null` — Toleranz ist ein Fenster |
| OT-4 | `verification = none`, Code trotzdem mitgesendet | Wird in `start_location_name` festgehalten |
| OT-5 | `verification = start`, Start ohne Code | `403`, kein `force`-Ausweg |
| OT-6 | `verification = start`, Start mit ungültigem Code | `401` |
| OT-7 | `verification = start`, Stoppen | Braucht keinen Code, bleibt `confirmed` |
| OT-8 | `verification = start_end`, Stopp ohne Code | `409` mit Hinweis auf `force` |
| OT-9 | `verification = start_end`, Stopp mit `force` | Beendet, `status = submitted` |
| OT-10 | `verification = start_end`, Stopp mit Code | Start und Ende belegt, bleibt `confirmed` |
| OT-11 | Pause und Weiter bei `start_end` | Verlangen nie einen Code |
| OT-12 | Ortsbelegter Start mit Termin | `records`-Eintrag mit `checkin_source = user_totp` und Stationsnamen |

### Manuell (PWA)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| OT-P1 | Nachweispflichtige Tätigkeit wählen, **Start** | Wechsel zum Check-in-Tab, blauer Hinweis „Code für den Start der Zeiterfassung scannen" |
| OT-P2 | Code per QR, NFC **oder** manueller Eingabe liefern | Zurück zum Zeit-Tab, Meldung „Gestartet · Ort belegt: <Station>" |
| OT-P3 | Statt zu scannen auf „Verlauf" wechseln, dann normal einchecken | Der Check-in läuft normal — die Anforderung der Zeiterfassung ist verfallen |
| OT-P4 | Bei `start_end` auf **Stopp** | Fordert erneut einen Code an |
| OT-P5 | „Ohne Nachweis beenden" | Eigenes Modal der App; nach Bestätigung „Beendet ohne Nachweis — wartet auf Freigabe", Eintrag erscheint als `pending` |
| OT-P6 | Tätigkeit ohne Nachweispflicht | Start ohne Umweg, kein „Ohne Nachweis beenden"-Knopf |

---

## Zeiterfassung: Auswertung (Stufe 3)

### Automatisiert

```bash
php tests/db/verify_worktime_reporting.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
```

Misst bewusst **Differenzen** statt absoluter Summen — in einer benutzten Datenbank liegen
bereits Sitzungen, und ein Test, der eine leere Tabelle voraussetzt, schlägt aus dem
falschen Grund fehl.

```bash
php tests/db/verify_proof_drop.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
```

Prüft, was über HTTP nicht erreichbar ist: Eine Sitzung mit gesetztem Ortsnachweis verliert bei
verschobenem Beginn genau `start_location_name`, bei verschobenem Ende genau
`end_location_name`, behält bei einer reinen Notizkorrektur beide und behält beide auch, wenn
derselbe Zeitpunkt in anderer Schreibweise mitkommt.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AW-1 | `statistics` ohne `include` | Kein `worktime`-Block |
| AW-2 | `statistics?include=worktime` | Summiert nur `confirmed` mit `end_time`; `submitted` zählt nicht |
| AW-3 | Nachweisgrade im `worktime`-Block | Getrennt nach stundenbelegt / teilbelegt / unbelegt |
| AW-4 | Aufschlüsselung nach Tätigkeitsart | Summen je Art stimmen |
| AW-5 | `statistics?include=worktime` als `user` | Nur das eigene Mitglied |
| AW-6 | `worktime_enabled = 0` | `worktime`-Block bleibt `null` |
| AW-7 | `export?type=worktime_member` | CSV mit Nachweisgrad, Einzelzeilen und Summenblock |
| AW-8 | Export enthält keine `submitted`-Einträge | Nicht bestätigte Zeiten fehlen im Nachweis |
| AW-9 | `export?type=worktime_activity` | Summen je Tätigkeitsart plus Gesamtzeile |
| AW-10 | Export als `user` | `403` — Auswertungen sind Managern vorbehalten |
| AW-11 | `my_data` | Enthält `work_sessions` **und** `work_session_log` |

### Manuell (Dashboard)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AW-D1 | `worktime_enabled = 0`, Dashboard laden | Navigationspunkt „Zeiterfassung" und Block „Tätigkeitsarten" bleiben verborgen |
| AW-D2 | Als `user` die Sektion öffnen | Nur eigene Einträge; kein Mitgliedsfilter, keine Export-Knöpfe; eigene Einträge bearbeitbar |
| AW-D3 | Als Manager/Admin | Alle Einträge, Mitgliedsfilter gefüllt, Export-Knöpfe sichtbar |
| AW-D4 | Eintrag mit Status „wartet auf Freigabe" | Vier Aktionen: Freigeben, Ablehnen, Bearbeiten, Löschen |
| AW-D5 | Freigeben klicken | Status wechselt auf „bestätigt", Auditspur erhält `approve` |
| AW-D6 | „Zeit nachtragen" speichern | Eintrag erscheint mit Status „wartet auf Freigabe" |
| AW-D7 | Tätigkeitsart mit erfassten Zeiten löschen | Fehlermeldung; Ausmustern über „Aktiv"-Haken bleibt möglich |
| AW-D8 | Knopf „📄 Bericht" | Dialog öffnet sich, Zeitraum auf das laufende Jahr vorbelegt |

### Manuell (PWA)

Angemeldet als Mitglied mit verknüpftem `member_id` und mindestens einer Tätigkeitsart.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AW-P1 | Mitglied ohne Tätigkeitsarten, Tab „Statistik" | Kein Block „Arbeitszeit"; Karten und Gruppenübersicht unverändert |
| AW-P2 | Mitglied mit bestätigten Stunden | Block zeigt die Jahressumme als `h:mm h`, darunter „bestätigt · N Sitzungen" |
| AW-P3 | Summe gegen den Bericht desselben Jahres | Beide Zahlen stimmen überein |
| AW-P4 | Ein Eintrag im Status „wartet auf Freigabe" | Fußnote „… aus 1 Eintrag wartet auf Freigabe"; er zählt nicht in die Summe |
| AW-P5 | Jahr mit 0 bestätigten, 1 eingereichten Eintrag | „0:00 h" **und** Fußnote — keine kommentarlose Null |
| AW-P6 | Jahr ohne jede Sitzung | Eine Zeile „Keine Stunden in <Jahr>", keine leere Liste |
| AW-P7 | Jahreswechsel über ‹ › | Summe, Fußnote und Tätigkeitsliste wechseln mit |
| AW-P8 | Abmelden, anderes Konto anmelden | Kein Rest des Vorgängers im Statistik-Tab |
| AW-P9 | Verlauf, „✎ Korrigieren" an einer bestätigten Sitzung, Beginn zwei Stunden zurücksetzen | Status fällt auf „wartet auf Freigabe", Nachweisgrad im Dashboard auf „unbelegt" |
| AW-P10 | Dieselbe Sitzung, nur die Notiz ändern | Zeiten und Nachweisgrad bleiben unverändert |
| AW-P11 | „Zeit nachtragen", Beginn und Ende ausfüllen, speichern | Neuer Eintrag im Verlauf mit „wartet auf Freigabe" |
| AW-P12 | „Zeit nachtragen" mit Ende vor Beginn | Fehlerkasten im Modal mit der Servermeldung, Modal bleibt offen |
| AW-P13 | `worktime_require_note = 1`, Nachtrag ohne Notiz | Fehlerkasten „Eine Notiz ist erforderlich" |
| AW-P14 | Laufende Sitzung im Verlauf | Kein Korrigieren-Knopf |
| AW-P15 | Sitzung mit einer Tätigkeit aus einer inzwischen verlassenen Gruppe korrigieren | Die Tätigkeit steht in der Auswahl und bleibt vorausgewählt |
| AW-P16 | Modal offen lassen, abmelden, neu anmelden | Kein offenes Modal, keine Werte des Vorgängers |

---

## Zeitraum und Druckansicht (ab 1.2.2)

### Automatisiert

```bash
php tests/run.php worktime_unit
```

```bash
php tests/run.php worktime_api
```

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| ZR-1 | `worktimeResolvePeriod` mit Jahr, Monat, freiem Zeitraum | Grenzen, Label und Slug je Fall korrekt |
| ZR-2 | `to` vor `from`, unparsbares Datum, 30. Februar, über 24 Monate | jeweils abgewiesen |
| ZR-3 | `export?type=worktime_member&from=…&to=…` | `200` |
| ZR-4 | Ungültiger Zeitraum am Export | `400` mit benannter Meldung |
| ZR-5 | `format=html` | HTML-Dokument mit `print.css` |
| ZR-6 | Notiz mit `<script>alert(1)</script>` im Bericht | erscheint maskiert, nicht ausgeführt |
| ZR-7 | Bericht enthält kein `<script>` und kein `window.print` | bestätigt |

### Manuell

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| ZR-M1 | Sitzung 31.01. 22:00 – 01.02. 02:00 anlegen und freigeben | erscheint **vollständig** im Januarbericht, **gar nicht** im Februarbericht |
| ZR-M2 | Summe Januar + Summe Februar gegen Zeitraum 01.01.–28.02. | identisch |
| ZR-M3 | Schnellwahl „Dieser Monat", „Letzter Monat", „Laufendes Jahr" | füllt Von/Bis; „Laufendes Jahr" folgt dem Jahresfilter, nicht dem heutigen Datum |
| ZR-M4 | Berichtsart auf „nach Tätigkeit" wechseln | Mitgliedsauswahl verschwindet |
| ZR-M5 | „📄 Druckansicht" | öffnet neuen Tab; **kein** Druckdialog beim Laden |
| ZR-M6 | Im Browser drucken, Vorschau prüfen | Querformat; Kopfzeile wiederholt sich auf Folgeseiten; keine Zeile über den Umbruch zerrissen |
| ZR-M7 | Fußnote der Druckansicht | Zuordnungsregel und alle drei Nachweisgrade erklärt |
| ZR-M8 | „💾 CSV" mit Monatszeitraum | Dateiname trägt den Monat, z. B. `stundennachweis_2026-01.csv` |
| ZR-M9 | Zeitraum über 24 Monate wählen | **Toast im Dialog**, kein Seitenwechsel und kein neuer Tab. Die Meldung darf nicht als JSON-Seite erscheinen |
| ZR-M10 | Bericht ohne Logo (Einstellung leer) | Kopf ohne Bild, kein gebrochenes Bildsymbol |

---

## Terminbezug beim Nachtragen (ab 1.2.2)

### Automatisiert

```bash
php tests/run.php worktime_api
```

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| TB-1 | Nachtrag mit `appointment_id` | Sitzung trägt den Termin |
| TB-2 | Nachtrag mit Termin, danach Freigabe | **kein** `records`-Eintrag, weder beim Anlegen noch bei der Freigabe |
| TB-3 | Korrektur trägt einen Termin nach | Termin gesetzt |
| TB-4 | Korrektur mit leerem `appointment_id` | Zuordnung gelöst |
| TB-5 | Korrektur ohne das Feld im Payload | Termin bleibt unangetastet |
| TB-6 | Korrektur mit unbekanntem `appointment_id` | `400` |

### Manuell (Dashboard)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| TB-M1 | „Zeit nachtragen" öffnen | Feld „Termin" mit „Kein Termin" vorbelegt, darunter der Hinweis, dass kein Anwesenheitseintrag entsteht |
| TB-M2 | Terminliste aufklappen | Termine des gewählten Jahres, neueste zuerst, mit Datum im Eintrag |
| TB-M3 | Nachtrag mit Termin speichern, Liste prüfen | Spalte „Termin" zeigt den Titel statt „—" |
| TB-M4 | Denselben Eintrag erneut öffnen | Der Termin ist vorausgewählt |
| TB-M5 | Termin auf „Kein Termin" setzen und speichern | Spalte zeigt wieder „—" |
| TB-M6 | Anwesenheitsliste des Termins prüfen | **kein** Eintrag für dieses Mitglied entstanden |
| TB-M7 | Bericht „Summen nach Termin" | Die nachgetragenen Stunden erscheinen unter dem Termin, nicht mehr unter „(ohne Termin)" |
| TB-M8 | Jahresfilter auf ein anderes Jahr stellen, Eintrag mit Termin aus dem Vorjahr öffnen | Der Termin steht mit dem Zusatz „(zugeordnet)" in der Liste und bleibt vorausgewählt — er darf beim Speichern nicht verlorengehen |

---

## Zeiterfassung ohne Anwesenheitskopplung (ab 1.2.3)

### Automatisiert

```bash
php tests/run.php worktime_api
```

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AK-1 | Timer-Start mit Termin **heute** | **kein** `records`-Eintrag |
| AK-2 | Timer-Start bei bereits vorhandenem Check-in | Der bestehende Eintrag bleibt unverändert |
| AK-3 | `GET activity_types` | `appointment_type_ids` vorhanden, leer bei unverknüpfter Art |
| AK-4 | `POST` mit `appointment_type_ids` | Verknüpfung angelegt |
| AK-5 | `PUT` mit leerem Array | Verknüpfung gelöst — zulässig, anders als bei `group_ids` |
| AK-6 | `PUT` ohne das Feld | Verknüpfung unangetastet |
| AK-7 | `PUT` mit unbekannter `type_id` | `400` |

### Manuell

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| AK-M1 | PWA: Zeiterfassung mit Termin heute starten, danach Anwesenheitsliste des Termins | **kein** neuer Eintrag |
| AK-M2 | Anwesenheitsquote des Mitglieds vor und nach dem Start vergleichen | unverändert |
| AK-M3 | PWA: Terminauswahl öffnen | Termine des ganzen Jahres, nächstgelegene zuerst, mit Datum |
| AK-M4 | PWA: Termin wählen | Hinweis sagt, dass die Stunden zugerechnet werden und **kein** Check-in entsteht |
| AK-M5 | Tätigkeitsart mit zwei Terminarten verknüpfen, dann in PWA und Nachtrag-Dialog wählen | Terminliste zeigt nur Termine dieser Arten |
| AK-M6 | Tätigkeitsart ohne Verknüpfung wählen | Terminliste zeigt alle Termine |
| AK-M7 | Bei gewähltem Termin die Tätigkeitsart wechseln, sodass der Termin nicht mehr passt | Der Termin bleibt gewählt und in der Liste |
| AK-M8 | Update-Wizard auf einer Installation mit alten Timer-Check-ins | Protokoll nennt deren Anzahl als Warnung; die Einträge bleiben |

---

## Gruppenbindung der Tätigkeitsarten (ab 1.2.1)

**Vorbereitung:** Zwei Gruppen anlegen, das Testmitglied nur einer davon zuordnen.
Eine Tätigkeitsart der Gruppe des Mitglieds zuordnen, eine zweite ausschließlich der
anderen Gruppe.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| GB-1 | Dashboard: Tätigkeitsart anlegen, eine Gruppe ankreuzen | Übersicht zeigt die Gruppe als Badge |
| GB-2 | Dieselbe Art erneut öffnen | Die Checkbox ist gesetzt |
| GB-3 | Häkchen entfernen, speichern | Übersicht zeigt „Keine" |
| GB-4 | PWA als Mitglied öffnen | Nur die Tätigkeitsart der eigenen Gruppe steht zur Wahl |
| GB-5 | Start der fremden Art per Direktaufruf der API | 403 `"Activity type not allowed for this member"` |
| GB-6 | Nachtrag der fremden Art durch das Mitglied selbst | 403, gleiche Meldung |
| GB-7 | Nachtrag durch Manager **für dieses Mitglied** | 201 — Stellvertretung bleibt erlaubt |
| GB-8 | Mitglied in eine Gruppe **ohne** Tätigkeitsarten setzen, PWA laden | Kein Kachel-Einstieg, direkt der Anwesenheits-Scanner; im Dashboard fehlt der Menüpunkt „Zeiterfassung" |
| GB-9 | Als Admin dieselbe Lage | Menüpunkt und Stammdatenblock bleiben sichtbar — sonst ließe sich die erste Art nie anlegen |

---

## PWA: Erfassen-Tab (ab 1.2.1)

**Diese Reihe ist vor jedem Merge nach `main` vollständig zu durchlaufen.** Sie berührt
den Check-in, die meistgenutzte Funktion der Anwendung, und kein automatisierter Test
deckt sie ab.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| ERF-1 | Beide Absichten verfügbar, Tab öffnen | Zwei Kacheln: Anwesenheit, Arbeitszeit |
| ERF-2 | Nur Anwesenheit verfügbar | Direkt der Scanner, keine Kachelseite, kein „Zurück" |
| ERF-3 | Kachel Anwesenheit → QR scannen | Anwesenheitseintrag entsteht |
| ERF-4 | Kachel Arbeitszeit, nachweisfreie Tätigkeit, Start | Sitzung läuft, Timer sichtbar |
| ERF-5 | **Fehlerweg:** Arbeitszeit → nachweispflichtige Art → Start → nicht scannen → Zurück → Anwesenheit → scannen | **Anwesenheitseintrag**, KEINE Arbeitszeitsitzung |
| ERF-6 | Sucher läuft: „Abbrechen" | Kamera aus, zurück zum Ausgangsformular |
| ERF-7 | Sucher läuft: „Code eingeben" | Kamera aus, Eingabefeld erscheint; eingegebener Code wirkt für die Absicht, aus der gestartet wurde |
| ERF-8 | Sucher läuft | „← Zurück" ist ausgeblendet; beide Knöpfe gleich hoch, „Abbrechen" farblich abgesetzt |
| ERF-9 | Zweck über dem Sucher | „📍 Anwesenheit erfassen" bzw. „⏱️ Zeiterfassung starten" — passend zur Absicht |
| ERF-10 | Tätigkeit mit `start_end` beenden | Sucher öffnet erneut, Zweck weist auf das Beenden hin |
| ERF-11 | Breiten prüfen (360 px und 320 px) | Knöpfe und „Zurück" fluchten; Terminauswahl ändert die Breite nicht |

---

## PWA: Laufende Sitzung und Verlauf (ab 1.2.1)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| LS-1 | Sitzung starten | Leiste über der Tab-Leiste zeigt Tätigkeit und laufende Zeit |
| LS-2 | Auf Verlauf und Statistik wechseln | Leiste bleibt sichtbar und zählt weiter |
| LS-3 | **App neu laden, Arbeitszeit-Ansicht NICHT öffnen** | Leiste steht sofort — der eigentliche Zweck |
| LS-4 | Pause | Symbol wechselt zu ⏸, die Zeit steht still |
| LS-5 | Leiste antippen | Führt zur Arbeitszeit-Ansicht, aus jedem Tab |
| LS-6 | Stoppen | Leiste verschwindet |
| VL-1 | Verlauf öffnen | Anwesenheiten (📍), Anträge (📋) und Arbeitszeiten (⏱️) in einer Zeitachse |
| VL-2 | Sortierung | Neuester Eintrag oben, quellenübergreifend |
| VL-3 | Laufende Sitzung | Erscheint als „läuft", ohne Freigabestatus |
| VL-4 | Antrag mit Status „pending" | Liest sich als „Wartet auf Freigabe" — gleicher Wortlaut wie bei Arbeitszeiten |
| VL-5 | Mitglied ohne Zeiterfassung | Nur Anwesenheiten und Anträge, keine Fehlermeldung |
| VL-6 | Arbeitszeit-Ansicht | Enthält KEINE eigene Liste „Erfasste Zeiten" mehr |

---

## PWA: Fehlermeldungen (ab 1.2.1)

Jede Meldung mindestens einmal auslösen und den Text lesen. Der Schlüssel ist die
englische Servermeldung, nicht der Statuscode — siehe `API.md`, Abschnitt Arbeitszeiten.

| ID | Auslöser | Erwarteter Text |
|----|----------|-----------------|
| FM-1 | Zweiter Start bei laufender Sitzung | „Es läuft bereits eine Zeiterfassung. Bitte zuerst beenden." |
| FM-2 | Start ohne Code bei `verification != none` | „Diese Tätigkeit verlangt beim Start den QR-Code der Station." |
| FM-3 | Start einer Art aus fremder Gruppe | „Diese Tätigkeit ist für deine Gruppe nicht vorgesehen." |
| FM-4 | Falscher Code beim Timer | „Der Code ist ungültig oder abgelaufen." |
| FM-5 | Pause ohne laufende Sitzung | „Es läuft gerade keine Zeiterfassung." |
| FM-6 | Tätigkeit gelöscht, App noch offen, Start | „Diese Tätigkeit gibt es nicht mehr. Bitte die App neu laden." |
| FM-7 | Unbekannte Meldung vom Server | Fällt auf den allgemeinen Statustext zurück; Rohmeldung steht im `debug.log` |

---

## DSGVO-Bereinigung (ab 1.2.5)

> **Warnung:** `cleanup` löscht endgültig, ohne Probelauf und ohne Rückgängig. Vor jedem
> manuellen Testfall eine Sicherung der Datenbank anlegen. Die automatisierten Tests arbeiten
> ausschließlich mit Fristen von 30 und 100 Jahren, die nur ihre eigenen Testdaten treffen —
> eine kleine Frist in diesen Suiten eingetragen, und der Bestand ist weg.

### Automatisiert

```bash
php tests/run.php cleanup_unit
```

```bash
php tests/run.php cleanup_api
```

Zusätzlich, weil zurückdatierte `start_time`/`changed_at` nötig sind:

```bash
php tests/db/verify_cleanup_retention.php "mysql:host=127.0.0.1;port=3306;dbname=ehrensache" root "" ez_
```

DL-11 bis DL-13 laufen im Kettentest mit (Fall UPD-6), auf einer Wegwerf-Datenbank:

```bash
php tests/db/verify_migration_chain.php "mysql:host=127.0.0.1;port=3306" root ""
```

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| DL-1 | `retentionYears(0)`, `(-1)`, `('drei')`, `('2.5')` | jeweils `null` — die Fristprüfung schlägt vor jedem `DELETE` zu |
| DL-2 | `POST cleanup` als `user` bzw. `manager` | `403` |
| DL-3 | `POST cleanup` mit nicht numerischer Frist | `400`, `field` benennt das Feld |
| DL-4 | Antwort eines gültigen Laufs | drei Stichtage, fünf Zählwerte |
| DL-5 | Beendete Sitzung jenseits der Arbeitszeitfrist | gelöscht, ihre Logzeilen mit |
| DL-6 | Sitzung von heute | bleibt |
| DL-7 | Laufende Sitzung mit altem `start_time` | bleibt — Fehlerfall, kein Löschfall |
| DL-8 | Verwaiste Logzeile jenseits der Auditfrist | `changes` und `changed_by` sind `NULL`, die Zeile bleibt |
| DL-9 | Verwaiste Logzeile innerhalb der Auditfrist | unverändert |
| DL-10 | Logzeile einer jungen Sitzung | unverändert |
| DL-11 | Migration 1.2.4 → 1.2.5 mit `dsgvo-cleanup-years` = 7 | Wert steht danach in `cleanup_years_records`, alter Schlüssel entfernt, Protokoll meldet die Übernahme |
| DL-12 | dieselbe Migration mit unbrauchbarem Wert (0) | Vorgabe 3, Warnung im Protokoll |
| DL-13 | dieselbe Migration, wenn `cleanup_years_records` bereits existiert | vorhandener Wert bleibt, Protokoll behauptet keine Übernahme, Warnung nennt beide Werte |

### Manuell (Dashboard)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| DL-M1 | Einstellungen → DSGVO Datenverwaltung | Drei Fristfelder mit Beschriftung, Vorgaben 3/3/1 |
| DL-M2 | Eine Frist auf 0 setzen und speichern | Feld wird als ungültig markiert, `min="1"` greift |
| DL-M3 | Fristen ändern, speichern, Seite neu laden | Werte stehen noch — sie liegen in `system_settings` |
| DL-M4 | Auf „Alte Daten löschen" klicken | Rückfrage nennt **alle drei** Fristen einzeln und den Hinweis auf die Endgültigkeit |
| DL-M5 | Rückfrage abbrechen | Nichts passiert, keine Meldung |
| DL-M6 | Rückfrage bestätigen | Ergebnisblock nennt Anwesenheiten, Ausnahmen, Arbeitszeiten, Logzeilen und anonymisierte Einträge mit den jeweiligen Stichtagen |
| DL-M7 | Update-Wizard durchlaufen (Kern automatisiert als DL-11 bis DL-13) | Schritt 3 nennt die Zeilen zu den drei Fristen; danach stehen die Werte in den Einstellungsfeldern |

---

## 20. Virtuelle Station (Kiosk, seit 1.3.0)

Automatisiert: `php tests/run.php station_unit` und `php tests/run.php station_api`.
Manuell im Dashboard:

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| ST-1 | Gerät vom Typ „Virtuelle Station" mit Code-Anzeige anlegen | Nach dem Speichern öffnet sich das Gerät im Bearbeiten-Modal mit Token (Auge/Kopieren); kein Secret sichtbar, nur das Häkchen |
| ST-2 | Häkchen „zeigt Stations-Code" entfernen, speichern | `GET station&action=totp` antwortet 404 |
| ST-3 | Einstellung „Stations-Anmeldung" aus | Profil zeigt keine PIN-Karte; Mitglieds-Modal kein PIN-Feld; `PUT members` mit `pin` → 409 |
| ST-4 | Einstellung an, Mitglied bearbeiten, PIN `1234` | Fehlermeldung „keine Zahlenfolge", nicht gespeichert |
| ST-5 | PIN `2580` setzen | Liste zeigt 🔢 an der Mitgliedsnummer; Modal-Hinweis „PIN gesetzt (Datum)" |
| ST-6 | Profil: PIN mit falschem Passwort ändern | Fehlertoast, Formular behält die Eingaben |
| ST-7 | Anwesenheiten nach einem Kiosk-Stempel | Badge „🖥️ Station (PIN)" mit Kiosk-Name als Ort |
| ST-8 | Selbstauskunft herunterladen (JSON und CSV) | `has_pin` und `pin_updated_at` bzw. Zeilen „Stations-PIN gesetzt"/„PIN zuletzt geändert", kein Hash |
| ST-9 | Neues Mitglied mit ungültiger PIN anlegen | Mitglied wird angelegt, Modal bleibt im Bearbeiten-Modus offen, Fehlertoast zur PIN |
| ST-10 | Im selben Browser erst `station/` mit Token aufrufen, dann am Dashboard anmelden | Dashboard bleibt nutzbar, kein 401 nach dem Login |
| ST-11 | Kiosk: „Weiter" ohne Mitgliedsnummer | Roter Hinweis „Nummer darf nicht leer sein" unter dem Ziffernblock; verschwindet beim nächsten Tastendruck |
| ST-12 | Kiosk: Taste „ABC" im Ziffernblock, dann „123" | Buchstabentastatur mit Bindestrich, Umschalter zurück ist doppelt breit; Navigationszeile zeigt nur Abbrechen/Weiter |
| ST-13 | Kiosk: PIN-Bild nach Eingabe von „B77" | Unter „PIN" steht „Mitgliedsnummer: B77"; nach Abbruch oder Ruhezeit ist der Text weg |
| ST-14 | Kiosk: Seite mitten in einer TOTP-Periode neu laden, dann drei Codewechsel abwarten | Balken beginnt sofort bei der tatsächlichen Restlaufzeit (nicht voll) und läuft bei jedem neuen Code von 100 % auf 0 %; bleibt nie grau |

---

## 21. Sitzungswechsel in der PWA und Mitgliedsfilter (seit 1.3.1)

Drei Fehlerbilder aus 1.3.1, die alle davon leben, dass die PWA ohne Reload weiterläuft
und der Bereichswechsel im Dashboard seine Daten im Hintergrund nachlädt. Ein Reload
verdeckt sie — deshalb steht in jedem Testfall, ob nachgeladen werden darf.

Automatisiert: `php tests/run.php worktime_frontend` (statische Gegenproben) und
`php tests/run.php worktime_api`.

### Mitgliedsfilter der Zeiterfassung (Dashboard)

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| MF-1 | Als Admin anmelden und **als Erstes** die Zeiterfassung öffnen, ohne vorher die Mitgliederliste zu besuchen | Der Filter „Mitglied" ist gefüllt, nicht nur „Alle Mitglieder" |
| MF-2 | Im selben Zug „Zeit nachtragen" öffnen | Die Mitgliedsauswahl im Dialog ist gefüllt; Mitglieder ohne Mitgliedschaft im gewählten Jahr fehlen |
| MF-3 | Ein Mitglied im Filter wählen | Tabelle und Kennzahl „bestätigte Stunden" zeigen nur dessen Einträge |
| MF-4 | Jahr wechseln, danach den Filter aufklappen | Auswahl passt zum neuen Jahr, ausgetretene Mitglieder tragen „(inaktiv)" |
| MF-5 | Als Manager statt Admin | Gleiches Verhalten; als einfaches Mitglied ist der Filter gar nicht sichtbar |

### Verlauf der Check-in-PWA

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| PV-1 | Als **Admin** in der PWA anmelden, Verlauf öffnen | Ausschließlich eigene Arbeitszeiten — die Gesamtübersicht gibt es nur im Dashboard |
| PV-2 | Dasselbe als Manager | Ebenfalls nur eigene Einträge |
| PV-3 | Als Admin abmelden, als einfaches Mitglied anmelden, **ohne Reload** in den Verlauf | Kein Eintrag des Admins; die Zeitachse gehört dem neu angemeldeten Mitglied |
| PV-4 | Nach dem Abmelden, **ohne Reload** | Die App startet im Erfassen-Tab, nicht im zuletzt geöffneten; Statistik und Anwesenheitsliste sind leer |
| PV-5 | Abmelden während einer laufenden Sitzung, neu anmelden | Die Leiste über der Tab-Leiste zeigt keine fremde Sitzung mehr |

### Doppelt gebundene Ereignisse (Check-in-PWA)

> Alle Fälle brauchen **denselben Reiter ohne Reload**: anmelden → abmelden → wieder anmelden.
> Genau dieser Zyklus hat die Ereignisse zuvor ein zweites Mal angehängt.

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| DE-1 | Nach dem Zyklus eine Zeiterfassung starten | Genau eine Sitzung; kein Fehlertoast „Es läuft bereits eine Zeiterfassung" |
| DE-2 | Sitzung beenden | Läuft einmal durch, keine zweite fehlschlagende Anfrage |
| DE-3 | In der Statistik einmal auf „◀" oder „▶" | Das Jahr ändert sich um **eins** |
| DE-4 | Auf „Verlauf" und „Statistik" wechseln | Im `debug.log` je eine „Loading …"-Zeile, nicht zwei |
| DE-5 | Als Verwalter einen Termin über die Anwesenheitsliste anlegen | Der Termin entsteht **einmal** |
| DE-6 | „Ohne Ortsnachweis beenden" antippen | Ein Bestätigungsdialog, nicht zwei übereinander |

---

## 22. Schnellinbetriebnahme des Kiosks per QR-Code (seit 1.5.0)

Automatisiert: `php tests/run.php assets` (Skriptpfade und externe Skripte).
Manuell — Dashboard und Station:

| ID | Testfall | Erwartetes Ergebnis |
|----|----------|---------------------|
| QR-1 | Kiosk bearbeiten, 📱 drücken | Modal „🖥️ Station in Betrieb nehmen" mit QR und der Adresse `…/station/#t=…`; rote Warnzeile sichtbar |
| QR-2 | Gerät vom Typ „TOTP-Standort" oder „Auth-Gerät" bearbeiten | Kein 📱 |
| QR-3 | „Neues Gerät", Typ „Virtuelle Station" wählen, nicht speichern | Kein 📱 — es gibt noch keinen Token |
| QR-4 | Station ohne gespeicherten Token, `station/#t=<gültig>` aufrufen | Ruhebild mit Uhr und Stations-Code; Adresszeile ohne `#` |
| QR-5 | Auf einer bereits gekoppelten Station den QR eines zweiten Kiosks scannen | Überschreibt still; das Ruhebild zeigt den zweiten Gerätenamen |
| QR-6 | `station/#t=abc123` aufrufen | Einrichtungs-Bildschirm mit „Token ungültig oder Gerät deaktiviert"; Adresszeile ohne `#`; der bisherige Token bleibt erhalten |
| QR-7 | Direkt nach QR-6 die Seite neu laden | Station ist wieder mit dem bisherigen Token verbunden, Ruhebild |
| QR-8 | Station offen lassen und **ohne Neuladen** die Adresse auf `station/#t=<gültig>` ändern | Die Seite lädt von selbst neu und übernimmt den Token |
| QR-9 | Nach einem Scan im Verlauf zurückgehen | Kein Eintrag mit Token in der Adresszeile |
| QR-10 | Ohne Neuladen erst QR-1, dann den PWA-Quicklink der Seitenleiste | Titel „📱 Check-In App öffnen", `checkin/`-Adresse, **keine** Warnzeile |
| QR-11 | Dashboard bei getrennter Internetverbindung (Server erreichbar) öffnen, QR-1 wiederholen | QR-Code wird weiterhin erzeugt |
| QR-12 | `station/#t=` (leerer Wert) aufrufen | Bestandsverhalten: gespeicherter Token wird geladen |
| QR-13 | Demo: Reset abwarten, als Admin den Kiosk öffnen, neu scannen | Station läuft nach einem Scan wieder |
| QR-14 | Neues Kiosk-Gerät anlegen und speichern | Das Modal öffnet sich sofort im Bearbeiten-Modus, 📱 ist ohne Zwischenschritt da und liefert einen QR mit dem neuen Token |
| QR-15 | **Nur mit echtem Tablet:** QR-1 aufrufen, mit der Kamera-App eines Tablets scannen, auf dem die Station bereits in einem Reiter offen ist | Der Bediener sieht die Kopplung **auf dem Gerät, das er vor Augen hat**. Öffnet der Browser stattdessen einen zweiten Reiter, koppelt sich dieser, während der sichtbare Reiter unverändert bleibt — dann greift OI-45 |
| QR-16 | Mitgliedsnummer oder PIN eintippen und **währenddessen** die Adresse auf `station/#t=<gültig>` ändern | Die Seite lädt neu, die Eingabe ist weg. Bewusst so — siehe „Bewusst entschieden" in `docs/OPEN-ITEMS.md` |
