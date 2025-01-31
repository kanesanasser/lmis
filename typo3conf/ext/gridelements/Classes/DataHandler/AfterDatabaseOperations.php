<?php

namespace GridElementsTeam\Gridelements\DataHandler;

/***************************************************************
 *  Copyright notice
 *  (c) 2013 Jo Hasenau <info@cybercraft.de>
 *  All rights reserved
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class/Function which offers TCE main hook functions.
 *
 * @author  Jo Hasenau <info@cybercraft.de>
 * @package  TYPO3
 * @subpackage tx_gridelements
 */
class AfterDatabaseOperations extends AbstractDataHandler
{
    /**
     * Function to set the colPos of an element depending on
     * whether it is a child of a parent container or not
     * will set colPos according to availability of the current grid column of an element
     * 0 = no column at all
     * -1 = grid element column
     * -2 = non used elements column
     * changes are applied to the field array of the parent object by reference
     *
     * @param array $fieldArray The array of fields and values that have been saved to the datamap
     * @param string $table The name of the table the data should be saved to
     * @param int $uid the ID of the record
     * @param DataHandler $parentObj The parent object that triggered this hook
     */
    public function execute_afterDatabaseOperations(array &$fieldArray, $table, $uid, DataHandler $parentObj)
    {
        if ($table === 'tt_content') {
            $this->init($table, $uid, $parentObj);
            if (!$this->getTceMain()->isImporting) {
                $this->saveCleanedUpFieldArray($fieldArray);
                if ((int)$uid > 0) {
                    $this->checkAndUpdateTranslatedElements($uid);
                }
            }
        }
    }

    /**
     * save cleaned up field array
     *
     * @param array $changedFieldArray
     */
    public function saveCleanedUpFieldArray(array $changedFieldArray)
    {
        unset($changedFieldArray['pi_flexform']);
        if (isset($changedFieldArray['tx_gridelements_backend_layout']) && $this->getTable() === 'tt_content'
            || isset($changedFieldArray['backend_layout']) && $this->getTable() === 'pages'
            || isset($changedFieldArray['backend_layout_next_level']) && $this->getTable() === 'pages'
        ) {
            $this->setUnusedElements($changedFieldArray);
        }
    }

