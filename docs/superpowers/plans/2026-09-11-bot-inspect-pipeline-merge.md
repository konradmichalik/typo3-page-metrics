# Bot-Inspect Pipeline Merge (Package 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Merge `typo3-bot-inspect`'s log-parsing/bot-classification pipeline into `typo3-page-metrics`, so one log read produces both human page-view metrics and bot-bucket metrics, with agent-level detail in a new table.

**Architecture:** `AccessLogMetricProvider` switches its parser from the page-metrics-only `AccessLogLineParser`/`AccessLogEntry` to bot-inspect's `CombinedLogParser`/`LogLine` (needed anyway because `BotTaxonomy::classify()` takes a `LogLine`), classifies every in-range line via the ported `BotTaxonomy`, and for a classified line yields a `bot.<bucket>` `UrlMetric` (flowing through the existing `Resolver`/`_daily`/`_unresolved` pipeline unchanged) while also handing agent-level detail to a new `BotAgentDetailCollector`, which `ImportCommand` flushes into a new `tx_typo3pagemetrics_bot_agent_daily` table after the existing per-provider upsert loop. `ImportCommand` additionally wraps its persistence step in one DB transaction so a crash can no longer leave `last_imported_date` advanced ahead of unwritten aggregates. `AnalyzeCommand` (a DB-free CLI diagnostic tool) and its own dependency chain (`SourceLocator`, `LogFileReader`, `Aggregator`) are ported verbatim as a self-contained addition.

**Tech Stack:** PHP 8.2+, TYPO3 13.4/14 core, Doctrine DBAL, Symfony Console/DI, PHPUnit (`typo3/testing-framework` for functional tests).

**Spec:** [`docs/superpowers/specs/2026-09-11-bot-inspect-merge-design.md`](../specs/2026-09-11-bot-inspect-merge-design.md) — this plan implements only §10's Package 1 ("Pipeline-Merge"); the backend modules (Package 2), widget-builder extraction (Package 3), and the human module (Package 4) are deliberately out of scope for this plan and PR, each self-contained per the spec's own sequencing.

## Global Constraints

