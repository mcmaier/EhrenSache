# EhrenSache API-Dokumentation

>[!NOTE]
> Dokumentation noch in Arbeit

## Übersicht

Die EhrenSache REST API ermöglicht die Verwaltung von Mitgliedern, Terminen, Anwesenheitserfassung und Ausnahmen für ehrenamtliche Organisationen.

**Base URL:** `https://meine-domain.de/api/api.php`

## Authentifizierung

Die API unterstützt zwei Authentifizierungsmethoden:

### Session-basiert (Web-Dashboard)
Nach erfolgreicher Anmeldung wird ein Session-Cookie gesetzt. Alle nachfolgenden Requests nutzen diesen Cookie automatisch.

### Token-basiert (IoT-Geräte, PWA)
Für API-Token wird der Token im Header übergeben:

```http
Authorization: Bearer YOUR_API_TOKEN
```

Alternative Header-Varianten:
```http
X-API-Key: YOUR_API_TOKEN
```

Query-Parameter (nicht empfohlen):
```
?api_token=YOUR_API_TOKEN
```

### CSRF-Schutz

Bei Session-basierter Authentifizierung ist ein CSRF-Token erforderlich:
- **POST/PUT:** Im Request-Body als `csrf_token`
- **DELETE:** Als Query-Parameter `?csrf_token=XXX`
- **Ausnahmen:** `login`, `logout`, `auth`, `register`, `regenerate_token`

## Rollen & Berechtigungen

| Rolle | Beschreibung |
|-------|--------------|
| `admin` | Volle Systemrechte, Konfiguration, Benutzerverwaltung |
| `manager` | Verwaltung von Mitgliedern, Terminen, Anwesenheit |
| `user` | Eigene Anwesenheit einsehen, Check-in durchführen |

## Rate Limiting

- **100 Requests pro Minute** pro IP/User-Kombination
- Bei Überschreitung: HTTP 429 mit `retry_after` in Sekunden

## HTTP Status Codes

| Code | Bedeutung |
|------|-----------|
| 200 | Erfolg |
| 201 | Ressource erstellt |
| 400 | Ungültige Anfrage |
| 401 | Nicht authentifiziert |
| 403 | Keine Berechtigung / CSRF-Fehler |
| 404 | Ressource nicht gefunden |
| 409 | Konflikt (z.B. Duplikat) |
| 429 | Rate Limit überschritten |
| 500 | Serverfehler |
| 503 | Service nicht verfügbar |

## Zeit & Datum

Einheitliches Datumsformat für Endpoints: YYYY-MM-DD HH:MM:SS

---

## Öffentliche Endpoints

### System Status
Prüft ob das System installiert und betriebsbereit ist.

**Endpoint:** `GET /api.php?resource=ping`

**Authentifizierung:** Keine

**Response:**
```json
{
  "status": "ok",
  "message": "System ready",
  "version": "1.0"
}
```

**Fehler-Status:**
```json
{
  "status": "not_installed",
  "message": "Installation required"
}
```

---

### Appearance
Lädt Branding-Einstellungen (Logo, Farben, Name).

**Endpoint:** `GET /api.php?resource=appearance`

**Authentifizierung:** Keine

**Response:**
```json
{
  "org_name": "Mein Verein",
  "privacy_policy_url": "https://meine-domain.de/datenschutzerklaerung/"
  "primary_color": "#667eea",
  "secondary_color": "#764ba2",
  "logo_url": "/uploads/logos/logo.png"
}
```

---

### Login (Web)
Session-basierte Anmeldung für das Web-Dashboard.

**Endpoint:** `POST /api.php?resource=login`

