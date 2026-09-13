# Ein eigenes Backend-Modul

**Status:** Entwurf, zur Review
**Bezug:** [`2026-09-12-presentation-and-annotations-design.md`](2026-09-12-presentation-and-annotations-design.md) §8.1 (dort bewusst offengelassen),
`docs/page-tiles.md`, `docs/dashboard-widgets.md`

## 1. Ausgangslage

Die Darstellungsschicht hat seit dem letzten Spec zwei Oberflächen und sechzehn gemergte
Änderungen. Sie hat damit auch ein Problem, das keine davon für sich verursacht hat.

| Oberfläche | Rolle | Einstieg |
|---|---|---|
| Page-Header (`Web > Page`) | im Vorbeigehen: läuft diese Seite | `PageHeaderMetricsRenderer`, AJAX zu `PageMetricsTilesController` |
| Dashboard (EXT:dashboard) | im Vorbeigehen: läuft die Site | sieben Widgets über `SiteMetricsOverviewBuilder` |

Ein eigenständiges Modul gab es einmal. Es ist aufgelöst worden, und der Docblock von
`PageMetricsViewDataBuilder` hält fest, was davon übrig blieb: die View-Logik wurde
herausgezogen, damit Page-Header und jeder künftige Konsument eine getestete Quelle teilen.
Genau diese Trennung macht die Rückkehr jetzt billig.

### 1.1 Das Popover trägt mehr, als ein Popover tragen kann

Im Popover einer Kachel stehen inzwischen: fünf Kennzahlen als Icons mit Hover-Erklärung, ein
Chart mit Datumsbeschriftung, Marker als Tick oder als Streifen, eine Aufschlüsselung für die
Bot-Sammelkachel, und der Site-Median mit Feldbreite und Einordnung.

Jede dieser Erweiterungen war für sich richtig. Zusammen haben sie eine Beiläufigkeit in eine
Auswertung verwandelt, die man nicht sitzend lesen kann, weil sie beim Wegbewegen der Maus
verschwindet.

Die Icon-only-Reihe ist der sichtbarste Beleg. Sie ist entstanden, weil fünf zweiwortige Labels
die Zeile auf drei ungleiche Zeilen umgebrochen haben. Der Name und die Erklärung liegen seither
in Text, der vorhanden, aber nicht gezeichnet ist. Das ist für Screenreader korrekt gelöst und
bleibt trotzdem ein Handel: sehende Nutzer müssen fünf Icons lernen oder fünfmal hovern. Jede
weitere Kennzahl verschärft denselben Handel, und es ist kein Zufall, dass die Reihe bei der
sechsten Kachel (`typicalPage`) umbricht.

### 1.2 Was schon jetzt nicht mehr hineinpasst

Vier offene Vorhaben lassen sich im Popover nicht ehrlich umsetzen:

