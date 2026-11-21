=== FM-WPCalendar ===
Contributors: frozenmedia
Tags: termine, kalender, verein, events, veranstaltungen
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Einfache Terminverwaltung für Vereine mit Monatskalender, Terminliste, Sidebar-Widget sowie CSV-/Excel-Import und -Export.

== Description ==

* Termine mit Titel, Beschreibung, Datum, optionaler Uhrzeit (von/bis) und optionalem Ort
* Ganztägige und mehrtägige Termine
* Orte mit Name und optionaler Adresse – direkt im Termin auswählbar oder neu anlegbar
* Shortcode `[vereinstermine]` für Liste, Monatskalender oder kompakte Liste
* Widget „Nächste Termine“ für die Seitenleiste
* Import/Export als CSV oder Excel (XLSX)
* Detailseite je Termin mit Zurück-Link

Entwickelt von Frozen-Media – https://www.frozen-media.de

== Shortcodes ==

`[vereinstermine]`
Terminliste mit 10 Einträgen. Der nächste (bzw. heutige) Termin steht hervorgehoben oben, „Mehr anzeigen“ lädt weitere nach.

`[vereinstermine ansicht="liste" anzahl="5"]`
Liste mit 5 Einträgen pro Ladevorgang. Mit `mehr="nein"` wird der Button ausgeblendet.

`[vereinstermine ansicht="monat"]`
Monatskalender, startet im aktuellen Monat, vor- und zurückblätterbar. Auf schmalen Bildschirmen wird automatisch eine Tagesliste angezeigt.

`[vereinstermine ansicht="kompakt" anzahl="5"]`
Kompakte Liste wie im Widget.

Alle Shortcodes funktionieren auch als `[fm_wpcalendar …]` mit denselben Attributen.

== Installation ==

1. ZIP unter Plugins > Installieren > Plugin hochladen installieren und aktivieren.
2. Unter „Termine“ Termine anlegen.
3. Eine Seite „Termine“ anlegen und den Shortcode einfügen.
4. Unter Termine > Einstellungen diese Seite als „Terminseite“ wählen (für Zurück-Link und Widget).
5. Unter Design > Widgets das Widget „Nächste Termine“ in die Seitenleiste ziehen.

== Changelog ==

= 1.0.0 =
* Erste Version.
