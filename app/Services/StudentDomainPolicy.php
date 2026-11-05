<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class StudentDomainPolicy
{
    public function institution(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $labels = explode('.', $domain);
        while (count($labels) > 2) {
            if (DB::table('student_email_domains')->where('domain', implode('.', $labels))->where('enabled', false)->exists()) return null;
            array_shift($labels);
        }
        $row = DB::table('student_email_domains')->where('domain', $domain)->first();
        if ($row) return $row->enabled && $row->country === 'NG' ? $row->institution : null;
        // A dot boundary excludes both edu.ng itself and deceptive suffixes.
        return str_ends_with($domain, '.edu.ng') ? 'Unknown institution ('.$domain.')' : null;
    }

    public static function personal(string $domain): bool
    {
        return in_array($domain, ['gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com'], true);
    }
}
