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

> ### Nicht aus dem ZIP-Download aufsetzen
>
> **Der ZIP-Download enthält `private/demo/` nicht** — `.gitattributes` schließt den Ordner
> per `export-ignore` aus, und `seed.php` schlägt dann fehl, weil es die Datei gar nicht gibt.
>
> Das ist Absicht und wird nicht geändert: Ein Skript, das alle Fachtabellen leert,
> einschließlich `users`, hat im Installationspaket eines Vereins nichts zu suchen. Ein
> Verein, der es versehentlich ausführt, verliert seinen Bestand.
>
> **Der Wächter dagegen ist im Paket.** Gegen ein echtes `git archive` nachgemessen:
> `private/helpers/demo_mode.php` liegt bei, `private/demo/` und `docs/DEMO.md` nicht. Eine
> aus dem ZIP aufgesetzte Demo wäre also durchaus abgesichert — sie hätte nur keinen
> Datenbestand und würde sich nie zurücksetzen. Das ist die unangenehmere Sorte Fehler, weil
> sie erst nach einer Stunde auffällt.
>
> **Für eine Demo also aus dem Git-Klon arbeiten:**
>
> ```
> git clone https://github.com/mcmaier/EhrenSache.git
> ```
>
> Wer die Installation lieber aus dem ZIP macht, kopiert `private/demo/` anschließend von Hand
> aus einem Klon nach — drei Dateien plus README genügen. Dasselbe gilt bei jedem Update: Ein
> ZIP-Update überschreibt den Ordner nicht, es bringt ihn nur nicht mit.

1. **Eigene Datenbank und eigenen Datenbankbenutzer anlegen.** Der Benutzer darf **nur** auf
   die Demo-Datenbank berechtigt sein — er ist der einzige Ring um alles, was ein Besucher
   anrichten kann.
2. Installation wie üblich über `/install` durchführen. Danach existiert
   `private/config/install.lock`; ohne diese Datei bleibt der Assistent offen.
3. Die Subdomain muss auf `public/` zeigen, nicht auf das Projektwurzelverzeichnis.
4. In `private/config/config.php` den Schlüssel setzen:

   ```php
   'demo_mode' => true,
   ```

   Installationen, die vor 1.6.0 eingerichtet wurden, trugen stattdessen
   `define('DEMO_MODE', true);`. Der Update-Assistent übernimmt den Wert beim Umstellen auf
   1.6.0 mitsamt seinem Rohwert.

5. Für eine Station, die Besucher ohne Umweg erreichen, zusätzlich einen festen Token setzen
   (32 bis 64 Zeichen aus Buchstaben und Ziffern):

   ```php
   'demo_station_token' => '<php -r "echo bin2hex(random_bytes(24));">',
   ```

   Der Generator gibt ihn bei jedem Lauf der `Probenraum-Station`, statt einen neuen zu
   würfeln. Damit bleibt der Link `https://demo.…/station/#t=<token>` über den Reset hinweg
   gültig; die Werbeseite liest denselben Wert aus ihrer `config/demo.php`. Ohne den Schlüssel
   würfelt der Generator wie bisher. Ein ungültiger Wert bricht den Lauf mit Code 1 ab.
6. Einmal den Bestand herstellen:

   ```
   php private/demo/seed.php
   ```

   Ohne `--yes` nennt das Skript Datenbank, Präfix und die Zeilenzahl jeder Tabelle, die es
   leeren wird, und verlangt die Eingabe `LOESCHEN`. Beim ersten Mal lohnt sich dieser Blick.

### Der Schalter kennt nur zwei saubere Werte

| In `config.php` | Ergebnis |
|---|---|
| Schlüssel fehlt oder `'demo_mode' => null` | Wächter **aus** |
| `'demo_mode' => true` | Wächter **an** |
| `'demo_mode' => false` | Wächter **aus** |
| jeder andere Wert (`1`, `'true'`, `'false'`, `0`) | Wächter **an**, dazu eine Meldung im `error_log` |

