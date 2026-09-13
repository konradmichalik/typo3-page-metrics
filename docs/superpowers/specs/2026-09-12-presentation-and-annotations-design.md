# Darstellungsschicht und Annotationen

**Status:** Entwurf, zur Review
**Bezug:** [`2026-09-11-bot-inspect-merge-design.md`](2026-09-11-bot-inspect-merge-design.md) (Bot-Buckets als Metriken, `maxDimensions`-Folgearbeit),
`docs/page-tiles.md`, `docs/dashboard-widgets.md`, `docs/extending.md`

## 1. Ausgangslage

Die Datenpipeline steht: URL zu Page-UID über Redirect-Ketten und mehrsprachige Slugs,
Bot-Taxonomie, idempotenter Upsert, drei Provider. Die Darstellungsschicht ist dahinter
zurückgeblieben. Sie hat zwei Oberflächen, die denselben Baukasten unterschiedlich weit
benutzen:

| Oberfläche | Einstieg | Zeitraum | Interaktion |
|---|---|---|---|
| Page-Header (`Web > Page`) | `PageHeaderMetricsRenderer`, AJAX zu `PageMetricsTilesController` | 7/30/90, in der URL | Periodenwechsel, Unterseiten-Toggle, Popover pro Kachel |
| Dashboard (EXT:dashboard) | sieben Widgets über `SiteMetricsOverviewBuilder` | je Widget fest oder als Setting | Chart-Tooltip, Widget-Settings |

Ein eigenständiges Backend-Modul gab es einmal, es ist aufgelöst worden (siehe Docblock
`Classes/Service/PageMetricsViewDataBuilder.php`). Damit existiert kein Ort für site-weite
Auswertung ohne EXT:dashboard und keiner für einen Export.

Eine Durchsicht der Templates, des CSS und des JavaScript gegen die Screenshots in `docs/img/`
hat zwei Klassen von Befunden ergeben: Aussagen, die die Oberfläche trifft und die Daten nicht
decken, und Uneinheitlichkeiten, die entstanden sind, weil dieselbe Semantik mehrfach
implementiert wurde.

### 1.1 Aussagen ohne Deckung

Diese wiegen schwerer als jede Stilfrage, weil sie das Werkzeug unglaubwürdig machen.

1. **Der Trend kennt keine Polarität.** `MetricDefinition` trägt Label, Icon, Einheit, Farbe,
   aber keine Angabe, ob ein Anstieg gut oder schlecht ist. `.pagemetrics-tile-delta-up` ist
   unbedingt grün. In `docs/img/page-tiles.png` steht folglich "Bounce Rate 31 %, +11 %" in
   Grün. Dieselbe Verwechslung im Trend-Widget: "Biggest gains" auf Bounce Rate listet die
   größten Verschlechterungen.
2. **Fehlender Tag und Nullwert sind nicht unterscheidbar.** `DailyMetricsRepository::siteSeries()`
   füllt jeden Tag ohne Zeile mit `0`. Im 90-Tage-Chart sieht der Import-Start dadurch aus wie
   ein Absturz auf Null (`docs/img/dashboard-widgets.png`). Dasselbe gilt für jeden Ausfall der
   Analytics-Quelle.
3. **"Today" ist in der Regel falsch.** Das Popover beschriftet den letzten Serienwert mit
   "Today". Der Import läuft täglich, der letzte vollständige Tag ist meist gestern. Der
   tatsächliche Datenstand liegt in `tx_typo3pagemetrics_import`, wird aber nirgends angezeigt.
4. **Charts ohne Skala.** Es gibt weder Achsenbeschriftung noch Maximum. Zwei nebeneinander
   liegende Compact-Charts skalieren unabhängig automatisch, sehen aber gleich aus.

### 1.2 Uneinheitlichkeiten

1. **Zwei Trend-Badges.** Inline in `Resources/Private/Templates/PageHeader/Metrics.html` und als
   Partial `Resources/Private/Partials/Widget/TrendBadge.html`, mit zwei CSS-Blöcken und
   denselben Hexwerten an vier Stellen (`page-metrics.css` Zeilen 397, 401, 757, 761).
