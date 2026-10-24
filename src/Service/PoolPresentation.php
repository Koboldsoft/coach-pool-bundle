<?php
namespace Koboldsoft\CoachPoolBundle\Service;

class PoolPresentation
{
    /** Einheitliche, rein darstellende Aufbereitung für Karten und Vergabetabelle. */
    public static function forContact(array $p): array
    {
        $funding = (string) ($p['welche_forderung_mochtest_du_fur_dein_coaching_nutzen'] ?? '');
        $voucher = (string) ($p['hast_du_schon_einen_avgs_gutschein_oder_brauchst_du_unterstutzung_beim_antrag_'] ?? '');
        $promise = (string) ($p['falls_du_noch_keinen_avgs_hast_hattest_du_bereits_kontakt_mit_deinerdeinem_sachbearbeiterin_zum_avgs'] ?? '');
        $expiry = self::timestamp((string) ($p['gutscheingultigkeit'] ?? ''));
        $badge = ['text' => $funding ?: 'Förderung offen', 'class' => 'neutral'];
        if (preg_match('/drv|renten/i', $funding)) {
            $badge = ['text' => 'DRV-Förderung', 'class' => 'drv'];
        } elseif (!preg_match('/kein|nicht|nein/i', $voucher) && preg_match('/liegt.?vor|^ja\b|^ja_|habe.*gutschein|gutschein.*vorhanden/i', $voucher)) {
            $badge = ['text' => 'AVGS vorhanden', 'class' => 'ok'];
        } elseif (!preg_match('/kein|nicht|nein/i', $promise) && preg_match('/zusag|^ja\b|^ja_/i', $promise)) {
            $badge = ['text' => 'Zusage vom Amt', 'class' => 'promise'];
        }
        // Nur das Datum entscheidet über den zusätzlichen Ablaufhinweis, nicht über Pool-Zulassung.
        if ($expiry !== null && !preg_match('/drv|renten/i', $funding)) {
            $today = strtotime('today');
            if ($expiry < $today) { $badge = ['text' => 'Gutschein abgelaufen', 'class' => 'urgent']; }
            elseif ($expiry <= strtotime('+7 days', $today)) { $badge = ['text' => 'Gutschein läuft bald ab', 'class' => 'urgent']; }
        }
        return ['badge' => $badge, 'expiry' => $expiry ? date('d.m.Y', $expiry) : '',
            'received' => self::timestamp((string) ($p['createdate'] ?? '')) ? date('d.m.Y', self::timestamp((string) $p['createdate'])) : '',
            'chips' => ['type' => self::values((string) ($p['coachingtyp'] ?? '')),
                'target' => self::values((string) ($p['zielgruppe'] ?? '')),
                'industry' => self::values((string) ($p['branche__kategorie'] ?? ''))]];
    }

    private static function values(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(';', $value)), static function (string $v): bool { return $v !== ''; }));
    }

    private static function timestamp(string $value): ?int
    {
        if ($value === '') { return null; }
        if (ctype_digit($value)) { return (int) (strlen($value) >= 12 ? (int) $value / 1000 : $value); }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }
}