Ein unsauberer Wert fällt absichtlich zur sicheren Seite: Ein Tippfehler lässt die öffentliche
Demo nicht stillschweigend ungeschützt.

### Zugänge

| Konto | Rolle |
|---|---|
| `admin@musterhausen.example` | Administrator |
| `manager@musterhausen.example` | Manager, Mitglied M013 |
| `user@musterhausen.example` | Mitglied M001, trägt die laufende Arbeitszeitsitzung |
| `user2@musterhausen.example` | Mitglied M002 |

Passwort für alle: `probelauf`, änderbar über `--password=`. `change_password` ist gesperrt —
ein Besucher kann die veröffentlichten Zugänge also nicht unbrauchbar machen.

**Station:** Mitglied **M001** trägt die feste PIN **4711** (`DEMO_PUBLIC_PIN` in
`private/demo/plan.php`), unabhängig von der Saat. M001 ist das Mitglied hinter `user@` — was
ein Besucher an der Station stempelt, sieht er in der Check-in-App wieder.

---

## 2. Einstellungen

### Was der Reset überschreibt — und was nicht

`system_settings` wird **nicht geleert**. Der Generator aktualisiert nur **zehn** Schlüssel:

```
organization_name, organization_logo, primary_color, secondary_color,
worktime_enabled, station_pin_enabled, station_pin_min_length, pagination_limit,
subgroup_label, holiday_region
```

`holiday_region` steht auf `BW`: Die Terminserien des Bestands lassen die Feiertage dieses
Landes aus, und der Kalender zeigt sie an. Ändern kann den Wert in der Demo niemand, der Wächter
sperrt die Systemeinstellungen.

