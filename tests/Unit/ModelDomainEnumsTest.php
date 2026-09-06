<?php

namespace Tests\Unit;

use App\Enums\BuildStatus;
use App\Enums\ServerCommandStatus;
use App\Enums\SignInMethod;
use App\Models\Build;
use App\Models\ServerCommandExecution;
use App\Models\SignInEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelDomainEnumsTest extends TestCase
{
    #[DataProvider('buildStates')]
    public function test_build_states_classify_active_work_and_preserve_public_string_values(BuildStatus $state, bool $active): void
    {
        $build = new Build(['status' => $state->value]);

        $this->assertSame($state, $build->statusEnum());
        $this->assertSame($active, $state->isActive());
        $this->assertSame(! $active, $state->isTerminal());
        $this->assertSame($active, in_array($build->status, Build::ACTIVE_STATUSES, true));
        $this->assertSame(! $active, in_array($build->status, Build::TERMINAL_STATUSES, true));
        $this->assertSame($state->value, $build->toArray()['status']);
    }

    #[DataProvider('commandStates')]
    public function test_command_states_classify_rerun_eligibility_without_changing_serialization(ServerCommandStatus $state, bool $active): void
    {
        $execution = new ServerCommandExecution(['status' => $state->value]);

        $this->assertSame($state, $execution->statusEnum());
        $this->assertSame($active, $state->isActive());
        $this->assertSame(! $active, $state->isTerminal());
        $this->assertSame($active, in_array($execution->status, ServerCommandExecution::ACTIVE_STATUSES, true));
        $this->assertSame(! $active, in_array($execution->status, ServerCommandExecution::TERMINAL_STATUSES, true));
        $this->assertSame($state->value, $execution->toArray()['status']);
    }

    #[DataProvider('authenticationMethods')]
    public function test_known_sign_in_methods_remain_serialized_strings(SignInMethod $method): void
    {
        $event = new SignInEvent(['method' => $method->value]);

        $this->assertSame($method, $event->methodEnum());
        $this->assertContains($event->method, SignInEvent::METHODS);
        $this->assertSame($method->value, $event->toArray()['method']);
    }

    public function test_unknown_historical_values_remain_readable_and_do_not_become_active_or_terminal_states(): void
    {
        $build = new Build(['status' => 'legacy-build-state']);
        $execution = new ServerCommandExecution(['status' => 'legacy-command-state']);
        $event = new SignInEvent(['method' => 'legacy-authentication']);

        $this->assertNull($build->statusEnum());
        $this->assertNull($execution->statusEnum());
        $this->assertNull($event->methodEnum());
        $this->assertSame('legacy-build-state', $build->toArray()['status']);
        $this->assertSame('legacy-command-state', $execution->toArray()['status']);
        $this->assertSame('legacy-authentication', $event->toArray()['method']);
        $this->assertNull((new Build)->statusEnum());
        $this->assertNull((new ServerCommandExecution)->statusEnum());
        $this->assertNull((new SignInEvent)->methodEnum());
    }

    /** @return array<string, array{BuildStatus, bool}> */
    public static function buildStates(): array
    {
        return [
            'queued' => [BuildStatus::Queued, true],
            'awaiting approval' => [BuildStatus::AwaitingApproval, true],
            'rejected' => [BuildStatus::Rejected, false],
            'deploying' => [BuildStatus::Deploying, true],
            'running' => [BuildStatus::Running, true],
            'timing out' => [BuildStatus::TimingOut, true],
            'succeeded' => [BuildStatus::Succeeded, false],
            'failed' => [BuildStatus::Failed, false],
            'canceled' => [BuildStatus::Canceled, false],
        ];
    }

    /** @return array<string, array{ServerCommandStatus, bool}> */
    public static function commandStates(): array
    {
        return [
            'queued' => [ServerCommandStatus::Queued, true],
            'running' => [ServerCommandStatus::Running, true],
            'succeeded' => [ServerCommandStatus::Succeeded, false],
            'failed' => [ServerCommandStatus::Failed, false],
            'canceled' => [ServerCommandStatus::Canceled, false],
        ];
    }

    /** @return array<string, array{SignInMethod}> */
    public static function authenticationMethods(): array
    {
        return [
            'password' => [SignInMethod::Password],
            'GitHub' => [SignInMethod::GitHub],
            'GitLab' => [SignInMethod::GitLab],
            'Bitbucket' => [SignInMethod::Bitbucket],
        ];
    }
}