**Authentifizierung:** Keine

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "user_id": 1,
  "role": "admin",
  "email": "user@example.com",
  "csrf_token": "abc123..."
}
```

**Fehler:**
```json
{
  "success": false,
  "message": "Invalid credentials"
}
```

---

### Login (Token)
Token-basierte Anmeldung für PWA/IoT-Geräte.

**Endpoint:** `POST /api.php?resource=auth`

**Authentifizierung:** Keine

**Request:**
```json
{
  "email": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "token": "abc123...",
  "user_id": 1,
  "role": "user",
  "member_id": 5
}
```

---

### Logout
Beendet Session.

**Endpoint:** `POST /api.php?resource=logout`

**Authentifizierung:** Session

**Response:**
```json
{
  "success": true,
  "message": "Logged out"
}
```

---

### Registrierung
Erstellt neuen Benutzer-Account (falls aktiviert).

**Endpoint:** `POST /api.php?resource=register`

**Authentifizierung:** Keine

**Request:**
```json
{
  "email": "new@example.com",
  "password": "SecurePass123!"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Registration successful",
  "user_id": 42
}
```

---

### Passwort-Reset Anfrage
Sendet Reset-Link per E-Mail.

**Endpoint:** `POST /api.php?resource=password_reset_request`

**Request:**
```json
{
  "email": "user@example.com"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Reset link sent"
}
```

---

## Geschützte Endpoints

### Benutzer-Info
Gibt Informationen über den aktuell angemeldeten Benutzer zurück.

**Endpoint:** `GET /api.php?resource=me`

**Authentifizierung:** Session oder Token

**Response:**
```json
{
  "user_id": 1,
  "email": "admin@example.com",
  "role": "admin",
  "member_id": 5,
  "auth_type": "session"
}
```

---

## Mitglieder (members)

### Alle Mitglieder abrufen
**Endpoint:** `GET /api.php?resource=members`

**Query-Parameter:**
- `id` (optional): Einzelnes Mitglied abrufen
- `group_id` (optional): Mitglieder einer Gruppe abrufen

**Response:**
```json
{
  "members": [
    {
      "member_id": 1,
      "name": "Max",
      "surname": "Mustermann",
      "member_number": "M001",
      "active": 1,
      "groups": [
        {
          "group_id": 1,
          "group_name": "Trompeten"
        }
      ],
      "membership_dates": [
        {
          "membership_date_id": 1,
          "start_date": "2020-01-01",
          "end_date": null,
          "status": "active"
        }
      ]
    }
  ],
  "pagination": {
    "total": 150,
    "page": 1,
    "per_page": 50,
    "total_pages": 3
  }
}
```

**Einzelnes Mitglied:**
```
GET /api.php?resource=members&id=1
```

---

### Mitglied erstellen
**Endpoint:** `POST /api.php?resource=members`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "name": "Max",
  "surname": "Mustermann",
  "member_number": "M001",
  "active": 1,
  "group_ids": [1, 3],
  "membership_dates": [
    {
      "start_date": "2024-01-01",
      "end_date": null,
      "status": "active"
    }
  ]
}
```

**Response:**
```json
{
  "message": "Member created",
  "id": 42
}
```

---

### Mitglied aktualisieren
**Endpoint:** `PUT /api.php?resource=members&id=1`

**Berechtigung:** Admin/Manager

**Request:** Wie bei POST

**Response:**
```json
{
  "message": "Member updated"
}
```

---

### Mitglied löschen
**Endpoint:** `DELETE /api.php?resource=members&id=1`

**Berechtigung:** Admin/Manager

**Response:**
```json
{
  "message": "Member deleted"
}
```

---

## Termine (appointments)

### Alle Termine abrufen
**Endpoint:** `GET /api.php?resource=appointments`

**Query-Parameter:**
- `id`: Einzelner Termin
- `year`: Filter nach Jahr
- `month`: Filter nach Monat (Auch in Kombination mit Jahr)
- `from_date`: Filter nach Termine ab Zeitpunkt
- `to_date`: Filter nach Termine bis Zeitpunkt

**Response:**
```json
[
  {
    "appointment_id": 1,
    "title": "Probe",
    "appointment_date": "2024-03-15",
    "start_time": "19:00:00",
    "end_time": "21:00:00",
    "location": "Proberaum",
    "type_id": 1,
    "type_name": "Probe",
    "type_color": "#667eea",
    "groups": [
      {
        "group_id": 1,
        "group_name": "Trompeten"
      }
    ]
  }
]
```

---

