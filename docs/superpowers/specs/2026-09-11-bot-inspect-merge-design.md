# Merge von `typo3-bot-inspect` in `typo3-page-metrics`

**Status:** Entwurf, zur Review
**Bezug:** [`2026-08-24-page-metrics-design.md`](2026-08-24-page-metrics-design.md) (Provider-Konzept),
[`2026-08-25-page-metrics-module-enrichment-design.md`](2026-08-25-page-metrics-module-enrichment-design.md) (0.2.0-Modul-Zusage),
`typo3-bot-inspect/docs/superpowers/specs/2026-08-26-page-level-resolution-design.md` §2 (Resolver-Entscheidung, wird hier revidiert)

## 1. Ausgangslage

`typo3-bot-inspect` ist als eigenständige Extension gewachsen und hat dabei die Architektur von
`typo3-page-metrics` ein zweites Mal gebaut. Seit dem Access-Log-Provider überschneiden sich beide
auf jeder Ebene der Datenpipeline:

| Konzept | page-metrics | bot-inspect |
|---|---|---|
| Log lesen inkl. gz | `AccessLogLineParser`, `ext-zlib` | `CombinedLogParser`, `LogFileReader`, `SourceLocator` |
| URL zu `page_uid` | `Resolver` (6 Klassen) | `PageUrlResolver` (schlanker) |
| Unaufgelöste URLs | `tx_typo3pagemetrics_unresolved.reason` | `UnresolvedReason` (identische vier Fälle) |
| Import-Idempotenz | `tx_typo3pagemetrics_import` | `tx_typo3botinspect_import` |
| Tages-Aggregate | `tx_typo3pagemetrics_daily` | vier `*_daily`-Tabellen |
| Bot-Erkennung | `BotFilter` (25 Substrings, binär) | `BotTaxonomy` (fünf Buckets, Guard) |
| ImportCommand | vorhanden | vorhanden |

Beide Resolver implementieren denselben Entwurf: Normalisierung, Site-Match, Longest-Base-Path-Language-Match,
Slug-Lookup, `sys_redirect`-Verfolgung mit maximal fünf Hops und Cycle-Guard, dieselben vier
`UnresolvedReason`-Fälle.

Die ursprüngliche page-metrics-Konzeption hatte bot-inspect bereits als Provider-Implementierung
vorgesehen. Dieser Merge führt die Sache in ihre geplante Form zurück.

### 1.1 Warum die Resolver-Entscheidung revidiert wird

`2026-08-26-page-level-resolution-design.md` §2 begründet bot-inspects eigenen Resolver mit drei
Argumenten, die alle auf **eigenständige Installierbarkeit** zielen: bot-inspect soll ohne
page-metrics lauffähig bleiben, Seitenbezug sei ein bot-inspect-eigenes Feature, und page-metrics
diene nur als Referenz für den Algorithmus. Als "akzeptierter Nachteil" ist dort wörtlich die
doppelte Auflösungslogik vermerkt.

Ein Merge widerspricht diesen Argumenten nicht, er löst ihre Prämisse auf. Nach dem Merge gibt es
keine zwei Extensions mehr, zwischen denen eine Abhängigkeit entstehen könnte. Der dort akzeptierte
Nachteil ist exakt das, was hier entfällt.

Der Umstieg auf page-metrics' `Resolver` ist zudem ein reiner Funktionszugewinn: bot-inspects
Variante ist laut §3 desselben Dokuments "bewusst schlanker", und `keepQueryParams` wurde laut §10
als YAGNI übersprungen.

## 2. Ziel und Nicht-Ziele

**Ziel:** eine Extension, eine Logpipeline, ein Resolver, eine Bot-Signaturliste, ein
`ImportCommand`, ein Aggregationsmodell.

**Nicht-Ziele:**

- Kein Datenmigrationspfad. Beide Extensions haben null Tags, bot-inspect steht auf `alpha 0.1.0`,
  es existieren nur Dev-Daten. Tabellen werden neu angelegt, Logs neu importiert.
- Keine Änderung an `MetricProviderInterface`. Siehe §3.5.
- Kein Status-Split pro Seite (`hits_ok` / `hits_redirect` / `hits_client_error` / `hits_server_error`).
  Fällt ersatzlos weg.
- Keine eigene Missing-Targets-Tabelle. Der Bedarf wird über das vorhandene
  `tx_typo3pagemetrics_unresolved` gedeckt.

## 3. Entscheidungen

### 3.1 Buckets sind Metriken, keine Dimension