- `MetricProviderInterface` (`Classes/Provider/MetricProviderInterface.php`) is `@api` and MUST NOT change (spec §3.5).
- `Resolver` (`Classes/Resolver/Resolver.php`) is `@api`; it already supports `pageMetrics.resolution.additionalHosts` per-site, a capability bot-inspect's own resolver never had (confirmed by reading `Classes/Resolver/SiteMatcher.php` and `LanguageMatcher.php` — both already read `additionalHosts` from site config). **Spec §11's "additional-hosts" open point is resolved: no resolver change is needed**, page-metrics' `Resolver` is a strict superset.
- `ModifyMetricTilesEvent` is untouched by this plan (it has no bot-inspect consumer left after this merge dissolves that extension; that consumer is bot-inspect's own `BotTrafficTileEventListener`, which is not being ported — the merged module in Package 2 will read directly from the DB instead).
- No data migration: both extensions have zero tags and only dev data (spec §2). Tables are created fresh via `ext_tables.sql`.
- Upsert is always **replace**, never add (spec §3.3) — this is what eliminates bot-inspect's rotated-log double-count bug.
- PHP `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`, copyright header year `2026`, license `GPL-2.0-or-later`, author `Konrad Michalik <hej@konradmichalik.dev>` — copy the exact header block from any existing file in this repo (e.g. `Classes/Provider/MetricProviderInterface.php:1-12`) verbatim into every new file.
- House style established throughout this codebase: `declare(strict_types=1)`, `final` classes and `readonly` value objects wherever nothing needs to subclass or mock them, grouped `use` imports, function imports (`use function ...;`) for global functions used more than once, and prose docblocks that explain **why**, not what — match the voice of the files you are editing.
- No `composer.json` changes are needed for this plan: page-metrics already has `ext-zlib` and already lists `typo3/cms-dashboard` under `suggest`, not `require` (bot-inspect's `require` on it does not carry over because bot-inspect is not being merged as a package, only its `Classes/` content is being ported). None of the ported code uses `ext-mbstring` functions (confirmed: `AgentMatcher::match()` uses `stripos`, not `mb_stripos`).

---

### Task 1: Port the Analysis package (Taxonomy, Guard, Parser)

**Files:**
- Create: `Classes/Analysis/Taxonomy/Bucket.php`
- Create: `Classes/Analysis/Taxonomy/Classification.php`
- Create: `Classes/Analysis/Taxonomy/AgentMatcher.php`
- Create: `Classes/Analysis/Taxonomy/BotTaxonomy.php`
- Create: `Classes/Analysis/Guard/SpoofingGuardInterface.php`
- Create: `Classes/Analysis/Guard/PathHeuristicGuard.php`
- Create: `Classes/Analysis/Parser/LogLine.php`
- Create: `Classes/Analysis/Parser/CombinedLogParser.php`
- Create: `Classes/Analysis/Parser/LogParserInterface.php`
- Create: `Tests/Unit/Analysis/Taxonomy/BucketTest.php` (only if no equivalent exists — see Step 1)
- Create: `Tests/Unit/Analysis/Taxonomy/AgentMatcherTest.php`
- Create: `Tests/Unit/Analysis/Taxonomy/BotTaxonomyTest.php`
- Create: `Tests/Unit/Analysis/Guard/PathHeuristicGuardTest.php`
- Create: `Tests/Unit/Analysis/Parser/CombinedLogParserTest.php` (only if a source test exists — see Step 1)
- Modify: `Configuration/Services.yaml`

**Interfaces:**
- Produces: `KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket` (string enum: `AiChatbot='ai_chatbot'`, `AiCrawler='ai_crawler'`, `Search='search'`, `Social='social'`, `Tool='tool'`), `Classification{public Bucket $bucket, public string $agent}`, `AgentMatcher::match(string $userAgent, array $needles): ?string`, `BotTaxonomy::classify(LogLine $line): ?Classification`, `BotTaxonomy::describe(string $agent): ?string` (static), `SpoofingGuardInterface::isSuspicious(LogLine $line): bool`, `PathHeuristicGuard implements SpoofingGuardInterface`, `LogLine{public string $ip, public DateTimeImmutable $timestamp, public ?string $method, public string $target, public ?string $protocol, public int $status, public ?int $bytesSent, public ?string $referer, public string $userAgent, public ?string $host}` plus `LogLine::path(): string`, `LogParserInterface::parseLine(string $line): ?LogLine`, `CombinedLogParser implements LogParserInterface`.
- This entire subtree is framework-free (no TYPO3 dependency) except where DI wiring in Services.yaml registers it — preserve that property, it is what makes it usable from both the DB-backed provider (Task 6) and the DB-free `AnalyzeCommand` (Task 8).

This is a **mechanical port**: the source files already exist, are already tested, and their behavior must not change — only the namespace changes. Do not rewrite any logic.

- [ ] **Step 1: Inventory the exact source files to port**

Run:
```bash
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Classes/Analysis/Taxonomy -type f
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Classes/Analysis/Guard -type f
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Classes/Analysis/Parser -type f
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Tests/Unit/Analysis -type f
```
Expected: `Taxonomy/{Bucket,Classification,AgentMatcher,BotTaxonomy}.php`, `Guard/{SpoofingGuardInterface,PathHeuristicGuard}.php`, `Parser/{LogLine,CombinedLogParser,LogParserInterface}.php`, and whichever `Tests/Unit/Analysis/{Taxonomy,Guard,Parser}/*Test.php` files exist (confirmed to exist for `Taxonomy/{AgentMatcherTest,BotTaxonomyTest}.php` and `Guard/PathHeuristicGuardTest.php`; check whether `Parser/*Test.php` or `Taxonomy/BucketTest.php` exist too — port whatever is found, skip the create steps above for whichever do not).

- [ ] **Step 2: Copy each file, adapt namespace and copyright**

For each of the 9 non-test files listed under **Files**, copy the bot-inspect source verbatim into the new page-metrics path, then apply exactly these two substitutions to the copied file:
1. Namespace: `KonradMichalik\Typo3BotInspect\Analysis\` → `KonradMichalik\Typo3PageMetrics\Analysis\` (in both the `namespace` line and any `use KonradMichalik\Typo3BotInspect\Analysis\...` import lines within the same subtree, e.g. `BotTaxonomy.php`'s `use KonradMichalik\Typo3BotInspect\Analysis\Parser\LogLine;` becomes `use KonradMichalik\Typo3PageMetrics\Analysis\Parser\LogLine;`).
2. File-header comment: `* This file is part of the "typo3_bot_inspect" TYPO3 CMS extension.` → `* This file is part of the "typo3_page_metrics" TYPO3 CMS extension.` (matching every other file in this repo).

Do not change anything else — not the class bodies, not the docblocks, not the `@author`/copyright lines (`(c) 2026 Konrad Michalik <hej@konradmichalik.dev>` and `GPL-2.0-or-later` are identical between the two extensions already).

For each test file found in Step 1, copy it into the corresponding `Tests/Unit/Analysis/...` path with the same two substitutions applied (plus `KonradMichalik\Typo3BotInspect\Tests\` → `KonradMichalik\Typo3PageMetrics\Tests\` in the test's own namespace and any fixture imports).

- [ ] **Step 3: Run the ported tests to verify the port preserved behavior**

Run: `vendor/bin/phpunit -c phpunit.xml --filter Analysis`
Expected: every ported test PASSes, with the exact same assertions as the bot-inspect original (they are unmodified files, only relocated).

- [ ] **Step 4: Register `SpoofingGuardInterface` → `PathHeuristicGuard` in DI**

Everything else in `Classes/Analysis/` is a concrete class and autowires fine under the blanket `resource: '../Classes/*'` entry already in `Configuration/Services.yaml`. `SpoofingGuardInterface` is the one interface-typed dependency (used by `AnalyzeCommand` in Task 8, and optionally by `AccessLogMetricProvider` in Task 6) — Symfony's autowiring does not resolve an interface to its sole implementation without an explicit alias.

Add, alphabetically among the other explicit entries in `Configuration/Services.yaml` (after the `_defaults`/exclude block, before the first `public: true` entry):

```yaml
    KonradMichalik\Typo3PageMetrics\Analysis\Guard\SpoofingGuardInterface:
        alias: KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard
```

- [ ] **Step 5: Commit**

```bash
git add Classes/Analysis Tests/Unit/Analysis Configuration/Services.yaml
git commit -m "feat: port bot-inspect's taxonomy, spoofing guard and log parser"
```

---

### Task 2: Add the `tx_typo3pagemetrics_bot_agent_daily` table

**Files:**
- Modify: `ext_tables.sql`

**Interfaces:**
- Produces: table `tx_typo3pagemetrics_bot_agent_daily` with columns `uid, date (DATE), site_identifier (varchar 255), page_uid (int unsigned), sys_language_uid (int), bucket (varchar 20), agent (varchar 255), agent_hash (char 40), hits (int unsigned), hits_suspicious (int unsigned)`, unique key `fact` on `(date, site_identifier, page_uid, sys_language_uid, bucket, agent_hash)`. Consumed by Task 3's `BotAgentDailyRepository`.

There is no test to write for a schema-only change; verification is that TYPO3's schema migration (run implicitly by the functional test bootstrap in later tasks) succeeds.

- [ ] **Step 1: Append the table definition**

Add this block to `ext_tables.sql`, after the existing `tx_typo3pagemetrics_annotation` table and before the `tx_typo3pagemetrics_manual_annotation` comment block (keeping the existing file's tab-indentation style):

```sql
CREATE TABLE tx_typo3pagemetrics_bot_agent_daily (
	uid int(11) unsigned NOT NULL auto_increment,
	date date NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	page_uid int(11) unsigned DEFAULT '0' NOT NULL,
	sys_language_uid int(11) DEFAULT '0' NOT NULL,
	bucket varchar(20) DEFAULT '' NOT NULL,
	agent varchar(255) DEFAULT '' NOT NULL,
	agent_hash char(40) DEFAULT '' NOT NULL,
	hits int(11) unsigned DEFAULT '0' NOT NULL,
	hits_suspicious int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY site_date (site_identifier,date),
	KEY page_date (page_uid,sys_language_uid,date),
	UNIQUE KEY fact (date,site_identifier,page_uid,sys_language_uid,bucket,agent_hash)
);
```

- [ ] **Step 2: Commit**

```bash
git add ext_tables.sql
git commit -m "feat: add tx_typo3pagemetrics_bot_agent_daily table"
```

---

### Task 3: `BotAgentDailyRepository`

**Files:**
- Create: `Classes/Persistence/BotAgentDailyRepository.php`
- Create: `Tests/Functional/Persistence/BotAgentDailyRepositoryTest.php`
- Modify: `Configuration/Services.yaml`

**Interfaces:**
- Consumes: nothing new (Doctrine `ConnectionPool`, already used identically by `DailyMetricsRepository`, `Classes/Persistence/DailyMetricsRepository.php:35-37`).
- Produces: `BotAgentDailyRepository::upsert(string $siteIdentifier, int $pageUid, int $languageUid, string $bucket, string $agent, DateTimeImmutable $date, int $hits, int $hitsSuspicious): void` — consumed by Task 4's `BotAgentDetailCollector`.

- [ ] **Step 1: Write the failing functional test**

Create `Tests/Functional/Persistence/BotAgentDailyRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Persistence;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Persistence\BotAgentDailyRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * BotAgentDailyRepositoryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BotAgentDailyRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private BotAgentDailyRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(BotAgentDailyRepository::class);
    }

    public function testUpsertInsertsANewRow(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 1);

        self::assertSame(['hits' => 5, 'hits_suspicious' => 1], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testUpsertReplacesRatherThanAddsOnASecondCallForTheSameIdentity(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 1);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 9, 3);

        self::assertSame(['hits' => 9, 'hits_suspicious' => 3], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testUpsertKeepsRowsWithDifferentAgentsSeparateEvenWithColliding32CharPrefixHashes(): void
    {
        // Two distinct agent strings that share the same first bytes are still
        // distinct identities: the unique key includes the full agent_hash
        // (sha1, 40 hex chars), not a truncated prefix, so this is really just
        // confirming two different agents produce two different rows.
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'ClaudeBot', $date, 3, 0);

        self::assertSame(['hits' => 5, 'hits_suspicious' => 0], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
        self::assertSame(['hits' => 3, 'hits_suspicious' => 0], $this->fetchRow('main', 2, 'ai_crawler', 'ClaudeBot'));
    }

    public function testUpsertKeepsThePageZeroRollupRowSeparateFromAPageScopedRow(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->upsert('main', 0, 0, 'ai_crawler', 'GPTBot', $date, 20, 0);
        $this->subject->upsert('main', 2, 0, 'ai_crawler', 'GPTBot', $date, 5, 0);

        self::assertSame(['hits' => 20, 'hits_suspicious' => 0], $this->fetchRow('main', 0, 'ai_crawler', 'GPTBot'));
        self::assertSame(['hits' => 5, 'hits_suspicious' => 0], $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot'));
    }

    /**
     * @return array{hits: int, hits_suspicious: int}
     */
    private function fetchRow(string $siteIdentifier, int $pageUid, string $bucket, string $agent): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $row = $queryBuilder
            ->select('hits', 'hits_suspicious')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter($bucket)),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter($agent)),
            )
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return ['hits' => (int) $row['hits'], 'hits_suspicious' => (int) $row['hits_suspicious']];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter BotAgentDailyRepositoryTest`
Expected: FAIL — `Class "KonradMichalik\Typo3PageMetrics\Persistence\BotAgentDailyRepository" not found`.

- [ ] **Step 3: Implement `BotAgentDailyRepository`**

Create `Classes/Persistence/BotAgentDailyRepository.php`:

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Persistence;

use DateTimeImmutable;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function sha1;

/**
 * BotAgentDailyRepository.
 *
 * Same select-then-insert-or-update upsert style as DailyMetricsRepository,
 * for the same reason (see that class's docblock) - and the same replace,
 * never add, semantics: a rotated log re-imported after a hash change must
 * land on the same total, not double-count on top of it.
 *
 * agent_hash (sha1 of the agent string) carries the identity instead of the
 * agent column itself, keeping the unique index narrow regardless of how
 * long a given agent string is - agent itself is stored purely as display
 * payload, updated alongside hits/hits_suspicious on every upsert() so a
 * hash collision (astronomically unlikely for this cardinality, but not
 * impossible) still self-corrects to the most recently written spelling.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class BotAgentDailyRepository
{
    private const TABLE = 'tx_typo3pagemetrics_bot_agent_daily';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function upsert(string $siteIdentifier, int $pageUid, int $languageUid, string $bucket, string $agent, DateTimeImmutable $date, int $hits, int $hitsSuspicious): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identity = [
            'date' => $date->format('Y-m-d'),
            'site_identifier' => $siteIdentifier,
            'page_uid' => $pageUid,
            'sys_language_uid' => $languageUid,
            'bucket' => $bucket,
            'agent_hash' => sha1($agent),
        ];

        $existingUid = $connection->select(['uid'], self::TABLE, $identity)->fetchOne();

        if (false !== $existingUid) {
            $connection->update(self::TABLE, ['agent' => $agent, 'hits' => $hits, 'hits_suspicious' => $hitsSuspicious], ['uid' => $existingUid]);

            return;
        }

        $connection->insert(self::TABLE, array_merge($identity, ['agent' => $agent, 'hits' => $hits, 'hits_suspicious' => $hitsSuspicious]));
    }
}
```

