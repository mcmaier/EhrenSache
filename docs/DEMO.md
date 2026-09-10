# Öffentliche Demo betreiben

Kurzanleitung für eine öffentlich erreichbare EhrenSache-Installation, in der jeder ohne
Registrierung mitarbeiten darf und die sich stündlich selbst zurücksetzt.

Zwei Teile greifen ineinander und sind unabhängig voneinander:

| Teil | Was er tut | Wo er sitzt |
|---|---|---|
| **Wächter** | begrenzt, was ein Besucher tun darf | `private/helpers/demo_mode.php`, eingeschaltet in `config.php` |
| **Generator** | stellt stündlich den Datenbestand her | `private/demo/seed.php`, aufgerufen per Cron |

Ohne Wächter könnte jeder Konten anlegen. Ohne Reset sähe die Demo nach einem Tag zerfahren
aus. Details zum Generator: `private/demo/README.md`.

---

## 1. Installation

1. **Eigene Datenbank und eigenen Datenbankbenutzer anlegen.** Der Benutzer darf **nur** auf
   die Demo-Datenbank berechtigt sein — er ist der einzige Ring um alles, was ein Besucher
   anrichten kann.
2. Installation wie üblich über `/install` durchführen. Danach existiert
   `private/config/install.lock`; ohne diese Datei bleibt der Assistent offen.
3. Die Subdomain muss auf `public/` zeigen, nicht auf das Projektwurzelverzeichnis.
4. In `private/config/config.php` ergänzen:

   ```php
   define('DEMO_MODE', true);
   ```

5. Einmal den Bestand herstellen:

   ```
   php private/demo/seed.php
   ```

   Ohne `--yes` nennt das Skript Datenbank, Präfix und die Zeilenzahl jeder Tabelle, die es
   leeren wird, und verlangt die Eingabe `LOESCHEN`. Beim ersten Mal lohnt sich dieser Blick.

### Der Schalter kennt nur zwei saubere Werte

| In `config.php` | Ergebnis |
|---|---|
| Zeile fehlt | Wächter **aus** |
| `define('DEMO_MODE', true);` | Wächter **an** |
| `define('DEMO_MODE', false);` | Wächter **aus** |
| jeder andere Wert (`1`, `'true'`, `'false'`, `0`) | Wächter **an**, dazu eine Meldung im `error_log` |

Ein unsauberer Wert fällt absichtlich zur sicheren Seite: Ein Tippfehler lässt die öffentliche
Demo nicht stillschweigend ungeschützt.

### Zugänge

| Konto | Rolle |
|---|---|
| `admin@musterhausen.example` | Administrator |
| `manager@musterhausen.example` | Manager |
| `user@musterhausen.example` | Mitglied M001, trägt die laufende Arbeitszeitsitzung |
| `user2@musterhausen.example` | Mitglied M002 |

Passwort für alle: `probelauf`, änderbar über `--password=`. `change_password` ist gesperrt —
ein Besucher kann die veröffentlichten Zugänge also nicht unbrauchbar machen.

---

## 2. Einstellungen

### Was der Reset überschreibt — und was nicht

`system_settings` wird **nicht geleert**. Der Generator aktualisiert nur **acht** Schlüssel:

```
organization_name, organization_logo, primary_color, secondary_color,
worktime_enabled, station_pin_enabled, station_pin_min_length, pagination_limit
```

Die übrigen **16** überleben jeden Reset unverändert. Das ist der Grund, warum die folgende
Liste wichtig ist: Was hier einmal falsch steht, bleibt falsch.

### Vor dem Scharfschalten prüfen

| Schlüssel | Sollwert | Warum |
|---|---|---|
| `mail_enabled` | **nicht `1`** | siehe unten |
| `smtp_configured` | **nicht `1`** | siehe unten |
| `privacy_policy_url` | eigene Adresse | zeigt sonst auf die Vorlage |
| `cleanup_years_*` | beliebig | wirkungslos, `cleanup` ist gesperrt |

**Zu Mail:** Der Reset räumt `mail_enabled` und `smtp_configured` **nicht** ab. Steht dort
einmal `1` — etwa weil die Installation aus einem Abzug einer echten Instanz stammt —,
überlebt der Wert jeden Reset.

Dass ein Besucher trotzdem keine Mail auslösen kann, liegt **allein** daran, dass `register`
und `password_reset_request` auf der Sperrliste des Wächters stehen. Die fehlende
Mail-Konfiguration ist **kein zweiter Riegel**. Wer den Sperreintrag lockert im Vertrauen
darauf, dass „ohne SMTP ja nichts passiert", öffnet damit unmittelbar den Versand an beliebige
Adressen.

Am einfachsten gar keine `private/config/mail_config.php` hinterlegen und beide Schlüssel
einmal in der Datenbank prüfen.

### Was ein Besucher tun darf

| erlaubt | gesperrt |
|---|---|
| Mitglieder, Termine, Anwesenheiten, Anträge | Konten, Rollen, Benutzerstatus |
| Arbeitszeiten und Tätigkeitsarten | Passwort und PIN ändern |
| Gruppen, Terminarten, Mitgliedschaftszeiträume | Systemeinstellungen, Logo-Upload |
| Check-in, TOTP-Check-in, Kiosk | Import, Mailversand, API-Token erzeugen |
| alles Lesen, einschließlich Export | Datenbereinigung (`cleanup`) |

