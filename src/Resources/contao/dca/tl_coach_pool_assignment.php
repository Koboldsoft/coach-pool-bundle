<?php
// Dauerhaftes Freigabejournal schützt vor Doppelvergabe und unklaren API-Timeouts.
$GLOBALS['TL_DCA']['tl_coach_pool_assignment'] = [
    'config' => ['dataContainer' => 'Table', 'closed' => true, 'notEditable' => true, 'sql' => [
        'keys' => ['id' => 'primary', 'contact_id' => 'unique'],
    ]],
    'fields' => [
        'id' => ['sql' => 'int(10) unsigned NOT NULL auto_increment'],
        'tstamp' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'contact_id' => ['sql' => "varchar(32) NOT NULL default ''"],
        'reviewer_id' => ['sql' => "int(10) unsigned NOT NULL default '0'"],
        'coach_email' => ['sql' => "varchar(254) NOT NULL default ''"],
        'coach_id' => ['sql' => "varchar(32) NOT NULL default ''"],
        'status' => ['sql' => "varchar(16) NOT NULL default ''"],
    ],
];