### Termin erstellen
**Endpoint:** `POST /api.php?resource=appointments`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "title": "Konzert",
  "appointment_date": "2024-06-15",
  "start_time": "19:00",
  "end_time": "21:30",
  "location": "Stadthalle",
  "type_id": 1,
  "group_ids": [1, 2]
}
```

**Response:**
```json
{
  "message": "Appointment created",
  "id": 42
}
```

---

### Termin aktualisieren
**Endpoint:** `PUT /api.php?resource=appointments&id=1`

**Berechtigung:** Admin/Manager

---

### Termin löschen
**Endpoint:** `DELETE /api.php?resource=appointments&id=1`

**Berechtigung:** Admin/Manager

---

## Anwesenheit (records)

### Anwesenheitseinträge abrufen
**Endpoint:** `GET /api.php?resource=records`

**Query-Parameter:**
- `appointment_id`: Alle Einträge eines Termins
- `member_id`: Alle Einträge eines Mitglieds
- `year`: Filter nach Jahr
- `month`: Filter nach Monat (auch in Kombination mit Jahr)
- `from_date`: Filter nach Einträge ab Datum
- `to_date`: Filter nach Einträge bis Datum
- `status`: Filter nach Status (Anwesend / Entschuldigt)
- `appointment_type_id`: Filter nach Termin-Arten

**Response:**
```json
[
  {
    "record_id": 1,
    "member_id": 5,
    "appointment_id": 10,
    "arrival_time": "2024-03-15 19:05:00",
    "status": "present",
    "member_name": "Max Mustermann",
    "appointment_title": "Probe"
  }
]
```

---

### Anwesenheit erfassen (manuell)
**Endpoint:** `POST /api.php?resource=records`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "member_id": 5,
  "appointment_id": 10,
  "arrival_time": "2024-03-15 19:05:00",
  "status": "present"
}
```

**Status-Werte:**
- `present`: Anwesend
- `late`: Verspätet
- `absent`: Abwesend

---

### Anwesenheit aktualisieren
**Endpoint:** `PUT /api.php?resource=records&id=1`

**Berechtigung:** Admin/Manager

---

### Anwesenheit löschen
**Endpoint:** `DELETE /api.php?resource=records&id=1`

**Berechtigung:** Admin/Manager

---

## Auto Check-In

### Automatischer Check-In
Geräte-Endpunkt (source_device).

**Source-Werte:**
- `user_totp`: QR-Code Scan via PWA
- `device_auth`: Authentifizierungs-Gerät
- `auto_checkin`: Anderes IoT-Gerät

Erfasst Anwesenheit basierend auf aktueller Zeit und Terminzuordnung. 
Sucht passenden Termin im Zeitfenster. Kann automatisch einen neuen Termin anlegen, wenn aktiviert.

**Endpoint:** `POST /api.php?resource=auto_checkin`

**Authentifizierung:** Token empfohlen

**Request:**
```json
{
  "member_id": 5 ODER "member_number": "M123",
  "arrival_time": "2024-03-15 19:05:32",
  "source_device": "device_auth"
}
```

**Response (Erfolg):**
```json
{
  "success": true,
  "message": "Check-in erfolgreich",
  "record_id": 42,
  "appointment_title": "Probe",
  "arrival_time": "2024-03-15 19:05:32"
}
```

**Fehler (kein Termin):**
```json
{
  "success": false,
  "message": "Kein passender Termin gefunden"
}
```

---

## TOTP Check-In

### Check-In mit TOTP-Code
Standortverifizierter Check-In mit zeitbasiertem Einmalpasswort.
Wird automatisch für Authorisierten User durchgeführt (z.B. User über PWA).

**Endpoint:** `POST /api.php?resource=totp_checkin`

**Authentifizierung:** Token empfohlen

**Request:**
```json
{
  "totp_code": "123456",
  "arrival_time": "2024-03-15 19:05:32",
  "source_device": "user_totp"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Check-in erfolgreich",
  "record_id": 42,
  "verified": true
}
```

**Fehler (ungültiger Code):**
```json
{
  "success": false,
  "message": "Ungültiger TOTP-Code"
}
```

---

## Ausnahmen (exceptions)

### Ausnahmen abrufen
**Endpoint:** `GET /api.php?resource=exceptions`

**Query-Parameter:**
- `member_id`: Filter nach Mitglied
- `status`: `pending`, `approved`, `rejected`

**Response:**
```json
[
  {
    "exception_id": 1,
    "member_id": 5,
    "exception_date": "2024-03-20",
    "type": "excused",
    "reason": "Krankheit",
    "status": "pending",
    "created_at": "2024-03-19 10:00:00"
  }
]
```

---

### Ausnahme beantragen
**Endpoint:** `POST /api.php?resource=exceptions`

**Request:**
```json
{
  "member_id": 5,
  "exception_date": "2024-03-20",
  "type": "excused",
  "reason": "Krankheit"
}
```