Die übrigen (Stand 1.11: **25**) überleben jeden Reset unverändert. Das ist der Grund, warum die folgende
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
0 * * * * /usr/bin/php /pfad/zur/installation/private/demo/cron.php
```

`cron.php` ist gleichbedeutend mit `seed.php --yes --quiet`, braucht aber keine Argumente —
viele Aufgabenplaner im Shared Hosting nehmen nur einen Dateipfad an. Wo Argumente gehen,
funktioniert der direkte Aufruf genauso:

```
0 * * * * /usr/bin/php /pfad/zur/installation/private/demo/seed.php --yes --quiet
```

- **CLI-PHP aufrufen, nicht den Webserver.** `seed.php` bricht über HTTP mit 403 ab. Startet
  das Panel Skripte über **php-cgi**, bricht es dort ebenfalls ab — mit Rückgabewert 1 und
  „Nur über die Kommandozeile aufrufbar." in der Ausgabe. Dann im Panel die CLI-Variante
  wählen oder den Pfad zur CLI-Binärdatei ausdrücklich angeben.
- `--yes` überspringt die Rückfrage, `--quiet` zusammen mit `--yes` unterdrückt jede Ausgabe.
  Ohne `--quiet` gäbe es rund zwanzig Zeilen je Lauf, also stündlich eine Mail vom Cron.
- Rückgabewert 0 bei Erfolg, 1 bei einem Fehler; Fehler gehen auf STDERR. Das gilt auch für
  eine **nicht erreichbare Datenbank** — die Datenbankklasse aus `private/helpers/database.php` beendet dort
  ohne Rückgabewert, ein Wächter in `seed.php` macht daraus eine 1. Ihre JSON-Meldung
  erscheint trotzdem auf STDOUT.
- **`cron.php` und `seed.php` gehören zusammen hochgeladen.** Liegt neben einer neuen
  `cron.php` noch eine ältere `seed.php`, meldet der Cron das mit Rückgabewert 1, statt still
  ins Leere zu laufen.
- **Update auf 1.7.0:** `private/demo/cron.php` und `seed.php` gehören zu den Dateien, die
  ein ZIP-Update nicht mitbringt (siehe Abschnitt 1) — beim Aktualisieren der Demo also
  zusammen mit dem Rest von Hand ersetzen. Der Generator verlangt ab dieser Version
  Schemastand 1.7.0 (siehe `private/demo/README.md`, Abschnitt „Voraussetzung") und bricht
  auf einem älteren Stand laut mit Rückgabewert 1 ab, statt in eine fehlende Tabelle zu
  schreiben. Deshalb vor dem nächsten Cron-Lauf erst den Update-Assistenten unter `/update`
  ausführen, dann `cron.php`/`seed.php` ersetzen.
- Der Schreibvorgang liegt in einer Transaktion — ein Besucher mitten in einer Aktion sieht
  keinen halben Bestand.

**Der Takt muss stündlich sein.** Das Hinweisband sagt dem Besucher „stündlicher Reset", und
der Satz steht fest verdrahtet in `public/js/theme.js`, `public/checkin/js/app.js` und
`public/station/js/app.js`. Wer seltener zurücksetzt, lässt die Oberfläche lügen.

### Was der Reset mit sich bringt

- **Geräte-Token werden bei jedem Lauf neu gewürfelt** — außer dem der Kiosk-Station, wenn
  `demo_station_token` gesetzt ist (Abschnitt 1). Ohne diesen Schlüssel verliert ein Kiosk
  seine Verbindung spätestens nach einer Stunde; dann hilft nur ein Scan: als Admin anmelden,
  Geräte → die virtuelle Station bearbeiten → 📱, den QR-Code mit dem Tablet scannen. Das
  TOTP-Gerät bekommt immer einen neuen Token.
- Die PINs der Mitglieder werden ebenfalls neu gesetzt. Die öffentliche PIN von M001 (4711)
  bleibt immer gleich; die übrigen bleiben gleich, solange `--seed` festgehalten wird
  (`private/demo/README.md` zeigt, wie man sie ausliest).

---

## 4. Prüfliste vor dem Scharfschalten

- [ ] `/update/` und `/install/` liefern **403**. Beide tragen eine `.htaccess` mit
      `Require all denied`. Ist `AllowOverride` beim Hoster abgeschaltet, sind beide offen —
      und der Update-Assistent hat **keine eigene Anmeldung**.
- [ ] `private/config/install.lock` vorhanden.
- [ ] Datenbankbenutzer nur auf die Demo-Datenbank berechtigt.
- [ ] `mail_enabled` und `smtp_configured` stehen nicht auf `1`.
- [ ] `'demo_mode' => true` steht in `config.php`.
- [ ] Ein Aufruf von `?resource=cleanup` per POST liefert **403** mit `"demo":true`.
- [ ] Das orange Hinweisband erscheint auf Anmeldung, Dashboard, Check-in-PWA und Kiosk.
- [ ] Der Cron läuft und der Bestand erneuert sich zur vollen Stunde.

---

## 5. Was die Demo nicht schützt

Bewusst hingenommen, damit die Demo zeigen kann, wofür sie da ist:

- **Gespeichertes XSS zwischen zwei Resets.** Ein Besucher darf schreiben, also kann er in ein
  Freitextfeld schreiben, was bis zum nächsten Reset jeder weitere Besucher zu sehen bekommt.
  Seit OI-17 Etappe 2 tragen alle Oberflächen eine CSP: Eingeschleustes Skript läuft nicht,
  eingeschleustes Markup bleibt bis zum Reset sichtbar. Siehe OI-17 und OI-44 in
  `docs/OPEN-ITEMS.md`.
- **Die Kiosk-PIN.** `change_pin` ist gesperrt, aber dieselbe Wirkung ist über `members` `PUT`
  erreichbar — und Mitglieder darf ein Besucher bearbeiten. Der stündliche Reset ist die
  Gegenmaßnahme.
- **Einstiegspunkte neben `public/api/api.php`.** Der Wächter sitzt im API-Router und sieht
  `reset_password.php` und `verify_email.php` nicht. Beide brauchen einen Token aus einer
  Mail, und Mail ist gesperrt — aber wer einen weiteren öffentlichen Einstiegspunkt schafft,
  muss ihn eigens bedenken.

---

## 6. Drei Dinge, die überraschen

**Die Demo läuft seit 2026-10-06 ohne Cloudflare-Proxy („DNS only“).** Die Abschnitte unten
beschreiben, was hinter dem Proxy nötig war. Warum es ohne ihn besser geht: Der Hoster der Demo
spricht direkt nur HTTP/1.1. Der Browser öffnet dorthin höchstens sechs Verbindungen gleichzeitig,
die Grenze des Hosters von etwa zwölf gleichzeitigen Anfragen wird nie erreicht. Hinter Cloudflare
sprach der Browser HTTP/2, alle Dateien starteten in derselben Millisekunde. Fehlten sie im
Zwischenspeicher von Cloudflare, ging die ganze Welle von rund 50 Anfragen gleichzeitig an den
Hoster, und ab der zwölften kam `503`. Gemessen am 2026-10-06: über Cloudflare `h2`, direkt
`http/1.1` (`server: Apache`, kein `cf-ray`). Ausgelöst hatte das eine abgelaufene Edge TTL von
einem Tag: Am Morgen kamen 21 CSS-Dateien als `503`-Fehlerseite (`text/html`), dazu fünf
JS-Module und `appearance`.

Wer prüfen will, ob eine Installation direkt angesprochen wird: In den Entwicklerwerkzeugen
(Netzwerk) trägt die Antwort dann `server: Apache` und kein `cf-ray`. Nach der Umstellung hält der
Browser noch eine Weile die alte HTTP/2-Verbindung zu Cloudflare. Abhilfe: den Browser neu starten
oder unter `chrome://net-internals` den DNS-Cache leeren und die Socket-Pools leeren. Ohne Proxy ist
`trusted_proxies` in `config.php` wirkungslos und kann leer bleiben.