Naheliegend wäre `dimension = <bucket>` in `tx_typo3pagemetrics_daily`. Das scheitert an der
Tile-Erzeugung: eine `MetricDefinition` trägt genau eine Farbe, die fünf Buckets brauchen je eigene,
und aus einer dimensionierten Metrik müssten Tiles erst aufgefächert werden.

Buckets sind ein geschlossenes Enum aus fünf Fällen. Sie werden deshalb fünf eigene Metriken:
`bot.ai_chatbot`, `bot.ai_crawler`, `bot.search`, `bot.social`, `bot.tool`, jede mit eigener
`MetricDefinition` samt Label, Icon, Farbe und `sortOrder`.

Damit trägt die bestehende Tile-, Chart- und Widget-Maschinerie die Bot-Daten ohne eine einzige
neue Mechanik, und `dimension` bleibt für seinen heutigen `via_redirect`-Zweck frei.

**Regel:** geschlossenes Enum wird Metrik, offene Menge wird Dimension oder eigene Tabelle. Agents
sind eine offene Menge und bekommen deshalb eine eigene Tabelle (§4.1).

**Folgearbeit:** `MetricDefinition::$maxDimensions` wird nirgends gelesen und bleibt auch nach
diesem Merge ungenutzt. Es sollte in einem eigenen Commit entfernt werden.

### 3.2 Zwei Speicherebenen statt einer

Die Bucket-Ebene geht in `tx_typo3pagemetrics_daily` und nutzt damit die komplette bestehende
Abfrage- und Darstellungsschicht. Die Agent-Ebene bekommt eine eigene schmale Tabelle.

Alternative wäre eine zweite Dimensionsspalte in der zentralen Faktentabelle gewesen. Dagegen
sprachen zwei Dinge: der Unique Key wäre unter utf8mb4 von rund 1400 auf rund 2400 Bytes gewachsen,
für genau einen Konsumenten, und weil EAV ein `value` pro Zeile hält, wären aus `hits` plus
`hits_suspicious` zwei Zeilen statt einer geworden.

### 3.3 Replace-Semantik, kein Byte-Offset-Resume

bot-inspect setzt bei Dateien am Byte-Offset fort und **addiert** beim Upsert. Das hat einen Bug:
stimmt `hash8k` nicht mehr, wird die Datei komplett neu gelesen, und eine rotierte und
wiederverwendete Datei zählt doppelt.

Der Merge übernimmt page-metrics' Modell: Datumsbereich vollständig neu lesen, **Replace**-Upsert.
Das ist idempotent und eliminiert die Fehlerklasse. `byte_offset` und `hash8k` entfallen.

Kosten sind erneutes Parsen. Abgefedert dadurch, dass Dateien mit `mtime < since` gar nicht erst
geöffnet werden.

### 3.4 Ein Log-Durchlauf für Mensch und Bot

Human- und Bot-Metriken stammen aus derselben Logzeile. Zwei getrennte Provider würden dieselben
Dateien zweimal parsen.

Es gibt deshalb **einen** Access-Log-Provider, der in einem Durchlauf beides emittiert. Das
Agent-Detail passt nicht in den `iterable<UrlMetric>`-Rückgabevertrag und bekommt einen expliziten
Beisitzer: `BotAgentDetailCollector` wird in den Provider injiziert, der Provider ruft während der
Iteration `add()`, `ImportCommand` ruft nach Abschluss `flush()`.

Explizite Lebenszyklen statt Seiteneffekten in `fetch()`, testbar mit einem Spy.

### 3.5 `@api`-Verträge bleiben unangetastet

`Resolver` und `MetricProviderInterface` sind semver-gebunden, weil `move-elevator/typo3-pulse` sie
konsumieren soll.

- `MetricProviderInterface` wird **nicht** geändert. Der Collector aus §3.4 wird in den konkreten
  Provider injiziert, nicht ins Interface aufgenommen.
- `Resolver` wird nur **additiv** erweitert, falls bot-inspects Variante Fähigkeiten hat, die
  page-metrics' Variante fehlen. Zu prüfen ist insbesondere die Behandlung site-spezifischer
  Zusatz-Hosts, die in beiden Implementierungen unterschiedlich dokumentiert ist.
- `ModifyMetricTilesEvent` bleibt bestehen, obwohl sein einziger Konsument nach dem Merge intern
  wird. Er ist der öffentliche Erweiterungspunkt für Dritte.

## 4. Datenmodell

Die fünf bestehenden Tabellen bleiben unverändert. Genau eine kommt hinzu.

### 4.1 Neue Tabelle

