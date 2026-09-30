<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\RecordFindings;
use App\Models\Build;
use App\Models\SecurityFinding;
use App\Services\Billing\Entitlements;
use App\Services\Security\DependencyCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class EvaluateSecurityGateController
{
    /**
     * Answer a deploy's Security gate from its lock files: "BLOCK" with the reason when a vulnerability at or above
     * the environment's gate severity is in them and hasn't been accepted (ignored) in Security, otherwise "PASS".
     * The URL is signed per build. What blocked it is recorded as findings (so they can be accepted); without Security
     * on the plan, or when the check fails, it passes.
     *
     * @param  Request  $request
     * @param  Build  $build
     * @param  DependencyCheck  $check
     * @param  Entitlements  $entitlements
     * @param  RecordFindings  $record
     * @return Response
     */
    public function __invoke(Request $request, Build $build, DependencyCheck $check, Entitlements $entitlements, RecordFindings $record): Response
    {
        $build->loadMissing(['environment.project.account', 'website']);
        $project = $build->environment?->project;
        $gate = $build->environment_payload['security_gate'] ?? null;
        if ($project === null || ! in_array($gate, ['critical', 'high'], true) || ! $project->hasService('security') || ! $entitlements->for($project->account)->has('security.deploy_gate')) {
            return $this->verdict('PASS');
        }
        $read = fn (string $field): ?string => $request->hasFile($field) && $request->file($field)?->getSize() <= 8_000_000 ? (string) $request->file($field)?->get() : null;
        try {
            $findings = $check->findings($read('composer'), $read('npm'), $build->website->name);
        } catch (Throwable $exception) {
            return $this->verdict('PASS Security couldn’t check the packages ('.str($exception->getMessage())->limit(120).'), so the deploy carries on.');
        }
        $live = 'website:'.$build->website_id;
        $gateScope = 'gate:'.$live;
        $blocking = [];
        $blockers = [];
        foreach ($findings as $finding) {
            $serious = $finding->severity === 'critical' || ($gate === 'high' && $finding->severity === 'high');
            $accepted = SecurityFinding::query()->where('project_id', $project->id)->where('status', 'ignored')
                ->whereIn('fingerprint', [SecurityFinding::fingerprint('dependencies', $live, $finding->key), SecurityFinding::fingerprint('dependencies', $gateScope, $finding->key)])->exists();
            if ($serious && ! $accepted) {
                $blockers[] = $finding;
                $blocking[] = ($finding->data['package'] ?? '?').' '.($finding->data['version'] ?? '').' ('.($finding->data['vulnerability'] ?? '').')';
            }
        }
        $record->handle($project, 'dependencies', $gateScope, $blockers);

        return $blocking === []
            ? $this->verdict('PASS No blocking vulnerabilities.')
            : $this->verdict('BLOCK Security stopped this deploy: '.implode(', ', array_slice($blocking, 0, 5)).(count($blocking) > 5 ? ' and '.(count($blocking) - 5).' more' : '').'. Update the packages, or accept the risk by ignoring the finding in Security.');
    }

    /**
     * Build the plain-text verdict the deploy script reads.
     *
     * @param  string  $text
     * @return Response
     */
    private function verdict(string $text): Response
    {
        return response($text, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