2. **Kein Dark Mode.** TYPO3 kann seit 13.3 zwischen hellem und dunklem Schema wechseln. Das CSS
   nutzt zwar an 68 Stellen `var(--typo3-*)`, mischt Akzentflächen aber gegen hart kodiertes
   `white` (Zeilen 345, 697) und setzt Trendfarben absolut.
3. **Text im JavaScript.** "Today", "Yesterday", "Peak", "Average", "Via redirects" und
   "Open in Matomo" stehen hart englisch in `sparkline.js`, während 66 Labels im XLF liegen.
   "Open in Matomo" ist bei GA4- oder Access-Log-Quelle zusätzlich sachlich falsch.
4. **Drei Zeitraummodelle.** Page-Tiles 7/30/90, `pageMetrics`-Widget fest 7 Tage auf der
   Startseite, TopPages und NotFound fest 30, Annotations fest 90. Zahlen zwischen den Flächen
   sind nicht vergleichbar, und nichts weist darauf hin.
5. **Kachelinflation.** Mit dem Access-Log-Provider entstehen zwei Human- und fünf Bot-Kacheln
   in einer Reihe, optisch gleichwertig. Bot-Traffic konkurriert mit den Zahlen, für die die
   Oberfläche gebaut ist.
6. **Drei Widget-Anatomien** bei fünf Widgets: Aside-Icon (Trend, NotFound), reine Tabelle
   (TopPages, Annotations), Kacheln (PageMetrics).

### 1.3 Annotationen sind ein Platzhalter

`Annotation` trägt `id`, `date`, `text`. Site-weit, ein Tag, keine Kategorie, kein Seitenbezug.
Die manuelle Variante liegt wegen `rootLevel = 1` auf der absoluten Wurzel und ist damit
**nur für Admins anlegbar**, also gerade nicht für die Zielgruppe. Eine Funktion, die
redaktionelle Disziplin verlangt und Redakteure aussperrt, wird nicht benutzt.

## 2. Ziel und Nicht-Ziele

**Ziel:** Aus einer Anzeige von Zahlen eine Aussage machen, ohne die Datengrundlage zu
verlassen. Jede Kachel und jedes Widget soll in einem Satz vorlesbar sein, und jeder Sprung in
einer Kurve soll eine auffindbare Ursache haben.

**Nicht-Ziele:**