```sql
CREATE TABLE tx_typo3pagemetrics_bot_agent_daily (
    uid              int(11) unsigned NOT NULL auto_increment,
    date             date NOT NULL,
    site_identifier  varchar(255) DEFAULT '' NOT NULL,
    page_uid         int(11) unsigned DEFAULT '0' NOT NULL,
    sys_language_uid int(11) DEFAULT '0' NOT NULL,
    bucket           varchar(20) DEFAULT '' NOT NULL,
    agent            varchar(255) DEFAULT '' NOT NULL,
    agent_hash       char(40) DEFAULT '' NOT NULL,
    hits             int(11) unsigned DEFAULT '0' NOT NULL,
    hits_suspicious  int(11) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY fact (date,site_identifier,page_uid,sys_language_uid,bucket,agent_hash),
    KEY site_date (site_identifier,date),
    KEY page_date (page_uid,sys_language_uid,date)
);
```

`agent_hash` ist `sha1(agent)` und hält den Unique Key schlank, mit Präzedenz in bot-inspects
`url_daily`. Der Key landet bei rund 1280 Bytes.

`page_uid = 0` markiert die site-weite Rollup-Zeile. Diese ist **nicht** aus den Seitenzeilen
ableitbar, und genau das ist der Grund, warum bot-inspect heute zwei Agent-Tabellen hat: Bot-Hits
auf nicht auflösbare URLs zählen site-weit mit, haben aber kein `page_uid`.

### 4.2 Schreibregeln pro klassifizierter Bot-Zeile

1. Sobald eine Site zugeordnet werden konnte, die Rollup-Zeile `(date, site, 0, 0, bucket, agent)`.
   Das gilt auch dann, wenn die URL auf keine Seite auflöst, also bei `reason` `file`, `non-page`
   oder `unknown`, denn dort war der Site-Match erfolgreich und nur der Slug-Lookup nicht.
2. Bei aufgelöster Seite zusätzlich `(date, site, pageUid, langUid, bucket, agent)`.
3. Bei aufgelöster Seite die Metrik `bot.<bucket>` in `tx_typo3pagemetrics_daily`.
4. Bei nicht aufgelöster URL ein Eintrag in `tx_typo3pagemetrics_unresolved` mit `reason`.
5. `reason = foreign-host` fällt als einziger Fall aus der Agent-Tabelle heraus, weil dort kein
   Site-Match zustande kam. Diese Treffer bleiben über `_unresolved` sichtbar.

Upsert ist durchgehend **Replace**, konsistent zu `DailyMetricsRepository`.

Die Differenz zwischen Rollup-Zeile und Summe der Seitenzeilen ist damit exakt der Anteil
Bot-Traffic, der auf keine Seite führt. Das ist die Kennzahl, um die es bot-inspect im Kern geht.

### 4.3 Redirect-Anteil entsteht kostenlos

`ImportCommand` schreibt heute bei `ResolvedUrl::$viaRedirect` automatisch eine zweite Zeile mit
`dimension = 'via_redirect'`. Weil die Bucket-Metriken denselben Pfad nehmen, gilt das auch für sie.

Damit liefert die bestehende Redirect-Share-Mechanik ohne Zusatzarbeit genau die Aufschlüsselung,
die bot-inspects Ausgangsmessung motiviert hat: wie viel Bot-Traffic nicht direkt ankommt.

## 5. Komponenten-Inventar

### 5.1 Wandert weitgehend unverändert

`BotTaxonomy`, `AgentMatcher`, `Bucket`, `Classification`, die `AGENT_DESCRIPTIONS`-Map,
`PathHeuristicGuard` mit `SpoofingGuardInterface`, `LogFileReader`, `SourceLocator`,
`BotMetricsCalculator`, `ChartDataBuilder`, `ViewDataBuilder`, `PageOverviewBuilder`, `AiHitsWidget`,
sowie `AnalyzeCommand` als eigenständiger DB-freier Befehl.

### 5.2 Entfällt, weil page-metrics es bereits hat

| bot-inspect | ersetzt durch |
|---|---|
| `PageUrlResolver`, `RedirectResolver`, `ResolutionResult`, `UnresolvedReason` | `Resolver`, `ResolvedUrl`, `UnresolvedUrl`, `UnresolvedReason` |
| `CombinedLogParser`, `LogLine` | `AccessLogLineParser`, `AccessLogEntry`, erweitert um optionales `%v`-Vhost-Präfix |
| `url_daily` samt Repository und Aggregate | `tx_typo3pagemetrics_unresolved` |
| `page_daily`, `agent_daily`, `page_agent_daily` | `_daily` (Bucket-Ebene) plus `_bot_agent_daily` |
| `tx_typo3botinspect_import`, `byte_offset`, `hash8k` | `tx_typo3pagemetrics_import` |
| `BotMetricsServiceInterface`, `BotMetricsService`, DTO | intern, das `@api`-Interface entfällt |
| `BotTrafficTileEventListener` | regulärer Provider-Beitrag |
| `MissingTargetsWidget` | `NotFoundPagesWidget`, ergänzt um einen Filter auf die `metric`-Spalte |

