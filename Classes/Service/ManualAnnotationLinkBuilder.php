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
use KonradMichalik\Typo3PageMetrics\Configuration\SiteConfigurationReader;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * ManualAnnotationLinkBuilder.
 *
 * The link behind "Add marker" in the page module: a FormEngine form for a
 * new manual annotation, with the site, the day and the page already filled
 * in, so a marker is recorded where the work that caused it happened rather
 * than in a separate list somewhere else.
 *
 * Every part of the link is decided here and nothing is taken from the
 * request: the record's page comes from the site's own
 * pageMetrics.annotations.storagePid, the site identifier from the site the
 * page belongs to. A backend user who may not create a record there gets no
 * link at all, and DataHandler enforces the same permissions again when the
 * form is saved, so a hand-built URL wins nothing.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ManualAnnotationLinkBuilder
{
    private const TABLE = 'tx_typo3pagemetrics_manual_annotation';

    public function __construct(
        private SiteConfigurationReader $configurationReader,
        private UriBuilder $uriBuilder,
    ) {}

    public function forPage(Site $site, int $pageUid, DateTimeImmutable $date): ?string
    {
        $storagePid = $this->configurationReader->read($site)->annotationStoragePid;

        if (null === $storagePid || !$this->mayCreateOn($storagePid)) {
            return null;
        }

        return (string) $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE => [$storagePid => 'new']],
            'defVals' => [self::TABLE => [
                'site_identifier' => $site->getIdentifier(),
                // A Unix timestamp because the column is an int one (see
                // ext_tables.sql): FormEngine turns it into the date the
                // field shows before anyone sees the form.
                'date' => (string) $date->getTimestamp(),
                'page_uid' => (string) $pageUid,
            ]],
            'returnUrl' => (string) $this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]),
        ]);
    }

    /**
     * Both halves of what TYPO3 asks before a record may be created: the
     * user's group must allow the table at all, and the storage page must be
     * one they may add content to. Without the page check a site could point
     * at a page outside an editor's branch and the menu entry would promise
     * a form that refuses to save.
     */
    private function mayCreateOn(int $storagePid): bool
    {
        $backendUser = $this->getBackendUser();

        if (!$backendUser->check('tables_modify', self::TABLE)) {
            return false;
        }

        return false !== BackendUtility::readPageAccess($storagePid, $backendUser->getPagePermsClause(Permission::CONTENT_EDIT));
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