- **Kein eigener Tracking-Code.** Scroll-Tiefe, Engaged Time, Heatmaps und Klickpfade
  erforderten Frontend-Instrumentierung. Das widerspricht der Positionierung ("No tracking
  code, no third-party service") und steht nicht zur Debatte.
- **Keine Änderung an der Importpipeline.** Resolver, Upsert-Semantik und
  `MetricProviderInterface`-Vertrag bleiben, abgesehen von den in §4.1 und §4.12 beschriebenen
  additiven Erweiterungen.
- **Keine KI-gestützte Interpretation.** Insight-Karten (§4.7) entstehen aus festen Schwellen,
  nicht aus einem Modell.
- **Kein Chart-Framework.** Die SVG-Eigenbauten bleiben. Sie sind klein, abhängigkeitsfrei und
  erfüllen ihren Zweck, sobald sie eine Skala bekommen.

## 3. Leitgedanke

Die Recherche zu vergleichbaren Umsetzungen (Matomo "Movers and Shakers" und das Community-Plugin
"Generated Insights", Parse.ly und Chartbeat im redaktionellen Umfeld, Plausible und Burst als
privacy-first Werkzeuge, GA4- und Shopify-Annotationen, Grafana-Annotationen) ergibt drei
wiederkehrende Muster:

1. **Eine Zahl ohne Kontext ist Dekoration.** Eine KPI-Karte braucht neben dem Wert eine zweite
   Ebene: Vergleich, Ziel oder Benchmark. Wir haben den Vergleich zur Vorperiode, es fehlen Ziel
   und Benchmark.
2. **Relevanz schlägt Vollständigkeit.** Fünf bis neun Elemente sind die Wahrnehmungsgrenze.
   Alles weitere gehört hinter einen Drilldown, nicht in eine längere Liste. Werkzeuge, die
   Redaktionen tatsächlich benutzen, zeigen wenig und filtern hart nach Signifikanz.
3. **Kontext entsteht am besten automatisch.** Shopifys Annotationen werden aus der
   Systemaktivität erzeugt, farbcodiert nach Ereignistyp, mit Tagesdetail beim Klick. Kein
   Setup, keine Disziplin. Das ist der entscheidende Unterschied zu GA4 und Matomo, wo
   Annotationen von Hand gepflegt werden müssen und deshalb meist leer bleiben. TYPO3 weiß
   selbst, wann eine Seite bearbeitet oder ein Redirect angelegt wurde.

## 4. Entscheidungen

### 4.1 Polarität gehört in die MetricDefinition, Farbe an das Sentiment

Ein Anstieg ist bei Seitenaufrufen gut, bei der Bounce Rate schlecht, bei der Verweildauer
kontextabhängig. Die Richtung kann das nicht ausdrücken, also braucht die Metrik eine eigene
Angabe.

```php
enum MetricPolarity: string
{
    case HigherIsBetter = 'higher_is_better';
    case LowerIsBetter = 'lower_is_better';
    case Neutral = 'neutral';
}

enum TrendSentiment: string
{
    case Positive = 'positive';
    case Negative = 'negative';
    case Neutral = 'neutral';
}
```

`TrendDirection` bleibt unverändert und steuert weiterhin das Pfeil-Icon. Neu ist, dass die
**Farbe an `TrendSentiment` gebunden** wird, abgeleitet aus Richtung und Polarität. Grün heißt
danach "besser geworden", nicht "gestiegen".

`MetricPolarity` wird ein Konstruktorparameter von `MetricDefinition` und `RateMetricDefinition`.
Im Gegenzug entfällt `MetricDefinition::$maxDimensions`, das nirgends gelesen wird und bereits im
Merge-Spec §3.1 zur Entfernung vorgemerkt ist. Damit bleibt die Parameterliste gleich lang, und
positionale Aufrufe brechen sichtbar statt still. Die Extension steht auf `0.1.0` ohne Tag, ein
Bruch an einer `@api`-Signatur ist hier zulässig und wird in `docs/extending.md` dokumentiert.

Folgewirkung auf das Trend-Widget: die Einstellung heißt nicht mehr "direction: up/down", sondern
wählt ein Sentiment. "Biggest gains" bedeutet dann bei jeder Metrik dasselbe.

### 4.2 Ein fehlender Tag ist kein Nullwert

`siteSeries()` gibt weiterhin eine lückenlose Serie zurück, aber `null` statt `0` für Tage ohne
Zeile. Die Aggregation (`array_sum`) behandelt `null` wie `0`, die Darstellung nicht:

- Chart: Lücke in der Linie, keine Fläche, kein Punkt auf der Nulllinie.
- Tooltip: "keine Daten" statt "0".
- Kennzahlen im Popover (Peak, Average): fehlende Tage zählen nicht in den Nenner.

Eine echte Null bleibt eine echte Null und wird als Punkt auf der Nulllinie gezeichnet. Die
Unterscheidung ist damit exakt so belastbar wie die Importdaten.

### 4.3 Der Datenstand ist Teil der Darstellung

`tx_typo3pagemetrics_import.last_imported_date` wird pro Site und Provider gelesen und als
"Stand: <Datum>" angezeigt, im Page-Header neben dem Periodenwechsel und in jedem Widget-Fuß.
Liegt der Stand mehr als zwei Tage zurück, wird er als Warnung ausgezeichnet, weil dann
vermutlich der Scheduler steht.

Damit entfällt "Today" als Beschriftung. Die Popover-Kennzahlen werden zu "Letzter Tag",
"Vortag", "Höchstwert", "Durchschnitt".

### 4.4 Ein Trend-Badge, eine Farbquelle

Der Inline-Badge in `Metrics.html` wird durch das vorhandene Partial ersetzt. Das Partial
bekommt den erklärenden `title` (aktueller Zeitraum, Vorzeitraum, beide Werte), den heute nur
der Tile-Badge hat. Farben kommen aus vier CSS-Custom-Properties, die einmal auf `:root` des
Extension-Stylesheets definiert und im dunklen Schema überschrieben werden:

```css
--pm-sentiment-positive, --pm-sentiment-negative, --pm-sentiment-neutral, --pm-surface-mix
```

`color-mix(..., white)` wird durch `color-mix(..., var(--typo3-component-bg, #fff))` ersetzt.
Zusätzlich wird die Farbe nirgends alleiniger Bedeutungsträger sein: Pfeil-Icon und Vorzeichen
bleiben, und der Badge bekommt ein `aria-label`, das die Aussage ausschreibt.

### 4.5 Kein Text im JavaScript

Alle Zeichenketten aus `sparkline.js` wandern ins XLF. Die Übergabe erfolgt als
`data-i18n`-Attribute am Container, den der AJAX-Controller ohnehin rendert, nicht über einen
zweiten Request. Der externe Link wird generisch beschriftet ("Im Analytics-Werkzeug öffnen") und
bekommt seinen konkreten Text vom Provider, der ihn erzeugt.

### 4.6 Bot-Metriken sind eine eigene Ebene

Human- und Bot-Metriken in einer Kachelreihe stellen eine Gleichwertigkeit her, die inhaltlich
nicht besteht. `MetricDefinition` bekommt deshalb eine `MetricGroup` (`Audience` als Default,
`Machine` für die fünf `bot.*`-Metriken). Die Page-Header-Reihe zeigt standardmäßig nur
`Audience`. Die Maschinen-Ebene wird zu **einer** zusammenfassenden Kachel ("Bot-Traffic", Summe
aller Buckets), deren Popover die Aufschlüsselung nach Bucket zeigt.

Das ist kein Datenverlust, sondern der Drilldown aus §3.2, und es macht den Slider in den
meisten Installationen überflüssig.

### 4.7 Signifikanz statt Prozent

`rankedByTrend()` filtert heute nur auf Richtung. Eine Seite von 2 auf 4 Aufrufe ist +100 % und
verdrängt eine Seite, die von 4.000 auf 3.000 gefallen ist.

Eingeführt wird eine Doppelschwelle, relativ **und** absolut, nach dem Vorbild von Matomos
"Generated Insights" (dort 12 % und 20 Visits für Besuche, 12 % und 3 Prozentpunkte für die
Bounce Rate). Die Sortierung erfolgt nicht mehr nach `deltaPercent`, sondern nach
`|absolute Änderung| * log(Volumen)`, damit Größenordnung und Volumen beide eingehen.

Die Schwellen gehören zur Metrik, nicht in den Kalkulator: `MetricDefinition` bekommt
`significanceRelative` (Default 0.1) und `significanceAbsolute` (Default 0, also aus), damit ein
Provider für seine eigenen Metriken sinnvolle Werte setzen kann.

Daraus folgt die **Insight-Karte**: ein Widget, das aus denselben Schwellen einen Satz baut
("Seitenaufrufe 30 Tage: 1.655, plus 29 Prozent gegenüber der Vorperiode"), höchstens vier
Karten, nach Signifikanz sortiert, leer wenn nichts signifikant ist. Leer ist hier eine
korrekte Aussage, kein Fehlerzustand.

### 4.8 Benchmark aus den eigenen Daten

Die zweite Kontextebene entsteht ohne neue Datenquelle: der Median aller Seiten derselben Site
im selben Zeitraum. Im Popover einer Kachel erscheint eine Zeile "Site-Median: 44 %", bei
Zählmetriken zusätzlich die Perzentil-Einordnung der Seite ("im obersten Fünftel").

Der Median wird über die vorhandene `sumsByPage()` berechnet und pro Request einmal
zwischengespeichert. Seiten ohne Daten zählen nicht in den Median.

### 4.9 Annotationen bekommen Kategorie, Zeitraum und Seitenbezug

Drei additive Felder, in dieser Reihenfolge nach Nutzen:

**Kategorie.** Ein geschlossenes Enum, keine Freitext-Tags, weil eine feste Menge Farbe und Icon
mitbringen kann und weil Grafana-Erfahrung zeigt, dass uneinheitlich getippte Tags den Filter
wertlos machen:

| Kategorie | Typische Herkunft |
|---|---|
| `release` | Deployment, Matomo-Release-Marker |
| `campaign` | Newsletter, Anzeigen, Messe |
| `content` | Seite veröffentlicht oder überarbeitet (automatisch, §4.10) |
| `redesign` | Relaunch, Template-Änderung |
| `incident` | Ausfall, Fehlkonfiguration |
| `seo` | Redirect, Slug-Änderung, Robots-Anpassung |
| `external` | Presse, Fremdverlinkung, Suchmaschinen-Update |

Farbe und Icon kommen aus dem Enum. Die Marker im Chart werden dadurch ohne Hover lesbar, und das
Chart-Widget bekommt eine Legende samt Kategorie-Filter.

**Zeitraum.** `date_until` als Nullable. Eine Kampagne läuft Wochen, kein Ereignis an einem Tag.
Im Chart wird daraus ein Band statt eines Strichs. GA4 hat genau diese Erweiterung 2025
nachgezogen, zusammen mit der Farbcodierung.

**Seitenbezug.** `page_uid` als Nullable plus `include_subpages`. Heute erscheint jeder Marker auf
jeder Kachel jeder Seite. Ein seitenbezogener Marker ("diese Seite wurde überarbeitet") erscheint
nur dort, wo er etwas erklärt. `null` bleibt die site-weite Bedeutung von heute.

Beide Annotationstabellen bekommen dieselben Felder, und der bestehende `AnnotationProviderInterface`
wird additiv erweitert: der Matomo-Provider liefert weiterhin `release`, solange Matomo nichts
Genaueres hergibt.

### 4.10 Automatische Marker aus TYPO3

Der Hebel, der Annotationen von einer selten gepflegten Randfunktion zu einer tragenden macht.
Ein Listener erzeugt Marker der Kategorie `content` aus dem, was TYPO3 ohnehin protokolliert:

- Seite veröffentlicht oder deren Inhalt geändert, ermittelt aus `sys_history` beim nächtlichen
  Lauf statt aus einem DataHandler-Hook, damit fünf Bearbeitungen an einem Tag einen Marker
  ergeben und nicht fünf.
- Neuer Redirect auf eine Seite, Kategorie `seo`, sofern EXT:redirects vorhanden ist.

Diese Marker sind seitenbezogen (§4.9), quellengebunden und für den Redakteur nicht editierbar,
analog zu GA4s System-Annotationen. Ein Schalter in der Site-Konfiguration schaltet sie ab.

Darauf setzt der **Vorher-Nachher-Vergleich** auf: zu jedem Marker lassen sich die N Tage davor
und danach gegenüberstellen. "14 Tage vor der Überarbeitung 420 Aufrufe, 14 Tage danach 680" ist
die direkteste Form von belegbarem Erfolg, die sich aus reinen Trackingdaten gewinnen lässt.

### 4.11 Marker anlegen können, wer sie braucht

`rootLevel = 1` fällt. Stattdessen ein konfigurierbarer Storage-PID pro Site
(`pageMetrics.annotations.storagePid`), damit die normale TYPO3-Rechteverwaltung greift und
Redakteure Marker anlegen können.

Zusätzlich ein "Marker setzen"-Eintrag im Options-Menü des Page-Headers, der das
FormEngine-Formular mit vorbelegter Site, vorbelegtem Datum und vorbelegter Seite öffnet. Marker
entstehen dort, wo die Arbeit passiert, nicht in einem Wurzel-Datensatz.

Ein Klick auf einen Marker im Chart öffnet die Tagesansicht: alle Ereignisse dieses Tages plus
die Tageswerte jeder Metrik. Das ist Shopifys Annotationspanel und macht aus Kontext eine
Diagnose.

### 4.12 Ziele sind Metriken, kein Sonderweg

Der Punkt, an dem aus Traffic Geschäftswert wird, und damit der eigentliche Kern von "messbarer
Erfolg". Beide Provider können es:

- **Matomo** liefert Zielkonversionen nach Page-URL seit dem "Goals per page"-Ausbau. Über
  `Actions.getPageUrls` mit `filter_update_columns_when_show_all_goals=1` und `idGoal` kommen
  `goal_<id>_nb_conversions` und `goal_<id>_revenue` in derselben Antwort mit, die heute schon
  geholt wird. Kein zusätzlicher Request pro Tag.
- **GA4** liefert `keyEvents` zur `pagePath`-Dimension im bereits verwendeten `runReport`.

Gespeichert wird als gewöhnliche Metrik `matomo.goal.<id>.conversions` beziehungsweise
`ga4.key_event.<name>`, also ohne Schemaänderung. Die Ziele werden pro Site konfiguriert
(welche IDs, welches Label, welche Polarität), weil nur der Betreiber weiß, welches Ziel zählt.

Daraus folgt eine Kachel pro konfiguriertem Ziel und eine Spalte in "Most viewed pages". Erst
damit beantwortet die Extension die Frage, für die sie gebaut wurde.

### 4.13 Herkunft pro Seite

Die zweite Belegquelle für Erfolg: woher die Besucher einer Seite kommen. Matomo über
`Referrers.getReferrerType` mit `segment=pageUrl==`, GA4 über `sessionDefaultChannelGroup` zur
`landingPage`.

Das passt ohne Schemaänderung in die vorhandene `dimension`-Spalte
(`metric = matomo.visits`, `dimension = channel:organic`), die heute nur `via_redirect` trägt.
Darstellung als Aufschlüsselung im Popover, nicht als eigene Kachel.

## 5. Datenmodell

Alle Änderungen sind additiv. Es gibt keine Migration, weil die Extension ungetaggt ist und
`page-metrics:import --force` jeden Stand neu aufbaut.

```sql
-- tx_typo3pagemetrics_annotation und tx_typo3pagemetrics_manual_annotation, beide:
category varchar(16) DEFAULT 'release' NOT NULL,
date_until date DEFAULT NULL,          -- im manual-Pendant int(11) DEFAULT NULL, TCA-Konvention
page_uid int(11) unsigned DEFAULT '0' NOT NULL,   -- 0 = site-weit
include_subpages tinyint(1) unsigned DEFAULT '0' NOT NULL,
```

`Annotation` als Value Object wächst entsprechend um `category`, `dateUntil`, `pageUid` und
`includeSubpages`. Es ist `@api`, der Bruch wird in `docs/extending.md` vermerkt.

Neue Metrikbezeichner, ohne Schemaänderung:

| Bezeichner | Quelle |
|---|---|
| `matomo.goal.<id>.conversions`, `matomo.goal.<id>.revenue` | §4.12 |
| `ga4.key_event.<name>` | §4.12 |
| `dimension = channel:<gruppe>` auf bestehenden Metriken | §4.13 |

## 6. Oberflächen

**Page-Header.** Kachelreihe nur mit `Audience`-Metriken plus einer Bot-Sammelkachel und einer
Kachel pro konfiguriertem Ziel. Datenstand neben dem Periodenwechsel. Popover um Site-Median,
Perzentil und Herkunftsaufschlüsselung erweitert. Im Options-Menü zusätzlich "Marker setzen".

**Dashboard.** Neues Insight-Widget an erster Stelle des Presets. Chart-Widget mit
Kategorielegende, Kategoriefilter und Bändern für Zeitraum-Marker. Trend-Widget mit
Signifikanzfilter und sentimentbasierten Bezeichnungen. Einheitliche Widget-Anatomie: die
Aside-Icon-Variante wird die Regel, alle Tabellen-Widgets ziehen nach. Jedes Widget zeigt seinen
Zeitraum im Titel und seinen Datenstand im Fuß.

**Kein neues Backend-Modul in diesem Spec.** Siehe §8.

## 7. Sequenzierung

Jedes Paket ist für sich auslieferbar und wird ein eigenes Issue.

**Paket A, Richtigstellung.** Ohne dieses Paket trifft die Oberfläche falsche Aussagen, deshalb
zuerst und in dieser Reihenfolge:

1. Polarität und Sentiment (§4.1), [#13](https://github.com/konradmichalik/typo3-page-metrics/issues/13)
2. Fehlende Tage von Nullwerten trennen (§4.2), [#14](https://github.com/konradmichalik/typo3-page-metrics/issues/14)
3. Datenstand anzeigen (§4.3), [#15](https://github.com/konradmichalik/typo3-page-metrics/issues/15)
4. Trend-Badge zusammenführen, Dark Mode, Sentiment-Tokens (§4.4), [#16](https://github.com/konradmichalik/typo3-page-metrics/issues/16)
5. Texte aus dem JavaScript ins XLF (§4.5), [#17](https://github.com/konradmichalik/typo3-page-metrics/issues/17)
6. Chart-Skala, [#18](https://github.com/konradmichalik/typo3-page-metrics/issues/18)

**Paket B, Relevanz.**

7. Bot-Metriken als eigene Ebene (§4.6), [#19](https://github.com/konradmichalik/typo3-page-metrics/issues/19)
8. Signifikanzschwellen im Trend-Widget (§4.7), [#20](https://github.com/konradmichalik/typo3-page-metrics/issues/20)
9. Insight-Widget (§4.7), [#21](https://github.com/konradmichalik/typo3-page-metrics/issues/21)
10. Site-Median und Perzentil (§4.8), [#22](https://github.com/konradmichalik/typo3-page-metrics/issues/22)

**Paket C, Marker.**

11. Kategorie, Zeitraum, Seitenbezug (§4.9), [#23](https://github.com/konradmichalik/typo3-page-metrics/issues/23)
12. Storage-PID und "Marker setzen" aus dem Page-Header (§4.11), [#24](https://github.com/konradmichalik/typo3-page-metrics/issues/24)
13. Automatische Marker aus `sys_history` und `sys_redirect` (§4.10), [#25](https://github.com/konradmichalik/typo3-page-metrics/issues/25)
14. Tagesansicht und Vorher-Nachher-Vergleich (§4.10, §4.11), [#26](https://github.com/konradmichalik/typo3-page-metrics/issues/26)

**Paket D, Geschäftswert.**

15. Ziele und Conversions (§4.12), [#27](https://github.com/konradmichalik/typo3-page-metrics/issues/27)
16. Herkunft pro Seite (§4.13), [#28](https://github.com/konradmichalik/typo3-page-metrics/issues/28)

## 8. Offene Punkte

1. **Backend-Modul.** Ein Export für den Kunden, eine Content-Decay-Liste (Traffic fällt,
   `pages.tstamp` ist alt) und eine site-weite Auswertung ohne EXT:dashboard brauchen einen Ort.
   Das aufgelöste Modul wieder einzuführen ist eine eigene Entscheidung mit eigenem Spec, weil
   sie die Positionierung der Extension berührt. Hier bewusst nicht entschieden.
   **Nachtrag:** entschieden in [`2026-09-13-backend-module-design.md`](2026-09-13-backend-module-design.md).
2. **Verweildauer-Polarität.** `Neutral` oder `HigherIsBetter`? Bei einer Kontaktseite ist eine
   kurze Verweildauer ein gutes Zeichen. Vorschlag: `Neutral` als Default, pro Site
   überschreibbar.
3. **Schwellenwerte.** Die Zahlen aus §4.7 sind von "Generated Insights" übernommen und an
   Matomo-Cloud-Volumen kalibriert. Für kleine TYPO3-Sites sind sie möglicherweise zu hoch.
   Nach der ersten Installation mit echten Daten prüfen.
4. **Automatische Marker und Rauschen.** Eine Site mit täglichen Redaktionsläufen erzeugt jeden
   Tag einen `content`-Marker. Falls das zu dicht wird, pro Tag und Seite zusammenfassen oder
   eine Mindeständerungsgröße aus `sys_history` verlangen.
