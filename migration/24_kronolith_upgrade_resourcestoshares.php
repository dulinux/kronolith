<?php
/**
 * @author   Michael J. Rubinsky <mrubinsk@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/gpl GPL
 * @package  Kronolith
 */

require_once __DIR__ . '/../lib/Kronolith.php';

/**
 * Add hierarchcal related columns to the legacy sql share driver
 *
 * Copyright 2011-2015 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (GPL). If you
 * did not receive this file, see http://www.horde.org/licenses/gpl.
 *
 * @author   Michael J. Rubinsky <mrubinsk@horde.org>
 * @category Horde
 * @license  http://www.horde.org/licenses/gpl GPL
 * @package  Kronolith
 */
class KronolithUpgradeResourcesToShares extends Horde_Db_Migration_Base
{
    /**
     * Upgrade.
     */
    public function up()
    {
        // shares ng
        $this->addColumn('kronolith_sharesng', 'attribute_email','text');
        $this->addColumn('kronolith_sharesng', 'attribute_members','text');
        $this->addColumn('kronolith_sharesng', 'attribute_response_type','integer');
        $this->addColumn('kronolith_sharesng', 'attribute_type', 'integer');
        $this->addColumn('kronolith_sharesng', 'attribute_isgroup', 'boolean', array('default' => false));

        // legacy shares
        $this->addColumn('kronolith_shares', 'attribute_email','text');
        $this->addColumn('kronolith_shares', 'attribute_members','text');
        $this->addColumn('kronolith_shares', 'attribute_response_type','integer');
        $this->addColumn('kronolith_shares', 'attribute_type', 'integer');
        $this->addColumn('kronolith_shares', 'attribute_isgroup', 'boolean', array('default' => false));

        /** Migrate existing resources to shares */
        $rows = $this->_connection->selectAll('SELECT * FROM kronolith_resources');
        $shares = $GLOBALS['injector']
             ->getInstance('Horde_Core_Factory_Share')
             ->create('kronolith');
        foreach ($rows as $row) {
            $share = $shares->newShare(
                null,
                $row['resource_calendar'],
                $row['resource_name']
            );
            $share->set('desc', $row['resource_description']);
            $share->set('email', $row['resource_email']);
            $share->set('response_type', $row['resource_response_type']);
            $share->set('type', Kronolith::SHARE_TYPE_RESOURCE);
            $share->set('isgroup', $row['resource_type'] == Kronolith_Resource::TYPE_GROUP);
            $share->set('members', $row['resource_members']);

            /* Perms to match existing behavior */
            $share->addDefaultPermission(Horde_Perms::SHOW);
            $share->addDefaultPermission(Horde_Perms::READ);
            $share->addDefaultPermission(Horde_Perms::EDIT);

            $share->save();
        }
    }

    /**
     * Downgrade
     */
    public function down()
    {
        $shares = $GLOBALS['injector']
             ->getInstance('Horde_Core_Factory_Share')
             ->create('kronolith');

        $resources = $shares->listShares(
            null,
            array('attributes' => array('type' => Kronolith::SHARE_TYPE_RESOURCE))
        );

        foreach ($resources as $resource) {
            $shares->removeShare($resource);
        }

        $this->removeColumn('kronolith_sharesng', 'attribute_email');
        $this->removeColumn('kronolith_sharesng', 'attribute_members');
        $this->removeColumn('kronolith_sharesng', 'attribute_response_type');
        $this->removeColumn('kronolith_sharesng', 'attribute_type');
        $this->removeColumn('kronolith_sharesng', 'attribute_isgroup');

        $this->removeColumn('kronolith_shares', 'attribute_email');
        $this->removeColumn('kronolith_shares', 'attribute_members');
        $this->removeColumn('kronolith_shares', 'attribute_response_type');
        $this->removeColumn('kronolith_shares', 'attribute_type');
        $this->removeColumn('kronolith_shares', 'attribute_isgroup');
    }

}