`tx_typo3pagemetrics_unresolved` hat keine `bucket`-Spalte und braucht auch keine: der Bucket steckt
bereits in `metric` als `bot.<bucket>`. Eine Bot-Sicht ist deshalb ein Filter `metric LIKE 'bot.%'`,
eine einzelne Bucket-Sicht ein exakter Metrikvergleich. Die Tabelle bleibt unverändert.

### 5.3 Entfällt in page-metrics

`BotFilter`. Ersetzt durch `BotTaxonomy::classify() !== null`, damit existiert genau eine
Bot-Signaturliste.

### 5.4 Neu

- `BotAgentDetailCollector` samt `BotAgentDailyRepository`
- Fünf `MetricDefinition`-Einträge für die Buckets
- Backend-Module gemäß §7

### 5.5 Paket

Extension Key `typo3_page_metrics` und Paketname `konradmichalik/typo3-page-metrics` bleiben.
bot-inspect wird archiviert. `typo3/cms-dashboard` geht von `require` auf `suggest`.

## 6. Datenfluss

`ImportCommand` bleibt der einzige Treiber. Pro Site und Provider: Datumsbereich aus
`ImportStateRepository`, `fetch()` iterieren, URL auflösen, akkumulieren, `_daily` upserten,
Unaufgelöstes nach `_unresolved`, danach `collector->flush()`, zum Schluss `markImported(gestern)`.

Im Provider: `SourceLocator` globt `pathPattern`, Dateien mit `mtime < since` werden nicht geöffnet,
`LogFileReader` liefert Zeilen inklusive transparentem gz, `AccessLogLineParser` parst, dann
`BotTaxonomy::classify()`.

- `null` bedeutet Mensch und erzeugt `accesslog.pageviews` sowie `accesslog.visits`.
- Nicht-`null` erzeugt `bot.<bucket>` als `UrlMetric` und zusätzlich `collector->add(...)`.

Der Collector aggregiert nach `(date, url, bucket, agent)` bereits beim Sammeln, nicht pro Zeile,
und löst die URLs erst beim `flush()` auf. Das ist billig, weil `Resolver` seinen In-Memory-Cache
nach normalisierter URL hält und die Auflösung dort bereits stattgefunden hat.

**Invariante:** zwischen Iteration und Flush darf kein `Resolver::reset()` laufen.

Bei sehr großen Logs ist der Collector der Speicher-Hotspot. Ausweg ist ein periodischer Flush pro
Datum.

## 7. Backend-Module

Ein Hauptmodul mit zwei Geschwister-Submodulen, beide unter dem Seitenbaum. Die gewählte Seite
bestimmt die Ansicht: Site-Wurzel zeigt die site-weite Sicht, jede andere Seite die Seiten-Sicht.
Damit entfällt ein eigener Site-Selektor.

Ein Nebeneffekt der neuen Tabelle: weil `_bot_agent_daily` ein `site_identifier` führt, das
bot-inspects `agent_daily` nicht hatte, wird das Bot-Modul erstmals site-fähig. Es ist heute
installationsweit.

| Baustein | Bot | Human |
|---|---|---|
| Controller | `BotController` | `OverviewController` |
| Site-Sicht | `ViewDataBuilder` (vorhanden) | neu |
| Seiten-Sicht | `PageOverviewBuilder` (vorhanden) | neu |
| Chart-Mathematik | `Backend/Chart/ChartDataBuilder` | derselbe |
| Zeitraum 7/30/90 | derselbe Selektor | derselbe Selektor |

**Human-Modul, Site-Sicht:** KPI-Zeile, Trend-Chart, vollständige Top-Pages-Tabelle mit Titeln,
unaufgelöste URLs gruppiert nach `reason`, Annotationen. Inhaltlich das, was die Dashboard-Widgets
zeigen, nur ungekürzt.

**Human-Modul, Seiten-Sicht:** KPI-Zeile und Trend-Chart für die gewählte Seite, mit dem
Zeitraumselektor des Moduls.

