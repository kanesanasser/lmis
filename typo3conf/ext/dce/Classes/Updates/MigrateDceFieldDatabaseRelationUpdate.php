<?php
namespace ArminVieweg\Dce\Updates;

/*  | This extension is made for TYPO3 CMS and is licensed
 *  | under GNU General Public License.
 *  |
 *  | (c) 2012-2018 Armin Vieweg <armin@v.ieweg.de>
 */

/**
 * Migrate m:n-relation of dce fields to 1:n-relation
 */
class MigrateDceFieldDatabaseRelationUpdate extends AbstractUpdate
{
    /**
     * @var string
     */
    protected $title = 'EXT:dce Migrate m:n-relation of dce fields to 1:n-relation';

    /**
     * Checks whether updates are required.
     *
     * @param string &$description The description for the update
     * @return bool Whether an update is required (TRUE) or not (FALSE)
     * @throws \Doctrine\DBAL\DBALException
     */
    public function checkForUpdate(&$description)
    {
        // Check if "parent" and "sorting" fields are existing in DceField table
        $dceFieldTableFields = $this->getDatabaseConnection()->admin_get_fields('tx_dce_domain_model_dcefield');
        if (!array_key_exists('parent_dce', $dceFieldTableFields) ||
            !array_key_exists('parent_field', $dceFieldTableFields) ||
            !array_key_exists('sorting', $dceFieldTableFields)
        ) {
            $description .= '<div class="alert alert-warning"><strong>WARNING</strong><br>' .
                'The database table of DceFields has no <em>parent_dce</em> and/or <em>parent_field</em> and/or ' .
                '<em>sorting</em> column. Please execute <em>Compare current database with ' .
                'specification</em> in Important Actions section here in Install Tool.</div>';
            return true;
        }

        // Get updatable dce fields
        $updatableDceFields = $this->getUpdatableDceFields();
        if (\count($updatableDceFields) > 0) {
            // Check of source table is existing
            $dceFieldTableName = $this->getSourceTableNameForDceField();
            $secionFieldTableName = $this->getSourceTableNameForSectionField();
            if ($dceFieldTableName === null || $secionFieldTableName === null) {
                $description = '<div class="alert alert-danger"><strong>FATAL ERROR</strong><br> ' .
                    'The script was not able to find source tables!!! ' .
                    'Two of these tables are missing (one of each group): <ul>' .
                    '<ul style="margin-top: 10px; margin-bottom: 10px;">' .
                    '<li>tx_dce_dce_dcefield_mm</li>' .
                    '<li>zzz_deleted_tx_dce_dce_dcefield_mm</li>' .
                    '</ul><ul>' .
                    '<li>tx_dce_dcefield_sectionfields_mm</li>' .
                    '<li>zzz_deleted_tx_dce_dcefield_sectionfields_mm</li>' .
                    '</ul></div>';
                return true;
            }
            $description = '<div class="alert alert-info">' .
                'You have <b>' . \count($updatableDceFields) . ' dce fields</b> which need to get updated. ' .
                'The old relations are taking from <em>' . $dceFieldTableName .
                '</em> and <em>' . $secionFieldTableName . '</em> table.' . '</div>';
            return true;
        }
        return false;
    }

    /**
     * Performs the accordant updates.
     *
     * @param array &$dbQueries Queries done in this update
     * @param string|array &$customMessages Custom messages
     * @return bool Whether everything went smoothly or not
     * @throws \Doctrine\DBAL\DBALException
     */
    public function performUpdate(array &$dbQueries, &$customMessages)
    {
        $this->getDatabaseConnection()->store_lastBuiltQuery = true;
        $availableDces = $this->getDatabaseConnection()->exec_SELECTgetRows(
            'uid',
            'tx_dce_domain_model_dce',
            'deleted = 0',
            '',
            '',
            '',
            'uid'
        );

        $sectionFieldRelations = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            $this->getSourceTableNameForSectionField(),
            '1'
        );
        $this->storeLastQuery($dbQueries);

        foreach ($sectionFieldRelations as $sectionFieldRelation) {
            $updateValues = [
                'parent_field' => $sectionFieldRelation['uid_local'],
                'sorting' => $sectionFieldRelation['sorting']
            ];
            $this->getDatabaseConnection()->exec_UPDATEquery(
                'tx_dce_domain_model_dcefield',
                'uid=' . $sectionFieldRelation['uid_foreign'],
                $updateValues
            );
            $this->storeLastQuery($dbQueries);
        }