- [ ] **Step 4: Register as public for functional test access**

Add to `Configuration/Services.yaml`, grouped with the other repository entries (after `ManualAnnotationRepository`, before `SitePageUidRepository`, alphabetically consistent with the existing block):

```yaml
    # Public so functional tests can fetch it directly via $this->get() before
    # any consuming service references it.
    KonradMichalik\Typo3PageMetrics\Persistence\BotAgentDailyRepository:
        public: true
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter BotAgentDailyRepositoryTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Persistence/BotAgentDailyRepository.php Tests/Functional/Persistence/BotAgentDailyRepositoryTest.php Configuration/Services.yaml
git commit -m "feat: add BotAgentDailyRepository"
```

---

### Task 4: `BotAgentDetailCollector`

**Files:**
- Create: `Classes/Service/BotAgentDetailCollector.php`
- Create: `Tests/Functional/Service/BotAgentDetailCollectorTest.php`
- Modify: `Configuration/Services.yaml`

**Interfaces:**
- Consumes: `Resolver::resolve(string $url, bool $dropQueryString, array $keepQueryParams): ResolvedUrl|UnresolvedUrl` (`Classes/Resolver/Resolver.php:56`), `BotAgentDailyRepository::upsert(...)` (Task 3).
- Produces: `BotAgentDetailCollector::add(DateTimeImmutable $date, string $url, Bucket $bucket, string $agent, bool $suspicious): void` and `BotAgentDetailCollector::flush(string $siteIdentifier, bool $dropQueryString, array $keepQueryParams): void` — consumed by Task 6 (`add()`, from `AccessLogMetricProvider`) and Task 7 (`flush()`, from `ImportCommand`).
- **Not `final`**: mirrors the documented reason `AccessLogConfigurationResolver` is not final (`Classes/Provider/AccessLog/AccessLogConfigurationResolver.php:27-29`) — Task 6's unit test mocks this collaborator, and PHPUnit cannot generate a test double for a final class. `Resolver` itself IS final and therefore cannot be mocked, which is exactly why this class's own test (this task) must be a functional test against a real `Resolver`, not a unit test.

Why a page-zero row always gets written for every non-`ForeignHost` result, and a page-scoped row is written in addition only when resolved: spec §4.2 rules 1–2 — the gap between the rollup total and the sum of page rows is deliberately visible (spec §4.3) as the volume of bot traffic that matched a site but landed on no real page.

- [ ] **Step 1: Write the failing functional test**

Create `Tests/Functional/Service/BotAgentDetailCollectorTest.php`:

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * BotAgentDetailCollectorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class BotAgentDetailCollectorTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['redirects'];
    protected array $testExtensionsToLoad = ['konradmichalik/typo3-page-metrics'];

    private BotAgentDetailCollector $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/Fixtures/pages.csv');
        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [0 => ['title' => 'English', 'enabled' => true, 'languageId' => 0, 'base' => '/', 'locale' => 'en_US.UTF-8', 'navigationTitle' => 'English', 'flag' => 'us']],
            'errorHandling' => [],
            'routes' => [],
            'pageMetrics' => ['enabled' => true, 'retentionDays' => 5],
        ]);
        $this->subject = $this->get(BotAgentDetailCollector::class);
    }

    public function testFlushWritesBothARollupRowAndAPageScopedRowForAResolvedUrl(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);

        $this->subject->flush('main', true, []);

        self::assertSame(1, $this->countRows('main', 0, 'ai_crawler', 'GPTBot'));
        self::assertSame(1, $this->countRows('main', 2, 'ai_crawler', 'GPTBot'));
    }

    public function testFlushWritesOnlyTheRollupRowForANonPageUrl(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/does-not-exist', Bucket::Tool, 'AhrefsBot', false);

        $this->subject->flush('main', true, []);

        self::assertSame(1, $this->countRows('main', 0, 'tool', 'AhrefsBot'));
        self::assertSame(0, $this->countRows('main', 2, 'tool', 'AhrefsBot'));
    }

    public function testFlushWritesNoRowAtAllForAForeignHost(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://not-this-site.example/about', Bucket::Search, 'Googlebot', false);

        $this->subject->flush('main', true, []);

        $total = (int) $this->get(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily')
            ->count('uid')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->executeQuery()
            ->fetchOne();

        self::assertSame(0, $total);
    }

    public function testFlushAggregatesMultipleAddCallsForTheSameIdentityBeforeWriting(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', true);
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);

        $this->subject->flush('main', true, []);

        $row = $this->fetchRow('main', 2, 'ai_crawler', 'GPTBot');
        self::assertSame(3, $row['hits']);
        self::assertSame(1, $row['hits_suspicious']);
    }

    public function testFlushClearsAccumulatedStateSoASecondFlushWithNoNewAddsWritesNothingNew(): void
    {
        $date = new DateTimeImmutable('2026-10-10');
        $this->subject->add($date, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot', false);
        $this->subject->flush('main', true, []);

        $this->subject->flush('main', true, []);

        self::assertSame(1, $this->countRows('main', 2, 'ai_crawler', 'GPTBot'));
    }

    private function countRows(string $siteIdentifier, int $pageUid, string $bucket, string $agent): int
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');

        return (int) $queryBuilder
            ->count('uid')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter($bucket)),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter($agent)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array{hits: int, hits_suspicious: int}
     */
    private function fetchRow(string $siteIdentifier, int $pageUid, string $bucket, string $agent): array
    {
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $row = $queryBuilder
            ->select('hits', 'hits_suspicious')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter($bucket)),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter($agent)),
            )
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);

        return ['hits' => (int) $row['hits'], 'hits_suspicious' => (int) $row['hits_suspicious']];
    }
}
```

Reuse the existing pages fixture (`Tests/Functional/Command/Fixtures/pages.csv`) rather than creating a new one: copy it to `Tests/Functional/Service/Fixtures/pages.csv` so this test's fixture directory is self-contained, matching the convention already used per-directory elsewhere in `Tests/Functional/`.

Run first: `find /Users/k.michalik/Sites/Packages/typo3-page-metrics/Tests/Functional/Command/Fixtures/pages.csv` to confirm its path, then `cp` it into the new location and inspect it to confirm page uid `2` really is `/about` under the `main` site's root — if the fixture uses different slugs/uids, adjust this test's URLs/uids to match the fixture rather than the other way around.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter BotAgentDetailCollectorTest`
Expected: FAIL — `Class "KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector" not found`.

- [ ] **Step 3: Implement `BotAgentDetailCollector`**

Create `Classes/Service/BotAgentDetailCollector.php`:

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Service;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\ResolvedUrl;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\UnresolvedReason;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\UnresolvedUrl;
use KonradMichalik\Typo3PageMetrics\Persistence\BotAgentDailyRepository;
use KonradMichalik\Typo3PageMetrics\Resolver\Resolver;

use function implode;

