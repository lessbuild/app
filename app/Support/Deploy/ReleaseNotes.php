<?php

declare(strict_types=1);

namespace App\Support\Deploy;

/**
 * Turns a deploy's commits into release notes: grouped by conventional commit type (feat, fix, perf; everything else
 * under Other changes), with the scope and prefix removed, pull request numbers kept, and chores, CI, docs, tests and
 * merge commits left out.
 */
final class ReleaseNotes
{
    /**
     * The sections, in order, with the commit types that go in each.
     *
     * @var array<string, list<string>>
     */
    private const SECTIONS = ['Features' => ['feat', 'feature'], 'Fixes' => ['fix', 'bugfix', 'hotfix'], 'Performance' => ['perf'], 'Other changes' => ['refactor', 'revert', 'style', '']];

    /**
     * The commit types left out of the notes.
     *
     * @var list<string>
     */
    private const HIDDEN = ['chore', 'ci', 'build', 'docs', 'test', 'tests'];

    /**
     * Read the commit list a deploy reports: one per line, fields separated by the unit separator (short hash, author,
     * subject). At most 200.
     *
     * @param  string  $text
     * @return list<array{sha: string, author: string, subject: string}>
     */
    public static function parse(string $text): array
    {
        $commits = [];
        foreach (array_slice(array_filter(explode("\n", $text)), 0, 200) as $line) {
            $fields = explode("\x1f", $line);
            if (count($fields) < 3 || preg_match('/\A[0-9a-f]{4,64}\z/', $fields[0]) !== 1) {
                continue;
            }
            $commits[] = ['sha' => $fields[0], 'author' => mb_substr(trim($fields[1]), 0, 80), 'subject' => mb_substr(trim($fields[2]), 0, 200)];
        }

        return $commits;
    }

    /**
     * Group the commits into sections of notes, leaving empty sections out.
     *
     * @param  list<array{sha: string, author: string, subject: string}>  $commits
     * @return array<string, list<string>>
     */
    public static function sections(array $commits): array
    {
        $sections = array_fill_keys(array_keys(self::SECTIONS), []);
        foreach ($commits as $commit) {
            $subject = $commit['subject'];
            if (str_starts_with($subject, 'Merge ')) {
                continue;
            }
            $type = '';
            if (preg_match('/\A(\w+)(?:\([^)]*\))?!?:\s*(.+)\z/', $subject, $match) === 1) {
                [$type, $subject] = [strtolower($match[1]), $match[2]];
            }
            if (in_array($type, self::HIDDEN, true)) {
                continue;
            }
            $section = 'Other changes';
            foreach (self::SECTIONS as $name => $types) {
                if (in_array($type, $types, true)) {
                    $section = $name;
                    break;
                }
            }
            $sections[$section][] = ucfirst($subject);
        }

        return array_filter($sections);
    }

    /**
     * Write the notes as plain text, for Slack and email.
     *
     * @param  list<array{sha: string, author: string, subject: string}>  $commits
     * @return string|null
     */
    public static function text(array $commits): ?string
    {
        $lines = [];
        foreach (self::sections($commits) as $section => $notes) {
            $lines[] = __($section).':';
            foreach (array_slice($notes, 0, 15) as $note) {
                $lines[] = '• '.$note;
            }
        }

        return $lines === [] ? null : mb_substr(implode("\n", $lines), 0, 1800);
    }
}
