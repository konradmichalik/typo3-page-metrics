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
        // Both the root and a regular page, because this record used to live
        // at the root only. TYPO3 lets nobody but an admin write at pid 0, so
        // the people who cause the events a marker explains could not record
        // them. New records go on the page a site names as its
        // pageMetrics.annotations.storagePid, where the ordinary page
        // permissions decide who may create one; -1 rather than 0 keeps the
        // records existing installations already have at pid 0 editable
        // instead of stranding them in a place their own TCA forbids.
        'rootLevel' => -1,
    ],
    'columns' => [
        // "hidden" is the only exclude field here. Every other column carries
        // what the marker says, and a field an editor's group has not been
        // granted is not rendered and therefore never submitted: a marker
        // saved without its site or its text is one nobody will ever see.
        // The same split core makes on tt_content, where the content fields
        // are open and the access ones are not.
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.site_identifier',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => AnnotationSiteItemsProcFunc::class.'->populateSites',
                'required' => true,
            ],
        ],
        'date' => [
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.date',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
                'required' => true,
            ],
        ],
        'date_until' => [
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.date_until',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.date_until.description',
            'config' => [
                'type' => 'datetime',
                'format' => 'date',
            ],
        ],
        'category' => [
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
            'label' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.include_subpages',
            'description' => 'LLL:EXT:typo3_page_metrics/Resources/Private/Language/locallang_db.xlf:tx_typo3pagemetrics_manual_annotation.include_subpages.description',
            'displayCond' => 'FIELD:page_uid:REQ:true',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'text' => [
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