/**
 * BotAgentDetailCollector.
 *
 * The agent-level sidecar to AccessLogMetricProvider's own bucket-level
 * bot.<bucket> UrlMetric emissions (see that class): agent detail does not
 * fit UrlMetric's (date, url, metric, value) shape (spec §3.4), so it is
 * collected here during fetch() via add() and written by ImportCommand via
 * flush(), once the whole provider's lines have been read.
 *
 * Aggregates in memory first, by (date, url, bucket, agent), rather than
 * resolving and writing on every add() call: the same URL/bucket/agent
 * combination recurs many times across a real log, and folding those
 * repeats down to one resolve() call plus one upsert() per combination is
 * both cheaper and matches DailyMetricsRepository's own "aggregate before
 * upsert" rule (see ImportCommand::importOneProviderForSite()'s docblock).
 *
 * Resolution happens here, not by AccessLogMetricProvider itself, and reuses
 * the same shared Resolver instance ImportCommand's own resolution loop
 * already warmed for these exact URLs earlier in the same run - Resolver's
 * own per-instance cache (keyed by normalized URL) makes the repeat
 * resolution in flush() an O(1) cache hit, not a second real resolution.
 *
 * Not final: AccessLogMetricProviderTest mocks this collaborator in a pure
 * unit test (see AccessLogConfigurationResolver's docblock for the same
 * reasoning) - PHP cannot generate a test double for a final class.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
class BotAgentDetailCollector
{
    /**
     * @var array<string, array{date: DateTimeImmutable, url: string, bucket: Bucket, agent: string, hits: int, hitsSuspicious: int}>
     */
    private array $aggregated = [];

    public function __construct(
        private readonly Resolver $resolver,
        private readonly BotAgentDailyRepository $repository,
    ) {}

    public function add(DateTimeImmutable $date, string $url, Bucket $bucket, string $agent, bool $suspicious): void
    {
        $key = implode('|', [$date->format('Y-m-d'), $url, $bucket->value, $agent]);

        $this->aggregated[$key] ??= [
            'date' => $date,
            'url' => $url,
            'bucket' => $bucket,
            'agent' => $agent,
            'hits' => 0,
            'hitsSuspicious' => 0,
        ];

        ++$this->aggregated[$key]['hits'];
        if ($suspicious) {
            ++$this->aggregated[$key]['hitsSuspicious'];
        }
    }

    /**
     * @param list<string> $keepQueryParams
     */
    public function flush(string $siteIdentifier, bool $dropQueryString, array $keepQueryParams): void
    {
        foreach ($this->aggregated as $row) {
            $result = $this->resolver->resolve($row['url'], $dropQueryString, $keepQueryParams);

            if ($result instanceof UnresolvedUrl && UnresolvedReason::ForeignHost === $result->reason) {
                continue;
            }

            $this->repository->upsert($siteIdentifier, 0, 0, $row['bucket']->value, $row['agent'], $row['date'], $row['hits'], $row['hitsSuspicious']);

            if ($result instanceof ResolvedUrl) {
                $this->repository->upsert($siteIdentifier, $result->pageUid, $result->languageUid, $row['bucket']->value, $row['agent'], $row['date'], $row['hits'], $row['hitsSuspicious']);
            }
        }

        $this->aggregated = [];
    }
}
```

- [ ] **Step 4: Register as public for functional test access**

Add to `Configuration/Services.yaml`, near the other `Service\` entries:

```yaml
    # Public so functional tests can fetch it directly via $this->get() before
    # any consuming service references it.
    KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector:
        public: true
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter BotAgentDetailCollectorTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add Classes/Service/BotAgentDetailCollector.php Tests/Functional/Service/BotAgentDetailCollectorTest.php Tests/Functional/Service/Fixtures/pages.csv Configuration/Services.yaml
git commit -m "feat: add BotAgentDetailCollector"
```

---

### Task 5: Delete `BotFilter`, `AccessLogLineParser`, `AccessLogEntry`

**Files:**
- Delete: `Classes/Provider/AccessLog/BotFilter.php`
- Delete: `Classes/Provider/AccessLog/AccessLogLineParser.php`
- Delete: `Classes/Provider/AccessLog/AccessLogEntry.php`
- Delete: `Tests/Unit/Provider/AccessLog/BotFilterTest.php`
- Delete: `Tests/Unit/Provider/AccessLog/AccessLogLineParserTest.php`

**Interfaces:**
- Nothing else in the codebase references these three classes outside their own tests and `AccessLogMetricProvider` (confirmed by `grep -rn "Provider\\\\AccessLog\\\\BotFilter\|AccessLogLineParser\|AccessLogEntry" Classes Tests Configuration docs`) — `AccessLogMetricProvider`'s own reference is rewritten in Task 6, in the same commit as this deletion (splitting them would leave an intermediate broken state).

Superseded by: `BotTaxonomy::classify()` (Task 1) replaces `BotFilter::isBot()` with five-bucket classification instead of a binary flag; `CombinedLogParser`/`LogLine` (Task 1) replace `AccessLogLineParser`/`AccessLogEntry` because `BotTaxonomy::classify()` requires a `LogLine`, and `CombinedLogParser`'s pattern is a strict superset (handles backslash-escaped quotes inside the request line/referrer/user-agent fields, which `AccessLogLineParser`'s simpler pattern does not).

This task has no independent test step — it is folded into Task 6 (both the deletion and `AccessLogMetricProvider`'s rewrite land in one commit, since deleting these three files without Task 6's changes would leave `AccessLogMetricProvider` referencing classes that no longer exist).

- [ ] **Step 1: Delete the five files**

```bash
git rm Classes/Provider/AccessLog/BotFilter.php Classes/Provider/AccessLog/AccessLogLineParser.php Classes/Provider/AccessLog/AccessLogEntry.php Tests/Unit/Provider/AccessLog/BotFilterTest.php Tests/Unit/Provider/AccessLog/AccessLogLineParserTest.php
```

Do not commit yet — this is staged alongside Task 6's changes in that task's own commit step.

---

### Task 6: Rewire `AccessLogMetricProvider`

**Files:**
- Modify: `Classes/Provider/AccessLog/AccessLogMetricProvider.php`
- Modify: `Tests/Unit/Provider/AccessLog/AccessLogMetricProviderTest.php`

**Interfaces:**
- Consumes: `CombinedLogParser::parseLine(string $line): ?LogLine` (Task 1), `BotTaxonomy::classify(LogLine $line): ?Classification` (Task 1), `SpoofingGuardInterface::isSuspicious(LogLine $line): bool` (Task 1), `BotAgentDetailCollector::add(...)` (Task 4).
- Produces: `AccessLogMetricProvider::getMetricDefinitions()` now returns 7 definitions (the existing 2 plus 5 new `bot.<bucket>` ones); `fetch()` now also yields `bot.<bucket>` `UrlMetric`s for classified lines.

This is a **behavior-preserving refactor for the human-metrics path** (pageviews/visits keep their exact existing filtering: GET, 2xx/304, non-static/non-backend path, non-bot user agent) **plus new behavior for the bot-metrics path** (every classified line in range counts, regardless of status/method/path — see the class docblock addition below for why).

- [ ] **Step 1: Update the existing unit test for the new constructor and new bot-emission behavior**

Rewrite `Tests/Unit/Provider/AccessLog/AccessLogMetricProviderTest.php`. Keep every existing test method and its assertions unchanged (the human-metrics behavior is not changing), only change: the `use` imports, `setUp()`'s constructor call, and add new test methods for bot emission. Full replacement content:

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\AccessLog;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\CombinedLogParser;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\{AgentMatcher, BotTaxonomy};
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\AccessLog\{AccessLogConfiguration, AccessLogConfigurationResolver, AccessLogMetricProvider};
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

use function array_map;
use function file_exists;
use function file_put_contents;
use function gzencode;
use function implode;
use function iterator_to_array;
use function mkdir;
use function rmdir;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * AccessLogMetricProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AccessLogMetricProviderTest extends TestCase
{
    /** @var AccessLogConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private AccessLogConfigurationResolver $resolver;

    /** @var BotAgentDetailCollector&\PHPUnit\Framework\MockObject\MockObject */
    private BotAgentDetailCollector $collector;

    private AccessLogMetricProvider $subject;
    private Site $site;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(AccessLogConfigurationResolver::class);
        $this->collector = $this->createMock(BotAgentDetailCollector::class);
        $this->subject = new AccessLogMetricProvider(
            $this->resolver,
            new CombinedLogParser(),
            new BotTaxonomy(new AgentMatcher()),
            new PathHeuristicGuard(),
            $this->collector,
        );
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testGetIdentifierReturnsAccesslog(): void
    {
        self::assertSame('accesslog', $this->subject->getIdentifier());
    }

    public function testGetMetricDefinitionsReturnsPageviewsVisitsAndTheFiveBotBuckets(): void
    {
        $identifiers = array_map(
            static fn ($definition) => $definition->identifier,
            $this->subject->getMetricDefinitions(),
        );

        self::assertSame(
            ['accesslog.pageviews', 'accesslog.visits', 'bot.ai_chatbot', 'bot.ai_crawler', 'bot.search', 'bot.social', 'bot.tool'],
            $identifiers,
        );
    }

    public function testGetRateMetricDefinitionsReturnsNoRateMetrics(): void
    {
        self::assertSame([], $this->subject->getRateMetricDefinitions());
    }

    public function testGetExternalLinkReturnsNull(): void
    {
        $range = new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-01-31'));

        self::assertNull($this->subject->getExternalLink($this->site, 'https://example.com/about', $range));
    }

    public function testSupportsReturnsTrueWhenConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration('/var/log/access.log*'));

        self::assertTrue($this->subject->supports($this->site));
    }

    public function testSupportsReturnsFalseWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        self::assertFalse($this->subject->supports($this->site));
    }

    public function testFetchYieldsNothingWhenNotConfigured(): void
    {
        $this->resolver->method('resolve')->willReturn(null);

        $result = iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()));

        self::assertSame([], $result);
    }

    public function testFetchCountsPageviewsAndApproximatesVisitsByIpAndUserAgent(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:11:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:12:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(3, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
        self::assertSame(2, $metrics['accesslog.visits']['https://example.com/about'] ?? null);
    }

    public function testFetchFiltersOutKnownBotUserAgentsFromHumanMetrics(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'Mozilla/5.0 (compatible; Googlebot/2.1)'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'Mozilla/5.0 real browser'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchOnlyCountsSuccessfulGetRequestsAsHumanPageviews(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 404, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:02:00 +0000', '203.0.113.3', 'GET', '/about', 500, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:03:00 +0000', '203.0.113.4', 'GET', '/about', 301, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:04:00 +0000', '203.0.113.5', 'POST', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchCountsA304NotModifiedAsAHumanPageview(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.2', 'GET', '/about', 304, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(2, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchFiltersOutStaticAssetsAndBackendPathsFromHumanMetrics(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/style.css', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:01:00 +0000', '203.0.113.1', 'GET', '/typo3/index.php', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:02:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertArrayNotHasKey('https://example.com/style.css', $metrics['accesslog.pageviews'] ?? []);
        self::assertArrayNotHasKey('https://example.com/typo3/index.php', $metrics['accesslog.pageviews'] ?? []);
        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchOnlyIncludesDatesWithinTheRequestedRange(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('09/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
            $this->logLine('11/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $result = iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()), false);
        $dates = array_map(static fn (UrlMetric $metric): string => $metric->date->format('Y-m-d'), $result);

        self::assertSame(['2026-10-10', '2026-10-10'], $dates);
    }

    public function testFetchSupportsAGzippedLogFile(): void
    {
        $line = $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA');
        $file = tempnam(sys_get_temp_dir(), 'accesslog-').'.gz';
        $this->tempFiles[] = $file;
        file_put_contents($file, gzencode($line."\n"));
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);
    }

    public function testFetchSupportsAGlobPatternMatchingMultipleFiles(): void
    {
        $directory = (string) tempnam(sys_get_temp_dir(), 'accesslog-glob-');
        unlink($directory);
        mkdir($directory);
        file_put_contents($directory.'/access.log', $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'BrowserA')."\n");
        file_put_contents($directory.'/access.log.1', $this->logLine('10/Oct/2026:11:00:00 +0000', '203.0.113.2', 'GET', '/about', 200, 'BrowserB')."\n");
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($directory.'/access.log*'));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(2, $metrics['accesslog.pageviews']['https://example.com/about'] ?? null);

        unlink($directory.'/access.log');
        unlink($directory.'/access.log.1');
        rmdir($directory);
    }

    public function testFetchYieldsABotBucketMetricForAClassifiedLineRegardlessOfStatus(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/gone', 404, 'GPTBot/1.0'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertSame(1, $metrics['bot.ai_crawler']['https://example.com/gone'] ?? null);
    }

    public function testFetchDoesNotCountAClassifiedLineAsAHumanPageview(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'GPTBot/1.0'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));

        $metrics = $this->fetchByMetric($this->rangeCoveringOctober10th());

        self::assertArrayNotHasKey('https://example.com/about', $metrics['accesslog.pageviews'] ?? []);
    }

    public function testFetchTellsTheCollectorAboutEveryClassifiedLine(): void
    {
        $file = $this->writeLogFile([
            $this->logLine('10/Oct/2026:10:00:00 +0000', '203.0.113.1', 'GET', '/about', 200, 'GPTBot/1.0'),
        ]);
        $this->resolver->method('resolve')->willReturn(new AccessLogConfiguration($file));
        $this->collector->expects(self::once())->method('add');

        iterator_to_array($this->subject->fetch($this->site, $this->rangeCoveringOctober10th()));
    }

    private function rangeCoveringOctober10th(): DateRange
    {
        return new DateRange(new DateTimeImmutable('2026-10-10'), new DateTimeImmutable('2026-10-10'));
    }

    /**
     * @param list<string> $lines
     */
    private function writeLogFile(array $lines): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'accesslog-');
        $this->tempFiles[] = $file;
        file_put_contents($file, implode("\n", $lines)."\n");

        return $file;
    }

    private function logLine(string $timestamp, string $ip, string $method, string $path, int $status, string $userAgent): string
    {
        return sprintf('%s - - [%s] "%s %s HTTP/1.1" %d 512 "-" "%s"', $ip, $timestamp, $method, $path, $status, $userAgent);
    }

    /**
     * @return array<string, array<string, int>> metric identifier => url => value
     */
    private function fetchByMetric(DateRange $range): array
    {
        $result = [];

        foreach ($this->subject->fetch($this->site, $range) as $metric) {
            $result[$metric->metric][$metric->url] = $metric->value;
        }

        return $result;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c phpunit.xml --filter AccessLogMetricProviderTest`