**Exception-Typen:**
- `excused`: Entschuldigt
- `vacation`: Urlaub
- `correction`: Zeitkorrektur

---

### Ausnahme genehmigen/ablehnen
**Endpoint:** `PUT /api.php?resource=exceptions&id=1`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "status": "approved"
}
```

---

## Mitgliedergruppen (member_groups)

### Gruppen abrufen
**Endpoint:** `GET /api.php?resource=member_groups`

**Response:**
```json
[
  {
    "group_id": 1,
    "group_name": "Trompeten",
    "description": "Trompetenregister",
    "is_default": 0
  }
]
```

---

### Gruppe erstellen
**Endpoint:** `POST /api.php?resource=member_groups`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "group_name": "Posaunen",
  "description": "Posaunenregister",
  "is_default": false
}
```

---

### Gruppe aktualisieren
**Endpoint:** `PUT /api.php?resource=member_groups&id=1`

---

### Gruppe löschen
**Endpoint:** `DELETE /api.php?resource=member_groups&id=1`

---

## Terminarten (appointment_types)

### Terminarten abrufen
**Endpoint:** `GET /api.php?resource=appointment_types`

**Response:**
```json
[
  {
    "type_id": 1,
    "type_name": "Probe",
    "description": "Wöchentliche Probe",
    "color": "#667eea",
    "is_default": 1,
    "groups": [
      {
        "group_id": 1,
        "group_name": "Trompeten"
      }
    ]
  }
]
```

---