**Hinter Cloudflare bleibt nach einem Update altes CSS und JS im Browser.** EhrenSache liefert
CSS und JS mit `Cache-Control: no-cache, must-revalidate` aus (`public/.htaccess`), damit der
Browser nach jedem Update nachfragt. Cloudflare ersetzt das mit seiner Voreinstellung
„Browser Cache TTL“ durch `max-age=14400` — der Browser fragt dann vier Stunden lang nicht nach.
Die Seite selbst (`index.html`) kommt neu, die CSS-Module unter `css/` und die JS-Module unter
`js/modules/` nicht: Das Dashboard steht ungestylt da oder Knöpfe tun nichts. Der Versions-Query
`?v=` hilft nur der einen eingebundenen Datei, nicht den Modulen, die sie nachlädt.
Am 2026-10-02 nach dem Update der Demo auf 1.20.0 so beobachtet.

Abhilfe in Cloudflare, **die ersten beiden Einstellungen nur zusammen**:

1. *Caching → Configuration*: **Browser Cache TTL** auf **„Respect Existing Headers“**. Der
   Browser fragt dann vor jeder Verwendung nach.
2. *Caching → Cache Rules*: Regel für die Dateiendungen `css` und `js`, **Edge TTL „Ignore
   cache-control header and use this TTL“** (mehrere Wochen, etwa 1 Monat; ursprünglich 1 Tag,
   der jede Nacht ablief), Browser TTL „Respect origin“, Statuscodes 500–599 nicht
   zwischenspeichern. Damit beantwortet Cloudflare die Nachfragen selbst.