    /**
     * Function to move elements to/from the unused elements column while changing the layout of a page or a grid element
     *
     * @param array $fieldArray The array of fields and values that have been saved to the datamap
     */
    public function setUnusedElements(array &$fieldArray)
    {
        $changedGridElements = [];
        $changedElements = [];
        $changedSubPageElements = [];

        if ($this->getTable() === 'tt_content') {
            $changedGridElements[$this->getPageUid()] = true;
            $childElementsInUnavailableColumns = [];
            $childElementsInAvailableColumns = [];
            $availableColumns = $this->getAvailableColumns($fieldArray['tx_gridelements_backend_layout'], 'tt_content',
                $this->getPageUid());
            if (!empty($availableColumns) || $availableColumns === '0') {
                $availableColumns = GeneralUtility::intExplode(',', $availableColumns);
                $queryBuilder = $this->getQueryBuilder();
                $childElementsInUnavailableColumnsQuery = $queryBuilder
                    ->select('uid')
                    ->from('tt_content')
                    ->where(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->eq('tx_gridelements_container',
                                $queryBuilder->createNamedParameter((int)$this->getPageUid(), \PDO::PARAM_INT)),
                            $queryBuilder->expr()->notIn('tx_gridelements_columns',
                                $queryBuilder->createNamedParameter($availableColumns, Connection::PARAM_INT_ARRAY))
                        )
                    )
                    ->execute();
                $childElementsInUnavailableColumns = [];
                while ($childElementInUnavailableColumns = $childElementsInUnavailableColumnsQuery->fetch()) {
                    $childElementsInUnavailableColumns[] = $childElementInUnavailableColumns['uid'];
                }
                if (!empty($childElementsInUnavailableColumns)) {
                    $queryBuilder
                        ->update('tt_content')
                        ->where(
                            $queryBuilder->expr()->in('uid',
                                $queryBuilder->createNamedParameter($childElementsInUnavailableColumns,
                                    Connection::PARAM_INT_ARRAY))
                        )
                        ->set('colPos', -2)
                        ->set('backupColPos', -1)
                        ->execute();
                    array_flip($childElementsInUnavailableColumns);
                }

                $queryBuilder = $this->getQueryBuilder();
                $childElementsInAvailableColumnsQuery = $queryBuilder
                    ->select('uid')
                    ->from('tt_content')
                    ->where(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->eq('tx_gridelements_container',
                                $queryBuilder->createNamedParameter((int)$this->getPageUid(), \PDO::PARAM_INT)),
                            $queryBuilder->expr()->in('tx_gridelements_columns',
                                $queryBuilder->createNamedParameter($availableColumns, Connection::PARAM_INT_ARRAY))
                        )
                    )
                    ->execute();
                $childElementsInAvailableColumns = [];
                while ($childElementInAvailableColumns = $childElementsInAvailableColumnsQuery->fetch()) {
                    $childElementsInAvailableColumns[] = $childElementInAvailableColumns['uid'];
                }
                if (!empty($childElementsInAvailableColumns)) {
                    $queryBuilder
                        ->update('tt_content')
                        ->where(
                            $queryBuilder->expr()->in('uid',
                                $queryBuilder->createNamedParameter($childElementsInAvailableColumns,
                                    Connection::PARAM_INT_ARRAY))
                        )
                        ->set('colPos', -1)
                        ->set('backupColPos', -2)
                        ->execute();
                    array_flip($childElementsInAvailableColumns);
                }
            }
            $changedGridElements = $changedGridElements + $childElementsInUnavailableColumns + $childElementsInAvailableColumns;
        }

        if ($this->getTable() === 'pages') {
            $backendLayoutUid = 0;
            $backendLayoutNextLevelUid = 0;
            $rootline = BackendUtility::BEgetRootLine($this->getPageUid());
            for ($i = count($rootline); $i > 0; $i--) {
                $page = BackendUtility::getRecord('pages', (int)$rootline[$i]['uid'],
                    'uid,backend_layout,backend_layout_next_level');
                $selectedBackendLayoutNextLevel = (int)$page['backend_layout_next_level'];
                if ($page['uid'] === $this->getPageUid()) {
                    if ($fieldArray['backend_layout_next_level'] !== 0) {
                        // Backend layout for sub pages of the current page is set
                        $backendLayoutNextLevelUid = (int)$fieldArray['backend_layout_next_level'];
                    }
                    if ($fieldArray['backend_layout'] !== 0) {
                        // Backend layout for current page is set
                        $backendLayoutUid = $fieldArray['backend_layout'];
                        break;
                    }
                } else {
                    if ($selectedBackendLayoutNextLevel === -1 && $page['uid'] !== $this->getPageUid()) {
                        // Some previous page in our rootline sets layout_next to "None"
                        break;
                    } else {
                        if ($selectedBackendLayoutNextLevel > 0 && $page['uid'] !== $this->getPageUid()) {
                            // Some previous page in our rootline sets some backend_layout, use it
                            $backendLayoutUid = $selectedBackendLayoutNextLevel;
                            break;
                        }
                    }
                }
            }

            if (isset($fieldArray['backend_layout'])) {
                $availableColumns = $this->getAvailableColumns($backendLayoutUid, 'pages', $this->getPageUid());
                $availableColumns = GeneralUtility::intExplode(',', $availableColumns);
                $queryBuilder = $this->getQueryBuilder();
                $elementsInUnavailableColumnsQuery = $queryBuilder
                    ->select('uid')
                    ->from('tt_content')
                    ->where(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->eq('pid',
                                $queryBuilder->createNamedParameter((int)$this->getPageUid(), \PDO::PARAM_INT)),
                            $queryBuilder->expr()->notIn('colPos',
                                $queryBuilder->createNamedParameter($availableColumns, Connection::PARAM_INT_ARRAY))
                        )
                    )
                    ->execute();
                $elementsInUnavailableColumns = [];
                while ($elementInUnavailableColumns = $elementsInUnavailableColumnsQuery->fetch()) {
                    $elementsInUnavailableColumns[] = $elementInUnavailableColumns['uid'];
                }
                if (!empty($elementsInUnavailableColumns)) {
                    $queryBuilder
                        ->update('tt_content')
                        ->where(
                            $queryBuilder->expr()->in('uid',
                                $queryBuilder->createNamedParameter($elementsInUnavailableColumns,
                                    Connection::PARAM_INT_ARRAY))
                        )
                        ->set('backupColPos', $queryBuilder->quoteIdentifier('colPos'))
                        ->set('colPos', -2)
                        ->execute();
                    array_flip($elementsInUnavailableColumns);
                }

                $queryBuilder = $this->getQueryBuilder();
                $elementsInAvailableColumnsQuery = $queryBuilder
                    ->select('uid')
                    ->from('tt_content')
                    ->where(
                        $queryBuilder->expr()->andX(
                            $queryBuilder->expr()->eq('pid',
                                $queryBuilder->createNamedParameter((int)$this->getPageUid(), \PDO::PARAM_INT)),
                            $queryBuilder->expr()->neq('backupColPos',
                                $queryBuilder->createNamedParameter(-2, \PDO::PARAM_INT)),
                            $queryBuilder->expr()->in('backupColPos',
                                $queryBuilder->createNamedParameter($availableColumns, Connection::PARAM_INT_ARRAY))
                        )
                    )
                    ->execute();
                $elementsInAvailableColumns = [];
                while ($elementInAvailableColumns = $elementsInAvailableColumnsQuery->fetch()) {
                    $elementsInAvailableColumns[] = $elementInAvailableColumns['uid'];
                }
                if (!empty($elementsInAvailableColumns)) {
                    $queryBuilder
                        ->update('tt_content')
                        ->where(
                            $queryBuilder->expr()->in('uid',
                                $queryBuilder->createNamedParameter($elementsInAvailableColumns,
                                    Connection::PARAM_INT_ARRAY))
                        )
                        ->set('colPos', $queryBuilder->quoteIdentifier('backupColPos'))
                        ->set('backupColPos', -2)
                        ->execute();
                    array_flip($elementsInAvailableColumns);
                }
                $changedElements = $elementsInUnavailableColumns + $elementsInAvailableColumns;
            }

            if (isset($fieldArray['backend_layout_next_level'])) {
                $backendLayoutUid = $backendLayoutNextLevelUid ? $backendLayoutNextLevelUid : $backendLayoutUid;
                $subPages = [];
                $this->getSubPagesRecursively($this->getPageUid(), $subPages);
                if (!empty($subPages)) {
                    $changedSubPageElements = [];
                    foreach ($subPages as $page) {
                        $availableColumns = $this->getAvailableColumns($backendLayoutUid, 'pages', $page['uid']);
                        $availableColumns = GeneralUtility::intExplode(',', $availableColumns);
                        $queryBuilder = $this->getQueryBuilder();
                        $subPageElementsInUnavailableColumnsQuery = $queryBuilder
                            ->select('uid')
                            ->from('tt_content')
                            ->where(
                                $queryBuilder->expr()->andX(
                                    $queryBuilder->expr()->eq('pid',
                                        $queryBuilder->createNamedParameter((int)$page['uid'], \PDO::PARAM_INT)),
                                    $queryBuilder->expr()->notIn('colPos',
                                        $queryBuilder->createNamedParameter($availableColumns,
                                            Connection::PARAM_INT_ARRAY))
                                )
                            )
                            ->execute();
                        $subPageElementsInUnavailableColumns = [];
                        while ($subPageElementInUnavailableColumns = $subPageElementsInUnavailableColumnsQuery->fetch()) {
                            $subPageElementsInUnavailableColumns[] = $subPageElementInUnavailableColumns['uid'];
                        }
                        if (!empty($subPageElementsInUnavailableColumns)) {
                            $queryBuilder
                                ->update('tt_content')
                                ->where(
                                    $queryBuilder->expr()->in('uid',
                                        $queryBuilder->createNamedParameter($subPageElementsInUnavailableColumns,
                                            Connection::PARAM_INT_ARRAY))
                                )
                                ->set('backupColPos', $queryBuilder->quoteIdentifier('colPos'), false)
                                ->set('colPos', -2)
                                ->execute();
                            array_flip($subPageElementsInUnavailableColumns);
                        }

                        $queryBuilder = $this->getQueryBuilder();
                        $subPageElementsInAvailableColumnsQuery = $queryBuilder
                            ->select('uid')
                            ->from('tt_content')
                            ->where(
                                $queryBuilder->expr()->andX(
                                    $queryBuilder->expr()->eq('pid',
                                        $queryBuilder->createNamedParameter((int)$page['uid'], \PDO::PARAM_INT)),
                                    $queryBuilder->expr()->neq('backupColPos',
                                        $queryBuilder->createNamedParameter(-2, \PDO::PARAM_INT)),
                                    $queryBuilder->expr()->in('backupColPos',
                                        $queryBuilder->createNamedParameter($availableColumns,
                                            Connection::PARAM_INT_ARRAY))
                                )
                            )
                            ->execute();
                        $subPageElementsInAvailableColumns = [];
                        while ($subPageElementInAvailableColumns = $subPageElementsInAvailableColumnsQuery->fetch()) {
                            $subPageElementsInAvailableColumns[] = $subPageElementInAvailableColumns['uid'];
                        }
                        if (!empty($subPageElementsInAvailableColumns)) {
                            $queryBuilder
                                ->update('tt_content')
                                ->where(
                                    $queryBuilder->expr()->in('uid',
                                        $queryBuilder->createNamedParameter($subPageElementsInAvailableColumns,
                                            Connection::PARAM_INT_ARRAY))
                                )
                                ->set('colPos', $queryBuilder->quoteIdentifier('backupColPos'))
                                ->set('backupColPos', -2)
                                ->execute();
                            array_flip($subPageElementsInAvailableColumns);
                        }

                        $changedPageElements = $subPageElementsInUnavailableColumns + $subPageElementsInAvailableColumns;
                        $changedSubPageElements = $changedSubPageElements + $changedPageElements;
                    }
                }
            }
        }

        $changedElementUids = $changedGridElements + $changedElements + $changedSubPageElements;
        if (!empty($changedElementUids)) {
            foreach ($changedElementUids as $uid => $value) {
                $this->dataHandler->updateRefIndex('tt_content', $uid);
            }
        }
    }

    /**
     * fetches all available columns for a certain grid container based on TCA settings and layout records
     *
     * @param string $layout The selected backend layout of the grid container or the page
     * @param string $table The name of the table to get the layout for
     * @param int $id he uid of the parent container - being the page id for the table "pages"
     *
     * @return string The columns available for the selected layout as CSV list
     */
    public function getAvailableColumns($layout = '', $table = '', $id = 0)
    {
        $tcaColumns = [];

        if ($layout && $table === 'tt_content') {
            $tcaColumns = $this->layoutSetup->getLayoutColumns($layout);
            $tcaColumns = $tcaColumns['CSV'];
        } elseif ($table === 'pages') {
            $tcaColumns = GeneralUtility::callUserFunction(BackendLayoutView::class . '->getColPosListItemsParsed',
                $id, $this);
            $temp = [];
            foreach ($tcaColumns AS $item) {
                if (trim($item[1]) !== '') {
                    $temp[] = (int)$item[1];
                }
            }
            // Implode into a CSV string as BackendLayoutView->getColPosListItemsParsed returns an array
            $tcaColumns = '-2,-1,' . implode(',', $temp);
        }
        return $tcaColumns;
    }

    /**
     * gets all subpages of the current page and traverses recursively unless backend_layout_next_level is set or unset (!= 0)
     *
     * @param int $pageUid
     * @param array $subPages
     */
    public function getSubPagesRecursively($pageUid, array &$subPages)
    {
        $childPages = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->select(
                ['uid', 'backend_layout', 'backend_layout_next_level'],
                'pages',
                ['pid' => (int)$pageUid]
            )->fetchAll();

        if (!empty($childPages)) {
            foreach ($childPages as $page) {
                if (empty($page['backend_layout'])) {
                    $subPages[] = $page;
                }
                if (empty($page['backend_layout_next_level'])) {
                    $this->getSubPagesRecursively($page['uid'], $subPages);
                }
            }
        }
    }
}