Maßgeblich sind die drei Listen in `private/helpers/demo_mode.php`. **Eine Ressource, die dort
in keiner Liste steht, ist auch lesend gesperrt** — das ist Absicht, damit eine künftig neue
Ressource nicht unbemerkt offen steht.

---

## 3. Der Cronjob

```
0 * * * * /usr/bin/php /pfad/zur/installation/private/demo/seed.php --yes --quiet
```

- **CLI-PHP aufrufen, nicht den Webserver.** `seed.php` bricht über HTTP mit 403 ab.
- `--yes` überspringt die Rückfrage, `--quiet` zusammen mit `--yes` unterdrückt jede Ausgabe.
  Ohne `--quiet` gäbe es rund zwanzig Zeilen je Lauf, also stündlich eine Mail vom Cron.
- Fehler gehen weiterhin auf STDERR und bleiben sichtbar. Rückgabewert 0 bei Erfolg, 1 bei
  einem Fehler.
- Der Schreibvorgang liegt in einer Transaktion — ein Besucher mitten in einer Aktion sieht
  keinen halben Bestand.

**Der Takt muss stündlich sein.** Das Hinweisband sagt dem Besucher „stündlicher Reset", und
der Satz steht fest verdrahtet in `public/js/theme.js`, `public/checkin/js/app.js` und
`public/station/js/app.js`. Wer seltener zurücksetzt, lässt die Oberfläche lügen.

### Was der Reset mit sich bringt

- **Geräte-Token werden bei jedem Lauf neu gewürfelt.** Ein Kiosk oder ein TOTP-Gerät, das ein
  Besucher eingerichtet hat, verliert seine Verbindung spätestens nach einer Stunde und muss
  mit dem neuen Token aus der Geräteverwaltung neu verbunden werden. Für eine Demo ist das
  hinnehmbar; als Dauerbetrieb taugt es nicht.
- Die PINs der Mitglieder werden ebenfalls neu gesetzt. Wer eine PIN für die Kiosk-Vorführung
  veröffentlicht, muss sie nach jedem Reset neu ablesen — oder `--seed` festhalten, dann
  bleiben die Klartext-PINs gleich (`private/demo/README.md` zeigt, wie man sie ausliest).

---

## 4. Prüfliste vor dem Scharfschalten

- [ ] `/update/` und `/install/` liefern **403**. Beide tragen eine `.htaccess` mit
      `Require all denied`. Ist `AllowOverride` beim Hoster abgeschaltet, sind beide offen —
      und der Update-Assistent hat **keine eigene Anmeldung**.
- [ ] `private/config/install.lock` vorhanden.
- [ ] Datenbankbenutzer nur auf die Demo-Datenbank berechtigt.
- [ ] `mail_enabled` und `smtp_configured` stehen nicht auf `1`.
- [ ] `define('DEMO_MODE', true);` steht in `config.php`.
- [ ] Ein Aufruf von `?resource=cleanup` per POST liefert **403** mit `"demo":true`.
- [ ] Das orange Hinweisband erscheint auf Anmeldung, Dashboard, Check-in-PWA und Kiosk.
- [ ] Der Cron läuft und der Bestand erneuert sich zur vollen Stunde.

---

## 5. Was die Demo nicht schützt

Bewusst hingenommen, damit die Demo zeigen kann, wofür sie da ist:

- **Gespeichertes XSS zwischen zwei Resets.** Ein Besucher darf schreiben, also kann er in ein
  Freitextfeld schreiben, was bis zum nächsten Reset jeder weitere Besucher zu sehen bekommt.
  Die Oberfläche nutzt Inline-Handler und führt bewusst keine CSP. Siehe OI-17 und OI-44 in
  `docs/OPEN-ITEMS.md`.
- **Die Kiosk-PIN.** `change_pin` ist gesperrt, aber dieselbe Wirkung ist über `members` `PUT`
  erreichbar — und Mitglieder darf ein Besucher bearbeiten. Der stündliche Reset ist die
  Gegenmaßnahme.
- **Einstiegspunkte neben `public/api/api.php`.** Der Wächter sitzt im API-Router und sieht
  `reset_password.php` und `verify_email.php` nicht. Beide brauchen einen Token aus einer
  Mail, und Mail ist gesperrt — aber wer einen weiteren öffentlichen Einstiegspunkt schafft,
  muss ihn eigens bedenken.

---

## 6. Zwei Dinge, die überraschen

**Die Testsuite läuft gegen eine Demo-Installation nicht.** Mit gesetztem `DEMO_MODE` werden
über hundert Prüfungen rot, weil die Suiten `settings` schreiben — und genau das sperrt der
Wächter. Das ist die korrekte Wirkung, sieht aber wie ein Regress aus. Tests gehören auf eine
Installation ohne `DEMO_MODE`.

**`config.php` liegt nicht im Repository.** Ein `git checkout` holt die Datei nicht zurück und
entfernt auch keine testweise eingetragene `DEMO_MODE`-Zeile. Wer den Modus zum Ausprobieren
einschaltet, muss die Zeile von Hand wieder löschen.