### Terminart erstellen
**Endpoint:** `POST /api.php?resource=appointment_types`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "type_name": "Konzert",
  "description": "Öffentliches Konzert",
  "color": "#f39c12",
  "is_default": false,
  "group_ids": [1, 2]
}
```

---

## Statistiken (statistics)

### Statistik abrufen
**Endpoint:** `GET /api.php?resource=statistics`

**Query-Parameter:**
- `member_id`: Statistik eines Mitglieds (User nur eigene)
- `year`: Jahr (Standard: aktuelles)

**Response:**
```json
{
  "total_appointments": 48,
  "attended": 42,
  "absent": 3,
  "excused": 3,
  "attendance_rate": 87.5,
  "late_count": 5,
  "avg_arrival_minutes": -3.2,
  "monthly_stats": [
    {
      "month": "2024-01",
      "appointments": 4,
      "attended": 4
    }
  ]
}
```

---

## Benutzerverwaltung (users)

### Benutzer abrufen
**Endpoint:** `GET /api.php?resource=users`

**Berechtigung:** Admin

**Response:**
```json
[
  {
    "user_id": 1,
    "email": "admin@example.com",
    "role": "admin",
    "member_id": 5,
    "is_active": 1,
    "created_at": "2024-01-01 12:00:00",
    "api_token_expires_at": "2025-01-01 00:00:00"
  }
]
```

---

### Benutzer erstellen
**Endpoint:** `POST /api.php?resource=users`

**Berechtigung:** Admin

**Request:**
```json
{
  "action": "create",
  "email": "new@example.com",
  "password": "SecurePass123!",
  "role": "user",
  "member_id": 10
}
```

---

### Benutzer aktivieren/deaktivieren
**Endpoint:** `POST /api.php?resource=activate_user`

**Berechtigung:** Admin

**Request:**
```json
{
  "user_id": 5,
  "is_active": 1
}
```

---

### Benutzerstatus aktualisieren
**Endpoint:** `POST /api.php?resource=user_status`

**Berechtigung:** Admin

**Request:**
```json
{
  "user_id": 5,
  "role": "manager",
  "member_id": 10
}
```

---

## Token-Verwaltung

### API-Token neu generieren
**Endpoint:** `POST /api.php?resource=regenerate_token`

**Berechtigung:** Admin/Manager

**Response:**
```json
{
  "success": true,
  "token": "new_generated_token_here",
  "expires_at": "2025-12-31 23:59:59"
}
```

---

### Passwort ändern
**Endpoint:** `POST /api.php?resource=change_password`

Doppelte Eingabeprüfung erfolgt in HTML.

**Request:**
```json
{
  "current_password": "oldPass123",
  "new_password": "newSecurePass456!",
}
```

---

## Import/Export

### Export
Exportiert Daten als Excel-Datei.

**Endpoint:** `GET /api.php?resource=export`

**Berechtigung:** Admin/Manager

**Query-Parameter:**
- `type`: `members`, `appointments`, `records`
- `year`: Jahr (für records)

**Response:** CSV-Datei

---

### Import
Importiert Daten aus Excel-Datei.

**Endpoint:** `POST /api.php?resource=import`

**Berechtigung:** Admin/Manager

**Content-Type:** `multipart/form-data`

**Form-Data:**
- `file`: CSV-Datei
- `type`: `members`, `appointments`
- `csrf_token`: CSRF-Token

**Response:**
```json
{
  "success": true,
  "imported": 25,
  "errors": []
}
```

---

## Systemeinstellungen (settings)

### Einstellungen abrufen
**Endpoint:** `GET /api.php?resource=settings`

**Berechtigung:** Admin

**Response:**
```json
{
  "settings": [
    {
      "setting_key": "org_name",
      "setting_value": "Mein Verein"
    }
  ]
}
```

---

### Einstellung aktualisieren
**Endpoint:** `PUT /api.php?resource=settings`

**Berechtigung:** Admin

**Request:**
```json
{
  "setting_key": "org_name",
  "setting_value": "Neuer Vereinsname"
}
```

---

### SMTP-Konfiguration
**Endpoint:** `POST /api.php?resource=settings`

**Berechtigung:** Admin

**Request (Konfiguration speichern):**
```json
{
  "action": "save_smtp_config",
  "config": {
    "smtp_host": "mail.example.com",
    "smtp_port": 587,
    "smtp_encryption": "tls",
    "smtp_username": "noreply@example.com",
    "smtp_password": "password123",
    "smtp_from_email": "noreply@example.com",
    "smtp_from_name": "Vereinsname"
  }
}
```

**Request (Test-Mail senden):**
```json
{
  "action": "test_mail",
  "recipient": "test@example.com"
}
```

---

## Logo-Upload

### Logo hochladen
**Endpoint:** `POST /api.php?resource=upload-logo`

**Berechtigung:** Admin

**Content-Type:** `multipart/form-data`

**Form-Data:**
- `logo`: Bild-Datei (JPG, PNG, max 2MB)
- `csrf_token`: CSRF-Token

**Response:**
```json
{
  "success": true,
  "logo_url": "/uploads/logo_1234567890.png"
}
```

---

## Anwesenheitsliste

### Anwesenheitsliste für Termin
**Endpoint:** `GET /api.php?resource=attendance_list&appointment_id=10`

**Response:**
```json
{
  "appointment": {
    "appointment_id": 10,
    "title": "Probe",
    "appointment_date": "2024-03-15",
    "start_time": "19:00:00"
  },
  "attendance": [
    {
      "member_id": 5,
      "member_name": "Max Mustermann",
      "group_name": "Trompeten",
      "status": "present",
      "arrival_time": "2024-03-15 19:05:00"
    },
    ...
  ]
}
```

### Anwesenheitsliste für Mitglied
**Endpoint:** `GET /api.php?resource=attendance_list&member_id=10`

**Query-Parameter:** 
- `year`: Jahr (Standard: Aktuelles Jahr)

**Response:**
```json
{
  "member_id": {
      "member_id": 5,
      "member_name": "Max Mustermann",
      "group_name": "Trompeten",
  },
  "attendance": [
    {
      "appointment_id": 10,
      "title": "Probe",
      "appointment_date": "2024-03-15",
      "start_time": "19:00:00",
      "status": "present",
      "arrival_time": "2024-03-15 19:05:00"
    },
    ...
  ]
}
```

---

## Verfügbare Jahre

### Jahre mit Daten abrufen
**Endpoint:** `GET /api.php?resource=available_years`

**Response:**
```json
{
  "years": [2022, 2023, 2024, 2025]
}
```

---

## Mitgliedschaftszeiträume (membership_dates)

### Zeiträume abrufen
**Endpoint:** `GET /api.php?resource=membership_dates`

**Query-Parameter:**
- `member_id`: Filter nach Mitglied

**Response:**
```json
[
  {
    "membership_date_id": 1,
    "member_id": 5,
    "start_date": "2020-01-01",
    "end_date": null,
    "status": "active"
  }
]
```

---

### Zeitraum erstellen
**Endpoint:** `POST /api.php?resource=membership_dates`

**Berechtigung:** Admin/Manager

**Request:**
```json
{
  "member_id": 5,
  "start_date": "2024-01-01",
  "end_date": null,
  "status": "active"
}
```

---

### Zeitraum aktualisieren
**Endpoint:** `PUT /api.php?resource=membership_dates&id=1`

---

### Zeitraum löschen
**Endpoint:** `DELETE /api.php?resource=membership_dates&id=1`

---

## Fehlerbehandlung

### Typische Fehler-Responses

**Authentifizierung fehlgeschlagen:**
```json
{
  "message": "Unauthorized"
}
```

**Ungültiger CSRF-Token:**
```json
{
  "message": "Invalid CSRF token"
}
```

**Fehlende Berechtigung:**
```json
{
  "message": "Admin access required"
}
```

**Rate Limit überschritten:**
```json
{
  "message": "Rate limit exceeded",
  "retry_after": 60
}
```

**Ressource nicht gefunden:**
```json
{
  "message": "Endpoint not found"
}
```

---

## Best Practices

### Sicherheit
1. **HTTPS verwenden** in Produktion
2. **API-Tokens sicher aufbewahren** (Umgebungsvariablen)
3. **Token-Ablaufdatum prüfen** vor Verwendung
4. **Rate Limits beachten** bei automatisierten Anfragen

### Performance
1. **Pagination** Erfolgt Client-Seitig
2. **Caching** für statische Daten auf Jahresbasis
3. **Jahr-Filter verwenden** bei Anwesenheitsabfragen
4. **Batch-Operationen** statt Einzelaufrufen

### Fehlerbehandlung
1. **HTTP Status Codes auswerten**
2. **Retry-Logic** bei 429/500 Fehlern
3. **Logging** von API-Fehlern implementieren
4. **Timeout-Handling** (empfohlen: 30s)

---

## Beispiel-Implementierungen

### JavaScript (Fetch)
```javascript
const API_BASE = 'https://your-domain.com/api/api.php';
const API_TOKEN = 'your_api_token_here';