- **[#26](https://github.com/konradmichalik/typo3-page-metrics/issues/26)** Tagesdetail und
  Vorher-Nachher-Vergleich um einen Marker. Ein Vergleich zweier Zeiträume in einem
  Hover-Popover ist keine ernsthafte Option.
- **[#21](https://github.com/konradmichalik/typo3-page-metrics/issues/21)** Insight-Widget, das
  in einem Satz sagt, was sich geändert hat. Als Widget geplant, aber die Begründung dahinter
  ("warum sagt es das") braucht Platz.
- **[#28](https://github.com/konradmichalik/typo3-page-metrics/issues/28)** Herkunft pro Seite.
  Eine Verteilung über Quellen ist eine Tabelle, keine Kachel.
- **[#65](https://github.com/konradmichalik/typo3-page-metrics/issues/65)** Goal-Spalte in
  "Most viewed pages". Eine zweite Spalte ist ein Hinweis darauf, dass die Liste eigentlich
  mehrere Spalten will.

### 1.3 Was ohne Modul gar keinen Ort hat

- Ein **Export** für den Kunden. Die Frage "kannst du mir das schicken" beantwortet ein
  Screenshot des Seitenmoduls schlecht.
- Eine **Content-Decay-Liste**: Traffic fällt, `pages.tstamp` ist alt. Das ist die konkreteste
  Handlungsempfehlung, die aus diesen Daten überhaupt ableitbar ist, und sie gehört in keine
  Kachelreihe.
- Site-weite Auswertung **ohne EXT:dashboard**. Das Dashboard ist optional und wird in vielen
  Installationen nicht ausgerollt; für die ist die Extension derzeit rein seitenbezogen.
- **Erläuterungen als Fließtext.** Was ein Median über fünf Seiten aussagt, ist ein Absatz, kein
  Tooltip.

## 2. Ziel und Nicht-Ziele

**Ziel.** Ein Ort, an dem man sich mit den Zahlen hinsetzt: mit Platz, mit Text, mit Vergleichen
über Zeiträume und über Seiten, und mit einem Export.

**Nicht-Ziele.**

1. **Kein Ersatz für Matomo oder GA4.** Die Extension beantwortet seitenbezogene Fragen im
   Redaktionskontext. Segmente, Funnels, Kohorten und Echtzeit bleiben draußen. Der externe Link
   auf der Kachel existiert genau dafür.
2. **Kein zweites Dashboard.** Das Dashboard beantwortet "wie steht die Site" auf einen Blick.
   Das Modul beantwortet "warum" und "was tue ich".
3. **Keine neue Datenschicht.** Wenn eine Ansicht eine neue Query braucht, gehört sie in die
   bestehenden Repositories, nicht in einen Controller.
4. **Kein Reportgenerator.** Ein Export, kein Berichtsdesigner mit gespeicherten Layouts.

## 3. Leitgedanke

> Das Popover behält, was man **im Vorbeigehen** liest. Alles, wofür man sich **hinsetzt**,
> zieht ins Modul.

Das ist als Test formuliert, nicht als Beschreibung, damit er auf künftige Features anwendbar
ist. Zwei Fragen entscheiden:

1. Liest man es, während man eigentlich etwas anderes tut? Dann Popover.
2. Verschwindet die Antwort, wenn die Maus weggeht, und ist das schlimm? Dann Modul.

Der Verlauf einer Kennzahl und die Einordnung "gut oder schlecht" bestehen den ersten Test. Ein
Vergleich zweier Zeiträume, eine Herkunftsverteilung und die Frage "was hat dieser Release
bewirkt" bestehen ihn nicht.

## 4. Entscheidungen

### 4.1 Das Modul hängt unter `web`, nicht unter `tools`

`'parent' => 'web'` funktioniert auf beiden unterstützten Versionen. TYPO3 14 hat die
Modulgruppen umbenannt, `web` ist dort ein Alias auf `content`
(`typo3/cms-core/Configuration/Backend/Modules.php`: `'content' => [... 'aliases' => ['web']]`).
Der Alias ist der dual-version-sichere Weg, die neuen v14-Gruppen wie `content_status` sind es
nicht, weil sie in 13.4 nicht existieren.

Unter `web` und nicht unter `tools` oder `site`, weil das Modul eine redaktionelle Frage
beantwortet und nicht eine administrative. Wer die Seite pflegt, soll es finden, ohne Rechte auf
Werkzeugmodule zu haben.

### 4.2 Der Seitenbaum bleibt, und das entscheidet die Struktur

Das Modul bekommt `navigationComponent` mit dem Seitenbaum. Damit ist die ausgewählte Seite ein
Zustand des Moduls, kein Formularfeld darin, und "Metriken zu dieser Seite" ist einen Klick vom
Seitenmodul entfernt statt einer Suche.

Daraus folgt die Aufteilung: **jede Ansicht ist seitenbezogen, außer sie ist ausdrücklich
site-weit.** Site-weite Ansichten werten die Site des gewählten Seitenbaums aus, nicht "alle
Sites". Eine Installation mit fünf Sites bekommt fünf Antworten, je nachdem wo man steht, und
nicht eine Summe, die für keine davon stimmt.

### 4.3 Zustand über `moduleData`, nicht über `uc`

Zeitraum, aktive Ansicht und Unterseiten-Schalter gehören in TYPO3s `moduleData`, das im
Modul-Array deklariert und pro Backend-User persistiert wird (`ModuleData` existiert in 13.4 und
14 gleich). Das ist derselbe Mechanismus, den das Info-Modul für `depth` und `lang` nutzt.

`PageMetricsUserPreferences` (im `uc`) bleibt, was es ist: der eingeklappte Zustand der
Page-Header-Leiste. Das ist eine Entscheidung über eine *andere* Oberfläche und hat im
Modulzustand nichts verloren.

### 4.4 Zugriff über denselben Schalter wie die Kacheln

`PageMetricsAccessChecker` gilt auch hier. Wer `tx_typo3pagemetrics.disabled = 1` gesetzt
bekommen hat, sieht das Modul nicht im Menü und erreicht es nicht über die Route.

Das Modul selbst bekommt `'access' => 'user'`, nicht `'admin'`. Die Zahlen sind für Redakteure,
und ein Modul, das nur Administratoren sehen, beantwortet die Frage aus §1.3 nicht.

Wichtig und leicht zu übersehen: die Seitenauswahl aus dem Seitenbaum ist ein Request-Parameter.
Jede Ansicht prüft `PAGE_SHOW` auf der gewählten Seite, so wie `PageMetricsViewDataBuilder` es
heute schon tut, bevor sie irgendetwas lädt.

### 4.5 Keine neue Datenschicht

`PageMetricsViewDataBuilder` und `SiteMetricsOverviewBuilder` sind bereits unabhängig vom
Seitenmodul, genau deshalb wurden sie herausgezogen. Die Dashboard-Widgets beweisen das seit
Monaten.

Neue Ansichten, die neue Aggregate brauchen (Content-Decay, Vorher-Nachher), bekommen ihre Query
in `DailyMetricsRepository` oder `PageTrendCalculator` und einen funktionalen Test dort. Der
Controller bleibt dünn.

### 4.6 Der Schnitt zwischen Popover und Modul, konkret

| bleibt im Popover | zieht ins Modul |
|---|---|
| Sparkline des Zeitraums | Vergleich zweier Zeiträume |
| Letzter Tag, Vortag, Spitze, Mittel | dieselben Werte als beschriftete Tabelle |
| Marker als Tick und Streifen | was ein Marker bewirkt hat (#26) |
| Site-Median mit Einordnung | das Feld dahinter: welche Seiten, in welcher Reihenfolge |
| Aufschlüsselung der Bot-Kachel | Bot-Verlauf und Agenten im Detail |
| Anteil über Redirects | Herkunft insgesamt (#28) |

Das Popover verliert dabei **nichts**. Es hört nur auf, der einzige Ort zu sein.

### 4.7 Erklärungen sind Fließtext, nicht Tooltip

Im Modul steht neben jeder Ansicht ein Absatz, der sagt, was die Zahl bedeutet und wo sie
herkommt, in derselben Sprache wie `docs/page-tiles.md`. Der Tooltip-Mechanismus aus
`sparkline.js` wird hier **nicht** verwendet.

Das ist kein Stilwunsch. Der Grund, überhaupt ein Modul zu bauen, ist Platz für Erklärung; sie
dann wieder hinter einen Hover zu legen, wäre der Fehler aus §1.1 an neuem Ort.

### 4.8 Export als CSV, seitenweise, ohne Formatierung

Ein Button pro Ansicht, der genau das ausgibt, was die Ansicht zeigt: dieselben Zeilen, dieselbe
Reihenfolge, dieselbe Periode. CSV, keine formatierte Datei, weil der Empfänger es ohnehin in
eine Tabellenkalkulation kippt.

Werte im Export sind **Rohwerte in ihrer eigenen Einheit**, nicht die formatierten Strings:
`183` statt `3:03`, Minor Units bei Währung wie in der Faktentabelle. Was die Oberfläche
formatiert, ist eine Lesehilfe, kein Datum.

Kein PDF und kein Branding. Das wäre der Reportgenerator aus §2.

### 4.9 Das Modul ist kein Pflichtbestandteil

Die Extension bleibt ohne Modul vollständig benutzbar, so wie sie heute ohne EXT:dashboard
vollständig benutzbar ist. Wer nur die Kacheln im Seitenmodul will, soll nichts abschalten
müssen.

Umgekehrt: das Modul funktioniert **ohne EXT:dashboard**. Das ist einer der Gründe, es zu bauen
(§1.3), und es heißt, dass keine Ansicht einen Widget-Service voraussetzen darf.

### 4.10 Eine Route, mehrere Ansichten, keine Untermodule

Untermodule im Modulmenü wären die TYPO3-typische Form, aber sie kosten hier mehr als sie
bringen: `showSubmoduleOverview` gibt es erst in 14, die Gruppenstruktur unterscheidet sich
zwischen den Versionen (§4.1), und fünf Einträge im Menü für eine Extension sind zudringlich.

Stattdessen ein Modul mit einem Ansichtswechsel im Docheader, wie ihn das Info-Modul in 13 für
seine Unteransichten verwendet. Die aktive Ansicht liegt in `moduleData` (§4.3).

## 5. Datenmodell

**Keine Schemaänderung.** Alle Ansichten lesen aus `tx_typo3pagemetrics_daily`,
`tx_typo3pagemetrics_annotation`, `tx_typo3pagemetrics_manual_annotation` und
`tx_typo3pagemetrics_unresolved`.

Zwei Dinge, die bewusst **nicht** eingeführt werden:

- **Gespeicherte Ansichten.** Eine Konfiguration, die ein Nutzer benennt und wiederfindet, ist
  ein eigenes Feature mit eigener Tabelle und eigenen Rechten. Bis jemand danach fragt, reicht
  `moduleData`.
- **Vorberechnete Aggregate.** Die teuerste geplante Ansicht ist Content-Decay, und die ist ein
  Aggregat über die Seiten einer Site, also dieselbe Größenordnung wie der Site-Median, den das
  Popover heute schon pro Kachel zieht. Eine Cache-Tabelle wäre eine Optimierung ohne gemessenes
  Problem.

## 6. Oberflächen

Fünf Ansichten. Jede beantwortet eine Frage, die man in einem Satz stellen kann.

**Seite.** Was die Kacheln zeigen, in gelesener Form: alle Kennzahlen als beschriftete Tabelle,
Chart über die volle Breite, die Marker des Zeitraums als Liste mit Kategorie, Zeitraum und
Text. Dazu der Zeitraumvergleich, den das Popover nicht tragen kann.

**Vergleich.** Zwei Zeiträume derselben Seite nebeneinander, mit Differenz und Signifikanz nach
derselben Regel wie das Trend-Widget (`MetricSignificance`). Das ist die Ansicht, in der #26
lebt: ein Marker auswählen und die Tage davor gegen die Tage danach stellen.

**Seiten.** Die Seiten der Site als Tabelle, sortierbar nach jeder Kennzahl, mit Trendspalte und
Goal-Spalte (#65). Hier gehört auch die Content-Decay-Sicht hin: fallender Traffic bei altem
`pages.tstamp`, als Sortierung, nicht als eigene Ansicht.

**Herkunft.** Die Verteilung aus #28, site-weit und pro Seite, plus der Redirect-Anteil, den die
Kachel heute als einzelne Zahl zeigt.

**Marker.** Alle Marker der Site in einem Ort: importierte, manuelle und abgeleitete,
unterscheidbar nach Quelle. Anlegen und Bearbeiten führen in die FormEngine, wie heute aus dem
Options-Menü (#24). Das ist auch der Ort, an dem die Dichte aus
[#64](https://github.com/konradmichalik/typo3-page-metrics/issues/64) beherrschbar wird, weil
eine Liste filterbar ist und eine Chart-Achse nicht.

**Page-Header und Dashboard** bleiben unverändert, bis auf einen Link ins Modul: auf der
Kachelreihe neben dem Datumsmarker, auf einem Widget im Fuß.

## 7. Sequenzierung

Jedes Paket ist für sich auslieferbar und wird ein eigenes Issue. Die Reihenfolge ist so
gewählt, dass nach Paket A etwas Benutzbares existiert und jede weitere Ansicht additiv ist.

**Paket A, das Gerüst.**

1. Modulregistrierung, Docheader mit Seitenauswahl, Zeitraum und Ansichtswechsel, Zugriff über
   `PageMetricsAccessChecker`, `PAGE_SHOW` je Request (§4.1 bis §4.4, §4.10), [#71](https://github.com/konradmichalik/typo3-page-metrics/issues/71)
2. Ansicht "Seite" auf Basis von `PageMetricsViewDataBuilder`, mit Erläuterungstexten (§4.7),
   [#72](https://github.com/konradmichalik/typo3-page-metrics/issues/72)
3. Link aus dem Page-Header und aus den Widgets ins Modul (§6), [#73](https://github.com/konradmichalik/typo3-page-metrics/issues/73)

**Paket B, die Fragen, die das Popover nicht beantwortet.**

4. Ansicht "Vergleich", zwei Zeiträume (§6), [#74](https://github.com/konradmichalik/typo3-page-metrics/issues/74)
5. Vorher-Nachher um einen Marker, [#26](https://github.com/konradmichalik/typo3-page-metrics/issues/26)
6. Ansicht "Marker", mit Filter nach Quelle und Kategorie, [#64](https://github.com/konradmichalik/typo3-page-metrics/issues/64)

**Paket C, Breite statt Tiefe.**

7. Ansicht "Seiten" mit sortierbaren Spalten, [#75](https://github.com/konradmichalik/typo3-page-metrics/issues/75)
8. Goal-Spalte, [#65](https://github.com/konradmichalik/typo3-page-metrics/issues/65)
9. Content-Decay als Sortierung derselben Tabelle, [#76](https://github.com/konradmichalik/typo3-page-metrics/issues/76)
10. Ansicht "Herkunft", [#28](https://github.com/konradmichalik/typo3-page-metrics/issues/28)

**Paket D, herausgeben.**

11. CSV-Export je Ansicht (§4.8), [#77](https://github.com/konradmichalik/typo3-page-metrics/issues/77)

Nicht in diesem Spec: [#21](https://github.com/konradmichalik/typo3-page-metrics/issues/21)
bleibt ein Dashboard-Widget. Ein Satz, der sagt was sich geändert hat, gehört auf die erste
Seite, die jemand sieht, und nicht in ein Modul, das man aufsuchen muss.

## 8. Offene Punkte

1. **Mehrsprachigkeit.** Alle Ansichten sind heute implizit `sys_language_uid = 0`, weil die
   Kacheln es sind. Ein Sprachwechsel im Docheader wäre die naheliegende Ergänzung, aber die
   Frage "was heißt ein Seitenvergleich über Sprachen hinweg" ist nicht beantwortet. Bewusst
   nicht entschieden.
2. **Content-Decay braucht eine Schwelle.** "Traffic fällt" ist `MetricSignificance`, "`tstamp`
   ist alt" braucht eine Zahl. 180 Tage sind ein Vorschlag, keine Messung. Gegen eine reale
   Installation prüfen, bevor sie hart verdrahtet wird.
3. **Wie viel Erläuterung ist zu viel.** §4.7 fordert Fließtext. Wo die Grenze zwischen
   erklärend und geschwätzig liegt, entscheidet sich erst am gerenderten Modul, nicht hier.
4. **Ob der Export die Rechte des Nutzers respektieren muss.** Eine Site-weite Tabelle enthält
   Seiten, die der exportierende Nutzer im Seitenbaum nicht sieht. Entweder der Export filtert
   auf `PAGE_SHOW` (dann stimmen die Summen nicht mit der Ansicht überein, die aggregiert), oder
   er tut es nicht (dann verlässt eine Zeile das Modul, die im Modul nicht sichtbar wäre). Das
   ist eine Sicherheitsentscheidung und gehört vor Paket D geklärt.
