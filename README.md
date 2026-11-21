# FM-WPCalendar

Einfache Terminverwaltung für Vereine als WordPress-Plugin – von [Frozen-Media](https://www.frozen-media.de).

- Termine mit Titel, Beschreibung, Datum, optionaler Uhrzeit (von/bis) und optionalem Ort
- Ganztägige und mehrtägige Termine
- Orte mit Name und optionaler Adresse – direkt im Termin auswählbar oder neu anlegbar
- Shortcode für Terminliste („Mehr anzeigen“ lädt nach), Monatskalender (blätterbar) oder kompakte Liste
- Detailseite je Termin mit Zurück-Link
- Sidebar-Widget „Nächste Termine“
- Import/Export als CSV oder Excel (XLSX), ohne externe Bibliotheken

**Voraussetzungen:** WordPress ab 6.4, PHP ab 8.2

## Installation

**Per ZIP:** Unter *Code → Download ZIP* herunterladen, den enthaltenen Ordner in `fm-wpcalendar` umbenennen, wieder zippen und unter *Plugins → Installieren → Plugin hochladen* einspielen.

**Per Git (auf dem Server):**

```bash
cd wp-content/plugins
git clone https://github.com/frozen-media-tz/FM-WPCalendar.git fm-wpcalendar
```

Danach das Plugin aktivieren.

## Einrichtung

1. Unter **Termine** Termine anlegen.
2. Eine Seite „Termine“ anlegen und einen Shortcode einfügen.
3. Unter **Termine → Einstellungen** diese Seite als „Terminseite“ wählen (für Zurück-Link und Widget).
4. Unter **Design → Widgets** das Widget „Nächste Termine“ in die Seitenleiste ziehen.

## Shortcodes

| Shortcode | Ausgabe |
|---|---|
| `[vereinstermine]` | Terminliste, 10 Einträge, nächster Termin hervorgehoben, „Mehr anzeigen“ |
| `[vereinstermine anzahl="5"]` | 5 Einträge je Ladevorgang (`mehr="nein"` blendet den Button aus) |
| `[vereinstermine ansicht="monat"]` | Monatskalender ab aktuellem Monat, vor-/zurückblätterbar |
| `[vereinstermine ansicht="kompakt" anzahl="5"]` | Kompakte Liste wie im Widget |

Alle Varianten funktionieren auch als `[fm_wpcalendar …]`.

## Import/Export

Unter **Termine → Import / Export**. Spalten: `titel`, `beschreibung`, `start_datum`, `end_datum`, `ganztaegig`, `start_zeit`, `end_zeit`, `ort`, `ort_adresse`. Trennzeichen und Zeichensatz werden automatisch erkannt; eine Vorlage lässt sich dort herunterladen.

## Releases (automatisch)

Bei jedem Push auf `main` baut GitHub Actions eine fertige Plugin-ZIP (`fm-wpcalendar-X.Y.Z.zip`) und hängt sie unter **Releases** an – direkt in WordPress hochladbar.

- **Gleiche Version:** Das bestehende Release `vX.Y.Z` wird mit dem neuen Stand aktualisiert.
- **Neue Version:** Version im Plugin-Header von `fm-wpcalendar.php` erhöhen (am besten auch `FMVT_VERSION` und `Stable tag` in `readme.txt`) – dann entsteht ein neues Release mit automatischen Release-Notes.
- Manuell starten: *Actions → Plugin-ZIP als Release → Run workflow*.

## Anpassen

Schriftart: unter **Termine → Einstellungen → Schriftart** (leer = Schrift des Themes) oder per Zusatz-CSS. Akzentfarbe per Zusatz-CSS:

```css
.fmvt {
	--fmvt-accent: #b91c1c;              /* Akzentfarbe */
	--fmvt-font: "Open Sans", sans-serif; /* Schriftart */
	--fmvt-badge-width: 6rem;             /* Breite des Datumskastens in der Liste */
}
```

**Hinweis:** Den Shortcode im Editor als normalen Text einfügen, nicht als Code formatieren.

## Lizenz

GPL-2.0-or-later
