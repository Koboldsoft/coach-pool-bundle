<?php
namespace Koboldsoft\CoachPoolBundle\Backend;

use Contao\DataContainer;
use Contao\Database;

class ReviewerOptions
{
    /** Die Backend-Auswahl bietet nur Coaches des für dieses Modul gewählten Teams an. */
    public static function getOptions(DataContainer $dc): array
    {
        $db = Database::getInstance();
        $module = $db->prepare('SELECT coachPoolTeam FROM tl_module WHERE id = ?')->execute($dc->id);
        if (!$module->numRows || !(int) $module->coachPoolTeam) { return []; }
        $members = $db->prepare('SELECT DISTINCT m.id, m.firstname, m.lastname FROM tl_member m INNER JOIN mm_vertriebsteam_member t ON t.id_coach = m.id WHERE t.id_vertriebsteam = ? ORDER BY m.lastname, m.firstname')->execute($module->coachPoolTeam);
        $options = [];
        while ($members->next()) {
            $options[$members->id] = trim($members->firstname.' '.$members->lastname).' (ID '.$members->id.')';
        }
        return $options;
    }
}