async function getMembers() {
  const response = await fetch(`${API_BASE}?resource=members`, {
    method: 'GET',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json'
    }
  });
  
  if (!response.ok) {
    throw new Error(`HTTP ${response.status}`);
  }
  
  return await response.json();
}

async function checkIn(memberId, appointmentId, totpCode) {
  const response = await fetch(`${API_BASE}?resource=totp_checkin`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({
      member_id: memberId,
      appointment_id: appointmentId,
      totp_code: totpCode,
      source: 'web'
    })
  });
  
  return await response.json();
}
```

---

### Python
```python
import requests

API_BASE = 'https://your-domain.com/api/api.php'
API_TOKEN = 'your_api_token_here'

headers = {
    'Authorization': f'Bearer {API_TOKEN}',
    'Content-Type': 'application/json'
}

def get_members():
    response = requests.get(
        f'{API_BASE}?resource=members',
        headers=headers
    )
    response.raise_for_status()
    return response.json()

def check_in(member_id, appointment_id, totp_code):
    data = {
        'member_id': member_id,
        'appointment_id': appointment_id,
        'totp_code': totp_code,
        'source': 'iot'
    }
    
    response = requests.post(
        f'{API_BASE}?resource=totp_checkin',
        headers=headers,
        json=data
    )
    
    return response.json()
```

---

### Arduino/ESP32
```cpp
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

const char* API_BASE = "https://your-domain.com/api/api.php";
const char* API_TOKEN = "your_api_token_here";

bool checkIn(int memberId, int appointmentId, String totpCode) {
  HTTPClient http;
  
  String url = String(API_BASE) + "?resource=totp_checkin";
  http.begin(url);
  
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Authorization", "Bearer " + String(API_TOKEN));
  
  StaticJsonDocument<200> doc;
  doc["member_id"] = memberId;
  doc["appointment_id"] = appointmentId;
  doc["totp_code"] = totpCode;
  doc["source"] = "nfc";
  
  String jsonData;
  serializeJson(doc, jsonData);
  
  int httpCode = http.POST(jsonData);
  
  if (httpCode == 200) {
    String payload = http.getString();
    DynamicJsonDocument response(1024);
    deserializeJson(response, payload);
    
    return response["success"];
  }
  
  http.end();
  return false;
}
```

---

## Support & Lizenz

**GitHub:** https://github.com/mcmaier/EhrenSache

**Lizenz:** AGPL-3.0 (gemeinnützig) / Kommerzielle Lizenz verfügbar

**Support:** Issues auf GitHub erstellen
