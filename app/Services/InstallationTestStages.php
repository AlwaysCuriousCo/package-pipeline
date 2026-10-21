<?php

namespace App\Services;

/** The onboarding summary follows the same lines as the streamed transcript. */
final class InstallationTestStages
{
    /**
     * @param  list<array{text: string, tone: string}>  $lines
     * @return array{stages: list<array{label: string, state: string}>, passed: ?bool, failure: ?string, hint: ?string}
     */
    public static function fromTranscript(array $lines, bool $public = false): array
    {
        $labels = [
            'credentials' => $public ? 'Checking access to the public registry' : 'Checking that the registry asks for credentials',
            'details' => 'Reading package details',
            'download' => 'Downloading the package',
            'verify' => 'Verifying the download',
        ];
        $states = array_fill_keys(array_keys($labels), 'pending');
        $active = 'credentials';
        $passed = null;
        $failure = null;
        $hint = null;

        foreach ($lines as $line) {
            $next = match (true) {
                str_starts_with($line['text'], 'Checking ') => 'credentials',
                $line['text'] === 'Reading package details' => 'details',
                $line['text'] === 'Downloading the package' => 'download',
                $line['text'] === 'Verifying the download' => 'verify',
                default => null,
            };
            if ($next !== null) {
                if ($next !== $active && $states[$active] !== 'failed') {
                    $states[$active] = 'passed';
                }
                $active = $next;
                $states[$active] = 'running';
            }
            if ($line['tone'] === 'ok') {
                $states[$active] = 'passed';
            } elseif ($line['tone'] === 'fail') {
                $states[$active] = 'failed';
                $failure = $labels[$active];
                $passed = false;
            } elseif ($line['tone'] === 'hint') {
                $hint = preg_replace('/^Hint:\s*/', '', $line['text']);
            } elseif ($line['tone'] === 'done') {
                $states = array_fill_keys(array_keys($labels), 'passed');
                $passed = true;
            }
        }

        $stages = [];
        foreach ($labels as $key => $label) {
            $stages[] = ['label' => $label, 'state' => $states[$key]];
        }

        return ['stages' => $stages, 'passed' => $passed, 'failure' => $failure, 'hint' => $hint];
    }
}
