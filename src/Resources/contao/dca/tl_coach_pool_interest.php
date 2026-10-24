<?php
// Technical delivery journal, deliberately not a frontend-editable data container.
$GLOBALS['TL_DCA']['tl_coach_pool_interest'] = [
    'config' => ['dataContainer' => 'Table', 'closed' => true, 'notEditable' => true, 'sql' => [
        'keys' => ['id' => 'primary', 'member_id,contact_id' => 'unique'],
    ]],
    'fields' => [
        'id' => ['sql' => "int(10) unsigned NOT NULL auto_increment"],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'member_id' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'contact_id' => ['sql' => "varchar(32) NOT NULL default ''"],
        'status' => ['sql' => "varchar(16) NOT NULL default ''"],
        'task_id' => ['sql' => "varchar(32) NOT NULL default ''"],
    ],
];
