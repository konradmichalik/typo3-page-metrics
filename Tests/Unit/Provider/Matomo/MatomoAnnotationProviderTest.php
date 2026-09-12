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

namespace KonradMichalik\Typo3PageMetrics\Tests\Unit\Provider\Matomo;

use DateTimeImmutable;
use KonradMichalik\Typo3PageMetrics\Domain\ValueObject\DateRange;
use KonradMichalik\Typo3PageMetrics\Provider\Matomo\{MatomoAnnotationProvider, MatomoApiClient, MatomoConfiguration, MatomoConfigurationResolver};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * MatomoAnnotationProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MatomoAnnotationProviderTest extends TestCase
{
    /** @var MatomoConfigurationResolver&\PHPUnit\Framework\MockObject\MockObject */
    private MatomoConfigurationResolver $resolver;

    /** @var MatomoApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private MatomoApiClient $apiClient;
    private MatomoAnnotationProvider $subject;
    private Site $site;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(MatomoConfigurationResolver::class);
        $this->apiClient = $this->createMock(MatomoApiClient::class);
        $this->subject = new MatomoAnnotationProvider($this->resolver, $this->apiClient);
        $this->site = new Site('main', 1, ['base' => 'https://example.com/']);
    }

    public function testGetIdentifierReturnsMatomo(): void
    {
        self::assertSame('matomo', $this->subject->getIdentifier());
    }

    public function testSupportsReturnsTrueWhenConfigurationResolves(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));

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

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchMapsAnnotationsFromTheSiteKeyedResponse(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            '3' => [
                ['id' => 1, 'idNote' => 1, 'idsite' => 3, 'date' => '2026-08-02', 'note' => 'Release 2.0', 'starred' => 0, 'user' => 'admin', 'canEditOrDelete' => true],
                ['id' => 2, 'idNote' => 2, 'idsite' => 3, 'date' => '2026-08-05', 'note' => 'Campaign start', 'starred' => 1, 'user' => 'admin', 'canEditOrDelete' => true],
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $annotations = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(2, $annotations);
        self::assertSame('1', $annotations[0]->id);
        self::assertSame('2026-08-02', $annotations[0]->date->format('Y-m-d'));
        self::assertSame('Release 2.0', $annotations[0]->text);
        self::assertSame('2', $annotations[1]->id);
        self::assertSame('2026-08-05', $annotations[1]->date->format('Y-m-d'));
        self::assertSame('Campaign start', $annotations[1]->text);
    }

    public function testFetchReturnsNothingWhenTheSiteHasNoAnnotations(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchCallsAnnotationsGetAllOncePerRangeWithPeriodRange(): void
    {
        $configuration = new MatomoConfiguration('https://matomo.example.com/', 3, 'token');
        $this->resolver->method('resolve')->willReturn($configuration);

        $calls = [];
        $this->apiClient->expects(self::once())
            ->method('request')
            ->willReturnCallback(static function (MatomoConfiguration $passedConfiguration, array $parameters) use (&$calls, $configuration) {
                self::assertSame($configuration, $passedConfiguration);
                $calls[] = $parameters;

                return [];
            });

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        iterator_to_array($this->subject->fetch($this->site, $range));

        self::assertCount(1, $calls);
        self::assertSame('Annotations.getAll', $calls[0]['method']);
        self::assertSame('range', $calls[0]['period']);
        self::assertSame('2026-08-01,2026-08-07', $calls[0]['date']);
    }

    public function testFetchYieldsNothingWhenTheSiteKeyDoesNotHoldAList(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn(['3' => 'no annotations']);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));

        self::assertSame([], iterator_to_array($this->subject->fetch($this->site, $range)));
    }

    public function testFetchSkipsRowsThatAreMalformedOrLackAUsableId(): void
    {
        $this->resolver->method('resolve')->willReturn(new MatomoConfiguration('https://matomo.example.com/', 3, 'token'));
        $this->apiClient->method('request')->willReturn([
            '3' => [
                'not-a-row',
                ['date' => '2026-08-02'],
                ['date' => 20260802, 'note' => 'Numeric date', 'id' => 1],
                ['date' => '2026-08-03', 'note' => 'No id at all'],
                ['date' => '2026-08-04', 'note' => 'Array id', 'id' => ['nested']],
                ['date' => '2026-08-05', 'note' => 'Kept', 'id' => 7],
            ],
        ]);

        $range = new DateRange(new DateTimeImmutable('2026-08-01'), new DateTimeImmutable('2026-08-07'));
        $annotations = iterator_to_array($this->subject->fetch($this->site, $range), false);

        self::assertCount(1, $annotations);
        self::assertSame('7', $annotations[0]->id);
        self::assertSame('Kept', $annotations[0]->text);
    }
}