Expected: FAIL — constructor argument count mismatch (the current `AccessLogMetricProvider` still takes `(AccessLogConfigurationResolver, AccessLogLineParser, BotFilter)`).

- [ ] **Step 3: Execute Task 5's deletion, then rewrite `AccessLogMetricProvider`**

Run the `git rm` command from Task 5, Step 1, if not already run.

Replace the full content of `Classes/Provider/AccessLog/AccessLogMetricProvider.php`:

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Provider\AccessLog;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Guard\PathHeuristicGuard;
use KonradMichalik\Typo3PageMetrics\Analysis\Parser\CombinedLogParser;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\BotTaxonomy;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Site\Entity\Site;

use function count;
use function explode;
use function fclose;
use function fgets;
use function fopen;
use function glob;
use function gzclose;
use function gzeof;
use function gzgets;
use function gzopen;
use function in_array;
use function pathinfo;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

/**
 * AccessLogMetricProvider.
 *
 * Reads the site's own Apache/Nginx "combined" access log(s) directly
 * (AccessLogConfiguration::$pathPattern, resolved via glob() so a rotated
 * set like "access.log", "access.log.1", "access.log-20261001.gz" is read
 * as one set) rather than calling an analytics API - no Matomo/GA4 needed,
 * at the cost of only ever offering page views and an approximated visit
 * count: a raw access log carries no session/client-side signal, so
 * "avg. time on page" and "bounce rate" (see MatomoMetricProvider,
 * Ga4MetricProvider) have nothing to be derived from here and are
 * deliberately not offered (see getRateMetricDefinitions()).
 *
 * "Visits" is a same-day distinct (IP address, user agent) count per page,
 * not a true session count - the closest approximation a stateless log
 * line allows, and the reason its own MetricDefinition description calls
 * it out as such.
 *
 * Every line in range is also classified via BotTaxonomy. A classified
 * line never counts toward pageviews/visits (it is bot traffic, not a
 * human page view) and instead contributes to that bucket's own
 * bot.<bucket> metric - and, unlike the human path, WITHOUT the GET/2xx/
 * static-asset/backend-path filtering pageviews/visits apply: a bot
 * hitting a dead link, a redirect, or a backend probe is exactly the
 * traffic this diagnostic exists to surface (see the merge design spec's
 * §4.3 "Redirect-Anteil entsteht kostenlos"), not traffic to discard
 * before it can be measured. BotTaxonomy's own static-asset exclusion
 * still applies (real CSS/JS/image hits are never classified as any
 * bucket), so no separate path filter is needed on this side either.
 * Agent-level detail (which specific bot, and whether its path pattern
 * looked spoofed) does not fit UrlMetric's shape and is instead handed to
 * BotAgentDetailCollector, flushed by ImportCommand once this provider's
 * fetch() has been fully consumed.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AccessLogMetricProvider implements MetricProviderInterface
{
    private const IDENTIFIER = 'accesslog';

    /**
     * Checked against the request path alone (query string stripped first,
     * see isRelevantPath()) - a log covering the whole site inevitably also
     * carries every CSS/JS/image/font hit, which would otherwise swamp real
     * page views by orders of magnitude. Applies only to the human-metrics
     * path; see the class docblock for why bot classification does not use
     * this filter.
     */
    private const STATIC_ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp',
        'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp4', 'webm', 'pdf',
        'zip', 'xml', 'json', 'txt',
    ];

    public function __construct(
        private AccessLogConfigurationResolver $configurationResolver,
        private CombinedLogParser $lineParser,
        private BotTaxonomy $taxonomy,
        private PathHeuristicGuard $guard,
        private BotAgentDetailCollector $agentDetailCollector,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function supports(Site $site): bool
    {
        return null !== $this->configurationResolver->resolve($site);
    }

    public function getMetricDefinitions(): array
    {
        return [
            new MetricDefinition('accesslog.pageviews', 'Page views', 'Successful (2xx) GET requests for this page in the selected period, parsed from the web server access log.', 'tabler-eye-search', MetricUnit::Count, 10, '#116329'),
            new MetricDefinition('accesslog.visits', 'Visits', 'Distinct IP address/user agent pairs that requested this page on the same day - an approximation, not a true session count.', 'tabler-chart-dots', MetricUnit::Count, 20, '#57606a'),
            new MetricDefinition('bot.ai_chatbot', 'AI chatbots', 'Requests from AI chatbots fetching this page live in response to a specific user request.', 'tabler-robot', MetricUnit::Count, 30, '#4c6ef5'),
            new MetricDefinition('bot.ai_crawler', 'AI crawlers', 'Requests from AI crawlers collecting content for model training or an AI search index.', 'tabler-spider', MetricUnit::Count, 40, '#ae3ec9'),
            new MetricDefinition('bot.search', 'Search indexers', 'Requests from real search-engine indexers building their search index.', 'tabler-world-search', MetricUnit::Count, 50, '#0ca678'),
            new MetricDefinition('bot.social', 'Social/link previews', 'Requests from social platforms generating link previews for shared URLs.', 'tabler-share', MetricUnit::Count, 60, '#f06595'),
            new MetricDefinition('bot.tool', 'SEO tools & scripts', 'Requests from SEO tools, monitoring services and generic HTTP clients.', 'tabler-tool', MetricUnit::Count, 70, '#f59f00'),
        ];
    }

    public function getRateMetricDefinitions(): array
    {
        return [];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        // A raw access log has no external UI of its own to deep-link into.
        return null;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $configuration = $this->configurationResolver->resolve($site);

        if (null === $configuration) {
            return;
        }

        $allowedDates = [];
        foreach ($range->days() as $day) {
            $allowedDates[$day->format('Y-m-d')] = true;
        }

        $base = $site->getBase();
        $baseUrl = $base->getScheme().'://'.$base->getAuthority();

        /** @var array<string, array<string, int>> $pageviews date => path => hit count */
        $pageviews = [];
        /** @var array<string, array<string, array<string, true>>> $visitors date => path => "ip|userAgent" set */
        $visitors = [];
        /** @var array<string, array<string, array<string, int>>> $botHits date => bucket value => url => hit count */
        $botHits = [];

        foreach ($this->lines($configuration->pathPattern) as $rawLine) {
            $line = $this->lineParser->parseLine($rawLine);

            if (null === $line) {
                continue;
            }

            $date = $line->timestamp->format('Y-m-d');

            if (!isset($allowedDates[$date])) {
                continue;
            }

            $classification = $this->taxonomy->classify($line);
            $url = $baseUrl.$line->target;

            if (null !== $classification) {
                $botHits[$date][$classification->bucket->value][$url] = ($botHits[$date][$classification->bucket->value][$url] ?? 0) + 1;
                $this->agentDetailCollector->add($line->timestamp, $url, $classification->bucket, $classification->agent, $this->guard->isSuspicious($line));

                continue;
            }

            if ('GET' !== $line->method || !$this->wasSuccessful($line->status) || !$this->isRelevantPath($line->target)) {
                continue;
            }

            $pageviews[$date][$line->target] = ($pageviews[$date][$line->target] ?? 0) + 1;
            $visitors[$date][$line->target][$line->ip.'|'.$line->userAgent] = true;
        }

        foreach ($pageviews as $date => $paths) {
            $day = new DateTimeImmutable($date);

            foreach ($paths as $path => $hits) {
                $url = $baseUrl.$path;
                yield new UrlMetric($url, $day, 'accesslog.pageviews', $hits);
                yield new UrlMetric($url, $day, 'accesslog.visits', count($visitors[$date][$path] ?? []));
            }
        }

        foreach ($botHits as $date => $byBucket) {
            $day = new DateTimeImmutable($date);

            foreach ($byBucket as $bucketValue => $urls) {
                foreach ($urls as $url => $hits) {
                    yield new UrlMetric($url, $day, 'bot.'.$bucketValue, $hits);
                }
            }
        }
    }

    /**
     * 2xx, plus 304 Not Modified: a conditional GET that hits the browser's
     * own cache still means the page was viewed, it just didn't need a
     * fresh body - excluding it would undercount every repeat visit on a
     * site with normal cache-revalidation headers (ETag/Last-Modified).
     * Every other 3xx is excluded on purpose: a redirect's own eventual 200
     * on the target page is what should count, not the hop that got it
     * there. Applies only to the human-metrics path.
     */
    private function wasSuccessful(int $statusCode): bool
    {
        return ($statusCode >= 200 && $statusCode <= 299) || 304 === $statusCode;
    }

    private function isRelevantPath(string $pathWithQuery): bool
    {
        $path = explode('?', $pathWithQuery, 2)[0];

        if (str_starts_with($path, '/typo3/') || str_starts_with($path, '/typo3conf/')) {
            return false;
        }

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return '' === $extension || !in_array($extension, self::STATIC_ASSET_EXTENSIONS, true);
    }

    /**
     * @return iterable<string>
     */
    private function lines(string $pathPattern): iterable
    {
        foreach (glob($pathPattern) ?: [] as $file) {
            yield from str_ends_with($file, '.gz') ? $this->gzipLines($file) : $this->plainLines($file);
        }
    }

    /**
     * @return iterable<string>
     */
    private function plainLines(string $file): iterable
    {
        $handle = fopen($file, 'r');

        if (false === $handle) {
            return;
        }

        try {
            while (false !== ($line = fgets($handle))) {
                yield $line;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return iterable<string>
     */
    private function gzipLines(string $file): iterable
    {
        $handle = gzopen($file, 'rb');

        if (false === $handle) {
            return;
        }

        try {
            while (!gzeof($handle)) {
                $line = gzgets($handle);

                if (false !== $line) {
                    yield $line;
                }
            }
        } finally {
            gzclose($handle);
        }
    }
}
```

Note the one intentional, minor behavior change versus the original for the **human** path: `isRelevantPath()` is now checked (in the human branch) alongside `wasSuccessful()`/method in a single combined condition rather than being ANDed together with the bot check the old code used — functionally identical filtering, just restructured because the bot check is no longer part of the same boolean expression (it now happens earlier, before the human branch is even reached).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c phpunit.xml --filter AccessLogMetricProviderTest`
Expected: PASS (all tests, including the 3 new bot-emission ones).

- [ ] **Step 5: Run the full unit suite to catch any other fallout**

Run: `vendor/bin/phpunit -c phpunit.xml --no-coverage`
Expected: PASS. If `Tests/Functional/Provider/AccessLog/AccessLogMetricProviderWiringTest.php` (a functional test, not part of this run) references the old constructor shape, note it for Task 7 — that test is expected to still pass unmodified since it only asserts DI wiring resolves (`$this->get(AccessLogMetricProvider::class)` succeeding), not the constructor signature directly.

- [ ] **Step 6: Commit**

```bash
git add Classes/Provider/AccessLog/AccessLogMetricProvider.php Tests/Unit/Provider/AccessLog/AccessLogMetricProviderTest.php
git commit -m "refactor: classify every access-log line via BotTaxonomy, drop BotFilter"
```

---

### Task 7: Wire `ImportCommand` — collector flush and a persistence transaction

**Files:**
- Modify: `Classes/Command/ImportCommand.php`
- Modify: `Tests/Functional/Command/ImportCommandTest.php`

**Interfaces:**
- Consumes: `BotAgentDetailCollector::flush(string $siteIdentifier, bool $dropQueryString, array $keepQueryParams): void` (Task 4), `ConnectionPool::getConnectionForTable(string $table): Connection` and `Connection::transactional(callable): mixed` (Doctrine DBAL, already used transitively via the repositories).

Two changes to `importOneProviderForSite()`:
1. After the existing `foreach ($aggregated as $row) upsert(...)` loop, call `$this->botAgentDetailCollector->flush(...)`.
2. Wrap {the upsert loop, the collector flush, `markImported`} in one DB transaction, so a crash between them can no longer leave `last_imported_date` advanced ahead of unwritten aggregates (spec §8). The `fetch()`/resolve loop that builds `$aggregated` stays OUTSIDE the transaction — it may call a slow external API (Matomo/GA4) or do a lot of file I/O, and holding a DB transaction open across that would be a lock-duration regression the spec does not ask for. `unresolvedUrlRepository->record()` calls (made during that same fetch/resolve loop, not the persistence step) are unaffected by this change and keep their existing already-idempotent, cap-trimmed behavior.

- [ ] **Step 1: Write the failing functional tests**

Add these two test methods to `Tests/Functional/Command/ImportCommandTest.php` (after the existing `testTwoUrlsResolvingToTheSamePageAndDaySumInsteadOfOverwriting` test or any other existing test — appended at the end of the class, before the closing brace):

```php
    public function testFlushesBotAgentDetailForABotAwareProviderAfterItsPersistenceStep(): void
    {
        $today = new DateTimeImmutable('today');
        $stub = new BotAwareStubMetricProvider(
            [new UrlMetric('https://example.com/about', $today, 'bot.ai_crawler', 3)],
            $this->get(BotAgentDetailCollector::class),
            $today,
            'https://example.com/about',
            Bucket::AiCrawler,
            'GPTBot',
        );

        $exitCode = $this->runImport([$stub]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $hits = $queryBuilder
            ->select('hits')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter(2)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter('ai_crawler')),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter('GPTBot')),
            )
            ->executeQuery()
            ->fetchOne();

        self::assertSame(1, $hits);
    }

    public function testRunningTheSameLogImportTwiceProducesIdenticalTotalsRatherThanDoubleCounting(): void
    {
        $today = new DateTimeImmutable('today');
        $urlMetric = new UrlMetric('https://example.com/about', $today, 'bot.ai_crawler', 3);
        $collector = $this->get(BotAgentDetailCollector::class);

        $firstRun = new BotAwareStubMetricProvider([$urlMetric], $collector, $today, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot');
        $this->runImport([$firstRun], true);

        $secondRun = new BotAwareStubMetricProvider([$urlMetric], $collector, $today, 'https://example.com/about', Bucket::AiCrawler, 'GPTBot');
        $this->runImport([$secondRun], true);

        $series = $this->dailyMetricsRepository->dailySeries(2, 0, 'bot.ai_crawler', new DateRange($today, $today));
        self::assertSame([$today->format('Y-m-d') => 3], $series);

        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tx_typo3pagemetrics_bot_agent_daily');
        $hits = $queryBuilder
            ->select('hits')
            ->from('tx_typo3pagemetrics_bot_agent_daily')
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter(2)),
                $queryBuilder->expr()->eq('bucket', $queryBuilder->createNamedParameter('ai_crawler')),
                $queryBuilder->expr()->eq('agent', $queryBuilder->createNamedParameter('GPTBot')),
            )
            ->executeQuery()
            ->fetchOne();

        self::assertSame(3, $hits);
    }
```

Add the required `use` statements to the top of `Tests/Functional/Command/ImportCommandTest.php`:

```php
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures\BotAwareStubMetricProvider;
```

Create the fixture `Tests/Functional/Command/Fixtures/BotAwareStubMetricProvider.php`. `StubMetricProvider` (`Tests/Functional/Command/Fixtures/StubMetricProvider.php:26`) is `final readonly class StubMetricProvider implements MetricProviderInterface`, so this cannot subclass it - it implements `MetricProviderInterface` directly instead, mirroring `StubMetricProvider`'s own body with one addition (the `collector->add()` call at the top of `fetch()`):

```php
<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_page_metrics" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3PageMetrics\Tests\Functional\Command\Fixtures;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Analysis\Taxonomy\Bucket;
use KonradMichalik\Typo3PageMetrics\Domain\Enum\MetricUnit;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\{DateRange, MetricDefinition, UrlMetric};
use KonradMichalik\Typo3PageMetrics\Provider\MetricProviderInterface;
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * BotAwareStubMetricProvider.
 *
 * StubMetricProvider's counterpart for exercising ImportCommand's
 * collector-flush and transaction wiring: fetch() both yields the given
 * UrlMetric list (same as StubMetricProvider) and tells the given
 * BotAgentDetailCollector about one agent hit, the same way
 * AccessLogMetricProvider does for a real classified log line. Cannot
 * subclass StubMetricProvider - that class is final - so it duplicates its
 * small body directly instead.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class BotAwareStubMetricProvider implements MetricProviderInterface
{
    /**
     * @param list<UrlMetric> $metrics
     */
    public function __construct(
        private array $metrics,
        private BotAgentDetailCollector $collector,
        private DateTimeImmutable $agentHitDate,
        private string $agentHitUrl,
        private Bucket $agentHitBucket,
        private string $agentHitAgent,
    ) {}

    public function getIdentifier(): string
    {
        return 'stub';
    }

    public function supports(Site $site): bool
    {
        return true;
    }

    public function getMetricDefinitions(): array
    {
        return [new MetricDefinition('bot.ai_crawler', 'AI crawlers', 'Stub bot hits.', 'tabler-spider', MetricUnit::Count, 1, '#ae3ec9')];
    }

    public function getRateMetricDefinitions(): array
    {
        return [];
    }

    public function getExternalLink(Site $site, string $pageUrl, DateRange $range): ?string
    {
        return null;
    }

    public function fetch(Site $site, DateRange $range): iterable
    {
        $this->collector->add($this->agentHitDate, $this->agentHitUrl, $this->agentHitBucket, $this->agentHitAgent, false);

        yield from $this->metrics;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter "testFlushesBotAgentDetail|testRunningTheSameLogImportTwice"`
Expected: FAIL — no rows in `tx_typo3pagemetrics_bot_agent_daily` (the collector is never flushed yet).

- [ ] **Step 3: Implement the wiring**

In `Classes/Command/ImportCommand.php`:

Add to the `use` block:
```php
use KonradMichalik\Typo3PageMetrics\Service\BotAgentDetailCollector;
use TYPO3\CMS\Core\Database\ConnectionPool;
```

Add a new constructor parameter (after `$annotationRepository`, before `$providers`):
```php
        private readonly BotAgentDetailCollector $botAgentDetailCollector,
        private readonly ConnectionPool $connectionPool,
```

Replace `importOneProviderForSite()`'s tail (from the `foreach ($aggregated as $row)` line through the end of the method) with:

```php
        $connection = $this->connectionPool->getConnectionForTable('tx_typo3pagemetrics_daily');
        $connection->transactional(function () use ($aggregated, $identifier, $site, $configuration, $today): void {
            foreach ($aggregated as $row) {
                $this->dailyMetricsRepository->upsert($row['pageUid'], $row['languageUid'], $identifier, $row['date'], $row['metric'], $row['value'], $row['dimension']);
            }

            $this->botAgentDetailCollector->flush($site->getIdentifier(), $configuration->dropQueryString, $configuration->keepQueryParams);

            // Mark yesterday, not today, as the last complete imported day: a
            // scheduled run may only capture part of today's traffic, so today
            // must always be revisited (and safely re-upserted) by the next run
            // until it is genuinely a fully elapsed prior day.
            $this->importStateRepository->markImported($identifier, $site->getIdentifier(), $today->modify('-1 day'));
        });

        $output->writeln(sprintf('Imported "%s" for site "%s" from %s to %s.', $identifier, $site->getIdentifier(), $from->format('Y-m-d'), $today->format('Y-m-d')));
    }
```

(The closing `}` of `importOneProviderForSite()` and the `accumulate()` method below it are unchanged — only the block from the old `foreach ($aggregated as $row)` line through the old `markImported`/`writeln` lines is replaced by the above.)

- [ ] **Step 4: Register the two new constructor arguments in DI**

`Configuration/Services.yaml`'s existing `ImportCommand` entry only overrides `$providers`/`$annotationProviders` (both need `!tagged_iterator`); `$botAgentDetailCollector` and `$connectionPool` autowire by type with no entry needed — verify this is true by running the test in Step 5 rather than pre-emptively adding a Services.yaml entry that may not be needed.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter "testFlushesBotAgentDetail|testRunningTheSameLogImportTwice"`
Expected: PASS.

- [ ] **Step 6: Run the full functional command test file to confirm no regression**

Run: `vendor/bin/phpunit -c phpunit.functional.xml --filter ImportCommandTest`
Expected: PASS (every existing test plus the two new ones).

- [ ] **Step 7: Commit**

```bash
git add Classes/Command/ImportCommand.php Tests/Functional/Command/ImportCommandTest.php Tests/Functional/Command/Fixtures/BotAwareStubMetricProvider.php
git commit -m "feat: flush bot agent detail and wrap import persistence in a transaction"
```

---

### Task 8: Port `AnalyzeCommand` and its dependency chain

**Files:**
- Create: `Classes/Analysis/Aggregate/Aggregator.php`
- Create: `Classes/Analysis/Aggregate/AgentDailyAggregate.php`
- Create: `Classes/Analysis/Aggregate/UrlDailyAggregate.php`
- Create: `Classes/Import/SourceLocator.php`
- Create: `Classes/Import/LogFileReader.php`
- Create: `Classes/Command/AnalyzeCommand.php`
- Create: any `Tests/Unit/...` test files bot-inspect has for `Aggregator`/`SourceLocator`/`LogFileReader` (inventory in Step 1, mirror Task 1's approach)
- Modify: `Configuration/Services.yaml`

**Interfaces:**
- Consumes: `BotTaxonomy`, `SpoofingGuardInterface`, `LogParserInterface`/`CombinedLogParser` (Task 1).
- Produces: console command `page-metrics:analyze` — a standalone, DB-free diagnostic tool, unrelated to the persistence pipeline built in Tasks 2-7.

This is a **mechanical port**, same method as Task 1: copy, adapt namespace and copyright, adapt any `KonradMichalik\Typo3BotInspect\` references to sibling classes, run the ported tests.

- [ ] **Step 1: Inventory the exact source files and confirm no page-scoped dependencies leak in**

Run:
```bash
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Classes/Analysis/Aggregate -type f
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Classes/Import -type f
find /Users/k.michalik/Sites/Packages/typo3-bot-inspect/Tests -iname "*Aggregator*" -o -iname "*SourceLocator*" -o -iname "*LogFileReader*"
```
Confirm `Aggregator.php` only imports `Bucket`/`Classification`/`LogLine` (already ported in Task 1) and produces only `AgentDailyAggregate`/`UrlDailyAggregate` — **do not** port `PageDailyAggregate.php` or `PageAgentDailyAggregate.php`: `AnalyzeCommand` never uses them (it only calls `$aggregator->agentDailyAggregates()`/`urlDailyAggregates()`), and they exist in bot-inspect only for its own DB-writing `LogImporter`, which this plan does not port (page-metrics' `ImportCommand` replaces that role entirely).

- [ ] **Step 2: Copy `Aggregator`, `AgentDailyAggregate`, `UrlDailyAggregate`**

Same two substitutions as Task 1, Step 2 (`KonradMichalik\Typo3BotInspect\Analysis\` → `KonradMichalik\Typo3PageMetrics\Analysis\` including the `use ...Analysis\Taxonomy\{Bucket, Classification};` and `use ...Analysis\Parser\LogLine;` import lines inside `Aggregator.php`; `"typo3_bot_inspect"` → `"typo3_page_metrics"` in the header comment) into `Classes/Analysis/Aggregate/{Aggregator,AgentDailyAggregate,UrlDailyAggregate}.php`.

- [ ] **Step 3: Copy `SourceLocator`, `LogFileReader`**

Same substitutions, into `Classes/Import/{SourceLocator,LogFileReader}.php`. These two have no dependency on anything else in this repo (confirmed in Task-gathering: `SourceLocator` only uses SPL filesystem iterators, `LogFileReader` only uses PHP's native `fopen`/`gzopen` family) — the port is a pure copy plus the two substitutions, nothing else changes.

- [ ] **Step 4: Copy any ported tests, run them**

Copy whatever test files Step 1 found, with the standard substitutions, into the mirroring `Tests/Unit/...` paths.

Run: `vendor/bin/phpunit -c phpunit.xml --filter "Aggregator|SourceLocator|LogFileReader"`
Expected: PASS.

- [ ] **Step 5: Port `AnalyzeCommand`**

Copy `Classes/Command/AnalyzeCommand.php` from bot-inspect into `Classes/Command/AnalyzeCommand.php`, with:
1. The standard namespace/copyright substitutions.
2. Remove the `#[AsCommand(name: 'botinspect:analyze', description: '...')]` attribute entirely (page-metrics' own `ImportCommand` does not use `#[AsCommand]`; command name/description are registered in `Configuration/Services.yaml` instead — see Step 6). Keep the `configure()` method's `addArgument`/`addOption` calls unchanged.
3. Every constructor-injected dependency (`SourceLocator`, `LogFileReader`, `LogParserInterface`, `BotTaxonomy`, `SpoofingGuardInterface`) now resolves to the Task 1/Task 8 ported classes automatically via the same namespace.

- [ ] **Step 6: Register the command in DI**

Add to `Configuration/Services.yaml`, alphabetically after the `ImportCommand` entry:

```yaml
    KonradMichalik\Typo3PageMetrics\Command\AnalyzeCommand:
        tags:
            - name: console.command
              command: 'page-metrics:analyze'
              description: 'Analyze access logs ad hoc, without writing to the database'
```

- [ ] **Step 7: Smoke-test the command end to end**

Run:
```bash
echo '203.0.113.1 - - [10/Oct/2026:10:00:00 +0000] "GET /about HTTP/1.1" 200 512 "-" "GPTBot/1.0"' > /tmp/smoke-access.log
vendor/bin/typo3 page-metrics:analyze /tmp/smoke-access.log
```
Expected: exits `0`, prints an "Overview" section with one `ai_crawler` row and an "Agents: ai_crawler" table listing `GPTBot`. If the TYPO3 CLI entrypoint path differs in this repo (check `bin/typo3` or `vendor/bin/typo3` first with `ls`), use whichever exists. Clean up: `rm /tmp/smoke-access.log`.

- [ ] **Step 8: Commit**

```bash
git add Classes/Analysis/Aggregate Classes/Import Classes/Command/AnalyzeCommand.php Tests Configuration/Services.yaml
git commit -m "feat: port AnalyzeCommand as a DB-free ad-hoc log analysis tool"
```

---

### Task 9: Update `docs/providers/accesslog.md`

**Files:**
- Modify: `docs/providers/accesslog.md`

**Interfaces:**
- None — documentation only.

- [ ] **Step 1: Read the current file**

Read `docs/providers/accesslog.md` in full to match its existing structure, heading levels, and tone before editing.

- [ ] **Step 2: Add a section documenting the bot metrics**

Add a new section (placed consistently with the existing document's structure — likely after the pageviews/visits description) covering: the five `bot.<bucket>` metrics and what each bucket means (chatbot/crawler/search/social/tool), that bot hits are counted regardless of HTTP status (unlike `accesslog.pageviews`) because the point is measuring how much bot traffic lands nowhere, that agent-level detail (which specific bot, and a `hits_suspicious` count from path-heuristic spoofing detection) lives in `tx_typo3pagemetrics_bot_agent_daily` rather than the tile system, and a pointer to `page-metrics:analyze` as the ad-hoc, DB-free way to inspect a log file directly. State plainly that a full bot-traffic backend module (mirroring the one this data model was originally built for, in `typo3-bot-inspect`) is planned but not part of this feature — link to the design spec (`docs/superpowers/specs/2026-09-11-bot-inspect-merge-design.md`) rather than duplicate its content.

- [ ] **Step 3: Commit**

```bash
git add docs/providers/accesslog.md
git commit -m "docs: document the merged bot-traffic metrics and page-metrics:analyze"
```

---

### Task 10: Full verification pass

**Files:** none (verification only).

- [ ] **Step 1: Run the complete unit and functional suites**

```bash
vendor/bin/phpunit -c phpunit.xml --no-coverage
vendor/bin/phpunit -c phpunit.functional.xml --no-coverage
```
Expected: PASS, zero failures, zero errors.

- [ ] **Step 2: Run static analysis and coding-standards checks**

Detect and run whatever this repo has configured (check for `phpstan.neon*`, `.php-cs-fixer.php`, `rector.php`, and any `composer.json` `scripts` entries matching `lint`/`check`/`stan`/`cgl`) — this repo's own `Tests/CGL/` subdirectory (seen in the earlier `find` output) strongly suggests a `composer cgl` script exists; run it with autofix, then re-run the test suites if it changed anything.

- [ ] **Step 3: Re-read the merge design spec and confirm Package 1 is fully covered**

Read `docs/superpowers/specs/2026-09-11-bot-inspect-merge-design.md` §3 (Entscheidungen), §4 (Datenmodell), §5.1-5.3, §6 (Datenfluss), §8 (Fehlerbehandlung), §9 (Tests) and confirm each item has a corresponding implemented task above. Note explicitly, in your final report, the two disclosed scope decisions this plan made beyond a literal reading of the spec:
1. `CombinedLogParser`/`LogLine` are **kept** (not replaced by `AccessLogLineParser`/`AccessLogEntry` as spec §5.2's table literally states) — `BotTaxonomy::classify()` requires a `LogLine`, so the spec's stated direction was inverted; this plan corrects it in the actual port.
2. Parse-failure counting (spec §8, "gezählt und am Ende ausgegeben") is **not implemented** in this plan — no clean seam exists without adding a method to the frozen `MetricProviderInterface` (spec §3.5), and inventing an unreviewed marker-interface mechanism was judged out of scope for this pass. Flag it as a deferred follow-up.

Do not silently fix scope gaps found here beyond what is already staged in Tasks 1-9 — report them for the next step (code review) instead.