Nur Schritt 1 allein ist schlechter als der Ausgangszustand: Dann folgt auch Cloudflare dem
`no-cache` und reicht jede Nachfrage an den Server durch — ein Dashboard-Aufruf sind rund 45
gleichzeitige Anfragen. Der Hoster der Demo weist ab etwa der zwölften gleichzeitigen Anfrage mit
Apaches eigenem `503 Service Unavailable` ab; das Dashboard blieb leer, weil die JS-Module fehlten
(2026-10-02 gemessen). Mit der Regel aus Schritt 2 kommt je Datei und Tag nur eine Anfrage beim
Server an.

3. *Caching → Tiered Cache*: **Smart Tiered Caching** einschalten (kostenlos). Ohne diese
   Einstellung hat jedes Rechenzentrum von Cloudflare einen eigenen Zwischenspeicher, und jedes
   holt die Dateien selbst beim Server. Mit ihr fragt ein leerer Standort zuerst einen zentralen
   Knoten von Cloudflare.

**Die Edge TTL ist eine Obergrenze, keine Zusage.** Auf einer wenig besuchten Seite verdrängt
Cloudflare selten abgerufene Dateien lange vor Ablauf der TTL. Am 2026-10-05 gemessen: `main.css`,
`app.js` und `ui.js` waren eine Stunde nach einem Dashboard-Aufruf schon nicht mehr im
Zwischenspeicher, andere Dateien lagen dort seit über einer Stunde. Ein leerer Zwischenspeicher
heißt eine Welle von Anfragen an den Server, und das sah der Nutzer am Wochenende davor als
ungestylte Login-Seite, dann weiße Seite, kurz darauf richtig. Tiered Cache macht das seltener,
verhindern kann es das nicht. Dauerhaft hilft nur, weniger Dateien zu laden (OI-120 in
`docs/OPEN-ITEMS.md`).

Nach einem Update **„Purge Everything“**. Direkt danach ist der Zwischenspeicher bei Cloudflare
kalt, der erste Aufruf kann deshalb noch einzelne 503 sehen; ab dem zweiten liefert Cloudflare aus.
Prüfen im Browser (Entwicklerwerkzeuge → Netzwerk): `css/variables.css` trägt
`cache-control: no-cache, must-revalidate` **und** ab dem zweiten Abruf `cf-cache-status: HIT`.

Seit der Version im Pfad (OI-120) tragen CSS und JS von Dashboard und Anmeldeseite die Version in
der Adresse (`css/v<Version>/…`) und liegen ein Jahr (`immutable`). Ein Update erzeugt also neue
Adressen. Der „Purge Everything“ nach jedem Update bleibt trotzdem Pflicht: Ruft während des
Dateitauschs jemand auf, kann unter der neuen Adresse noch die alte Datei landen — und Cloudflare
hielte sie ein Jahr. Der Update-Assistent schreibt `public/index.html` und `public/login.html`
deshalb zuletzt; wer von Hand hochlädt, lädt diese beiden ebenfalls zuletzt hoch. Für die
HTML-Seiten, die Check-in-App und die Station gilt der Purge ohnehin. Die Cache-Regel (Edge TTL
für css/js) bleibt sinnvoll.

Dazu gehört in `config.php` der Schlüssel **`trusted_proxies`** mit den Adressbereichen von
Cloudflare (https://www.cloudflare.com/ips/). Ohne ihn sieht der Rate Limiter nur die Adressen von
Cloudflare und zählt alle Besucher, die über denselben Knoten kommen, gemeinsam.

**Die Testsuite läuft gegen eine Demo-Installation nicht.** Mit gesetztem `DEMO_MODE` werden
über hundert Prüfungen rot, weil die Suiten `settings` schreiben — und genau das sperrt der
Wächter. Das ist die korrekte Wirkung, sieht aber wie ein Regress aus. Tests gehören auf eine
Installation ohne `DEMO_MODE`.

**`config.php` liegt nicht im Repository.** Ein `git checkout` holt die Datei nicht zurück und
setzt auch keinen testweise eingeschalteten `demo_mode` zurück. Wer den Modus zum Ausprobieren
einschaltet, muss ihn von Hand wieder auf `false` stellen.
