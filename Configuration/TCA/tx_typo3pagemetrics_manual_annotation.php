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

use KonradMichalik\Typo3PageMetrics\Domain\Enum\AnnotationCategory;
use KonradMichalik\Typo3PageMetrics\UserFunction\{AnnotationCategoryItemsProcFunc, AnnotationSiteItemsProcFunc};

return [
    'ctrl' => [
        'title' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation',
        'label' => 'text',
        'label_alt' => 'date',
        'label_alt_force' => true,
        'default_sortby' => 'date DESC',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'groupName' => 'content',
        'typeicon_classes' => [
            'default' => 'tabler-note',
        ],
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        // Root level only (pid=0), not on regular pages: an annotation is
        // scoped by its own "Site" field (see site_identifier below), not
        // by where it happens to sit in a page tree, so there is no
        // meaningful page to put it on - one shared list at the absolute
        // root keeps every site's annotations in a single, predictable
        // place instead of scattered across arbitrary pages. Same
        // rootLevel convention as be_users/be_groups/sys_file. Only admins
        // can create records at the true root (TYPO3 core requirement),
        // so this is an admin-managed record, similar to those tables.
        'rootLevel' => 1,
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'site_identifier' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.site_identifier',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => AnnotationSiteItemsProcFunc::class.'->populateSites',
                'required' => true,
            ],
        ],
        'date' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'required' => true,
            ],
        ],
        'date_until' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.date_until',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.date_until.description',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
            ],
        ],
        'category' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.category',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                // Built from the enum rather than repeated here, so a new
                // category cannot exist in one place and not the other.
                'itemsProcFunc' => AnnotationCategoryItemsProcFunc::class.'->populateCategories',
                'default' => AnnotationCategory::Release->value,
                'required' => true,
            ],
        ],
        'page_uid' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.page_uid',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.page_uid.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'pages',
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
        'include_subpages' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.include_subpages',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.include_subpages.description',
            'displayCond' => 'FIELD:page_uid:REQ:true',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'text' => [
            'exclude' => true,
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.text',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'max' => 255,
                'required' => true,
            ],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                site_identifier, category, date, date_until, text,
                --div--;LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.tab.scope, page_uid, include_subpages,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access, hidden,
            ',
        ],
    ],
];
