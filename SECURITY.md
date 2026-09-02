# Sicherheitsrichtlinie

EhrenSache verwaltet personenbezogene Daten von Vereinsmitgliedern. Meldungen zu
Sicherheitslücken werden deshalb ernst genommen und bevorzugt behandelt.

## Unterstützte Versionen

| Version | Sicherheitsupdates |
|---------|--------------------|
| 1.2.x   | ja                 |
| 1.1.x   | nein — bitte über den Update-Wizard auf 1.2.x aktualisieren |
| < 1.1   | nein               |

Die installierte Version steht in `version.json` und wird im Dashboard angezeigt.

## Eine Lücke melden

**Bitte kein öffentliches Issue eröffnen.**

Nutze stattdessen die private Meldefunktion von GitHub:
**Security → Report a vulnerability** im Repository. Die Meldung ist nur für die
Maintainer sichtbar.

Hilfreich in der Meldung:

- betroffene Version und Installationsart (eigener Server, Shared Hosting)
- Rolle, die für den Angriff nötig ist (anonym, `user`, `manager`, `admin`, `device`)
- Schritte zur Reproduktion
- Auswirkung: Welche Daten sind lesbar oder veränderbar?

## Ablauf

| Schritt | Zeitrahmen |
|---|---|
| Eingangsbestätigung | innerhalb von 7 Tagen |
| Erste Einschätzung mit Schweregrad | innerhalb von 14 Tagen |
| Fix und Veröffentlichung | nach Schweregrad, kritische Funde vorrangig |

Nach der Behebung wird ein GitHub Security Advisory veröffentlicht und der Fund im
`CHANGELOG.md` vermerkt. Auf Wunsch mit Namensnennung des Melders, sonst anonym.

## Umgang mit bekannten Schwachstellen

Das Projekt dokumentiert offene Punkte öffentlich in `docs/OPEN-ITEMS.md` — auch
sicherheitsrelevante. Dabei gilt eine Grenze:

- **Öffentlich dokumentiert** werden Schwächen, die entweder bereits privilegierten Zugang
  voraussetzen, aus dem veröffentlichten Quellcode ohnehin ablesbar sind oder eine bewusst
  getroffene Abwägung darstellen. Beispiel: das im Klartext gespeicherte TOTP-Secret
  (`OI-6`), das nur von Konten mit Administrator- oder Managerrechten ausgelesen werden kann.
- **Nicht öffentlich** behandelt werden ungepatchte Lücken, die ohne vorherigen Zugang
  ausnutzbar sind oder eine Rechteausweitung erlauben. Diese laufen als privates Advisory
  und erscheinen erst mit dem Fix in der öffentlichen Dokumentation.

Der Quellcode steht unter AGPL-3.0 und ist vollständig einsehbar. Sicherheit durch
Verschweigen ist damit ohnehin keine Option — Transparenz über bekannte Grenzen ist die
ehrlichere Antwort.

## Betrieb absichern

Siehe Abschnitt „Sicherheitshinweise" in `README.md` und `DATENSCHUTZ.md` für die
datenschutzrechtlichen Pflichten des Betreibers.
