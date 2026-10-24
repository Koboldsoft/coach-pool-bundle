<?php
$GLOBALS['TL_DCA']['tl_module']['palettes']['coach_pool'] = '{title_legend},name,headline,type;{coach_pool_legend},coachPoolTeam,coachPoolIncludeInterested;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';
$GLOBALS['TL_DCA']['tl_module']['fields']['coachPoolTeam'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['coachPoolTeam'],
    'inputType' => 'select', 'foreignKey' => 'mm_vertriebsteam.team',
    'eval' => ['mandatory' => true, 'includeBlankOption' => true, 'submitOnChange' => true, 'chosen' => true, 'tl_class' => 'w50'],
    'sql' => "int(10) unsigned NOT NULL default '0'",
];
// Retain the existing column to avoid a destructive schema change; it is no longer used.
$GLOBALS['TL_DCA']['tl_module']['fields']['coachPoolOwner'] = ['sql' => "varchar(32) NOT NULL default ''"];
$GLOBALS['TL_DCA']['tl_module']['fields']['coachPoolDescription'] = ['sql' => "varchar(128) NOT NULL default ''"];

// Eigene Vergabeansicht: ohne explizite Prüferauswahl hat niemand Zugriff.
$GLOBALS['TL_DCA']['tl_module']['palettes']['coach_pool_review'] = '{title_legend},name,headline,type;{coach_pool_legend},coachPoolTeam,coachPoolReviewers;{protected_legend:hide},protected;{expert_legend:hide},guests,cssID';
$GLOBALS['TL_DCA']['tl_module']['fields']['coachPoolReviewers'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['coachPoolReviewers'],
    'inputType' => 'checkbox',
    'options_callback' => [\Koboldsoft\CoachPoolBundle\Backend\ReviewerOptions::class, 'getOptions'],
    'eval' => ['multiple' => true, 'tl_class' => 'clr'],
    'sql' => 'blob NULL',
];
$GLOBALS['TL_DCA']['tl_module']['fields']['coachPoolIncludeInterested'] = [
    'label' => &$GLOBALS['TL_LANG']['tl_module']['coachPoolIncludeInterested'],
    'inputType' => 'checkbox', 'default' => '1', 'eval' => ['tl_class' => 'clr'],
    'sql' => "char(1) NOT NULL default '1'",
];