        $dceFieldRelations = $this->getDatabaseConnection()->exec_SELECTgetRows(
            'DISTINCT A.uid_local, A.uid_foreign',
            $this->getSourceTableNameForDceField() . ' A, ' . $this->getSourceTableNameForDceField() . ' B',
            'A.uid_foreign=B.uid_foreign AND A.uid_local != B.uid_local',
            '',
            'A.uid_foreign ASC'
        );
        $this->storeLastQuery($dbQueries);
        $dceFieldUid = 0;
        foreach ($dceFieldRelations as $dceFieldRelation) {
            if ($dceFieldUid == $dceFieldRelation['uid_foreign']) {
                $dceFields = $this->getDatabaseConnection()->exec_SELECTgetRows(
                    '*',
                    'tx_dce_domain_model_dcefield',
                    'uid=' . $dceFieldRelation['uid_foreign'],
                    '',
                    ''
                );
                $this->storeLastQuery($dbQueries);
                foreach ($dceFields as $dceField) {
                    $dceFieldData = $dceField;
                    unset($dceFieldData['uid']);
                    $this->getDatabaseConnection()->exec_INSERTquery(
                        'tx_dce_domain_model_dcefield',
                        $dceFieldData
                    );
                    $dceFieldInsertUid = $this->getDatabaseConnection()->sql_insert_id();
                    $this->storeLastQuery($dbQueries);


                    if ((int)$dceField['type'] == 2) {
                        $dceSectionFields = $this->getDatabaseConnection()->exec_SELECTgetRows(
                            'B.*',
                            'tx_dce_dcefield_sectionfields_mm A INNER JOIN tx_dce_domain_model_dcefield B ' .
                            'ON (A.uid_foreign=B.uid)',
                            'A.uid_local=' . $dceField['uid'],
                            '',
                            ''
                        );
                        $this->storeLastQuery($dbQueries);
                        foreach ($dceSectionFields as $dceSectionField) {
                            $dceSectionFieldData = $dceSectionField;
                            $dceSectionFieldData['parent_field'] = $dceFieldInsertUid;
                            unset($dceSectionFieldData['uid']);
                            $this->getDatabaseConnection()->exec_INSERTquery(
                                'tx_dce_domain_model_dcefield',
                                $dceSectionFieldData
                            );
                            $this->storeLastQuery($dbQueries);
                        }
                    }

                    $this->getDatabaseConnection()->exec_UPDATEquery(
                        $this->getSourceTableNameForDceField(),
                        'uid_local=' . $dceFieldRelation['uid_local'] .
                        ' AND uid_foreign=' . $dceFieldRelation['uid_foreign'],
                        [
                            'uid_foreign' => $dceFieldInsertUid
                        ]
                    );
                    $this->storeLastQuery($dbQueries);
                }
            }
            $dceFieldUid = $dceFieldRelation['uid_foreign'];
        }

        $dceFieldRelations = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            $this->getSourceTableNameForDceField(),
            '1'
        );
        $this->storeLastQuery($dbQueries);
        foreach ($dceFieldRelations as $dceFieldRelation) {
            if (!array_key_exists($dceFieldRelation['uid_local'], $availableDces)) {
                continue;
            }
            $updateValues = [
                'parent_dce' => $dceFieldRelation['uid_local'],
                'sorting' => $dceFieldRelation['sorting']
            ];
            $this->getDatabaseConnection()->exec_UPDATEquery(
                'tx_dce_domain_model_dcefield',
                'uid=' . $dceFieldRelation['uid_foreign'],
                $updateValues
            );
            $this->storeLastQuery($dbQueries);
        }

        $remainingDceFields = $this->getUpdatableDceFields();
        $this->storeLastQuery($dbQueries);
        if (\count($remainingDceFields) > 0) {
            $dceFieldUids = [];
            foreach ($remainingDceFields as $remainingDceField) {
                $dceFieldUids[] = $remainingDceField['uid'];
            }
            $dceFieldUids = implode(',', $dceFieldUids);

            $message = 'After the update ' . \count($remainingDceFields) . ' remain without parent value. ' .
                'This means, no MM relation was existing for these fields. So they were lost in the ' .
                'past anyway. Setting deleted=1 to these fields. (uids: ' . $dceFieldUids . ')';

            if (\is_array($customMessages)) {
                $customMessages[] = $message;
            } else {
                $customMessages = $message;
            }

            $this->getDatabaseConnection()->exec_UPDATEquery(
                'tx_dce_domain_model_dcefield',
                'uid IN (' . $dceFieldUids . ')',
                ['deleted' => '1']
            );
            $this->storeLastQuery($dbQueries);
        }
        return true;
    }

    /**
     * Returns DceFields without set parent field
     *
     * @return array DceField row
     */
    protected function getUpdatableDceFields()
    {
        return $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'tx_dce_domain_model_dcefield',
            'parent_dce=0 AND parent_field=0 AND deleted=0'
        );
    }

    /**
     * Get source table name for DceField. If no source table existing
     * the method returns null. Otherwise the table name.
     *
     * @return string|null
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getSourceTableNameForDceField()
    {
        if (array_key_exists('tx_dce_dce_dcefield_mm', $this->getDatabaseConnection()->admin_get_tables())) {
            return 'tx_dce_dce_dcefield_mm';
        }
        if (array_key_exists(
            'zzz_deleted_tx_dce_dce_dcefield_mm',
            $this->getDatabaseConnection()->admin_get_tables()
        )) {
            return 'zzz_deleted_tx_dce_dce_dcefield_mm';
        }
        return null;
    }

    /**
     * Get source table name for Section Field. If no source table existing
     * the method returns null. Otherwise the table name.
     *
     * @return string|null
     * @throws \Doctrine\DBAL\DBALException
     */
    protected function getSourceTableNameForSectionField()
    {
        if (array_key_exists('tx_dce_dcefield_sectionfields_mm', $this->getDatabaseConnection()->admin_get_tables())) {
            return 'tx_dce_dcefield_sectionfields_mm';
        }
        if (array_key_exists(
            'zzz_deleted_tx_dce_dcefield_sectionfields_mm',
            $this->getDatabaseConnection()->admin_get_tables()
        )) {
            return 'zzz_deleted_tx_dce_dcefield_sectionfields_mm';
        }
        return null;
    }
}