**Bot-Modul, Site-Sicht:** KPI pro Bucket, Trend, Top-N-Agent-Tabelle, Donut, Spoofing-Warnung,
unaufgelöste Bot-URLs als Sicht auf `_unresolved` mit Filter `metric LIKE 'bot.%'` (§5.2).

**Bot-Modul, Seiten-Sicht:** KPI pro Bucket und Agent-Tabelle für die gewählte Seite. Dies ist der
Konsument der `page_uid != 0`-Zeilen aus §4.1.

Die Seiten-Sicht überschneidet sich bewusst mit den Tiles im Web-&gt;-Page-Header. Der Header ist der
Blick im Bearbeitungskontext, das Modul die Analyse mit Zeitraumwahl und Chart. bot-inspect hat
diese Doppelung mit Tile-Listener und `PageOverviewBuilder` heute bereits.

**Voraussetzung:** Die Datenaufbereitung der Widgets `TopPagesWidget`, `NotFoundPagesWidget`,
`TrendWidget` und `AnnotationsWidget` muss in eigene Builder ausgelagert werden, damit Widget und
Modul denselben Code nutzen. Ohne diesen Schritt entsteht die dritte Kopie derselben Logik.

## 8. Fehlerbehandlung

- Unparsbare Zeilen werden übersprungen, aber **gezählt und am Ende ausgegeben**. Heute verschwinden
  sie lautlos, was ein falsches Logformat unsichtbar macht.
- Nicht lesbare Dateien aus dem Glob warnen und brechen den Lauf nicht ab.
- Fehlendes `cms-redirects` wird wie bisher geschluckt.
- Hop-Limit fünf und Cycle-Guard bleiben unverändert.
- **Neu:** Der Import pro Provider und Site läuft in einer Transaktion zusammen mit `markImported`.
  bot-inspect macht das bereits, page-metrics nicht. Ohne das hinterlässt ein Absturz halb
  geschriebene Aggregate hinter einem bereits hochgesetzten Importstand.

## 9. Tests

**Zusammenführen:** Taxonomy-, Guard- und Parser-Tests, letztere erweitert um `%v`-Fälle. Beide
Resolver-Testsuiten werden auf den verbleibenden `Resolver` gerichtet. Das deckt voraussichtlich
Lücken auf beiden Seiten auf.

**Neu:**

- Unit-Test für `BotAgentDetailCollector` mit Spy
- Functional-Test für `BotAgentDailyRepository` auf Upsert-Idempotenz
- End-to-End-Test des `ImportCommand` gegen ein Fixture-Log mit Mensch, allen fünf Buckets,
  unaufgelöster URL und Redirect
- Test, dass die Rollup-Zeile auch Hits auf nicht auflösbare URLs enthält, die Seitenzeilen aber nicht

**Der zentrale Regressionstest:** `ImportCommand` zweimal auf demselben Fixture laufen lassen und
identische Zeilenzahlen und Werte behaupten. Das ist der Beweis, dass die Replace-Semantik den
Doppelzähl-Bug aus §3.3 erledigt hat.

## 10. Sequenzierung

Der Merge und das Human-Modul sind zwei Arbeitspakete. Das erste ist in sich lieferbar.

1. **Pipeline-Merge:** Taxonomy, Guard, Parser mit `%v`, Resolver-Konsolidierung, `BotFilter` raus,
   neue Tabelle, Collector, Provider-Erweiterung, Import-Transaktion, Bucket-Metriken samt Tiles.
2. **Bot-Modul portieren:** Controller, Builder, Chart, Widgets, jetzt site-fähig.
3. **Widget-Builder extrahieren:** Voraussetzung für Schritt 4, für sich genommen bereits eine
   Verbesserung.
4. **Human-Modul:** Site- und Seiten-Sicht auf den Buildern aus Schritt 3.
5. **Aufräumen:** `maxDimensions` entfernen, bot-inspect archivieren, Doku angleichen.

## 11. Offene Punkte

- Die Behandlung site-spezifischer Zusatz-Hosts ist in den beiden Resolvern unterschiedlich
  dokumentiert. Vor der Konsolidierung ist zu prüfen, welche Variante mehr kann, damit der Umstieg
  additiv bleibt (§3.5).
- Die Obergrenze für die Top-N-Agent-Tabelle ist noch nicht festgelegt. bot-inspect kappt
  `url_daily` bei 500 pro Tag und Bucket, für Agents fehlt eine vergleichbare Zahl.
- Ob der Zeitraumselektor 7/30/90 aus dem Bot-Modul auch für das Human-Modul die richtige Staffelung
  ist, sollte an echten Daten geprüft werden.
