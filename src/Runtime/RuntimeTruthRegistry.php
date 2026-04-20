<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Runtime;

use Apntalk\EslCore\Vocabulary\BoundedVarianceMarker;
use Apntalk\EslCore\Vocabulary\DrainPosture;
use Apntalk\EslCore\Vocabulary\FinalityMarker;
use Apntalk\EslCore\Vocabulary\InFlightOperationId;
use Apntalk\EslCore\Vocabulary\LifecycleSemanticObservation;
use Apntalk\EslCore\Vocabulary\LifecycleSemanticState;
use Apntalk\EslCore\Vocabulary\LifecycleTransition;
use Apntalk\EslCore\Vocabulary\OrderingIdentity;
use Apntalk\EslCore\Vocabulary\PublicationId;
use Apntalk\EslCore\Vocabulary\PublicationSource;
use Apntalk\EslCore\Vocabulary\QueueState;
use Apntalk\EslCore\Vocabulary\ReconstructionPosture;
use Apntalk\EslCore\Vocabulary\RecoveryGenerationId;
use Apntalk\EslCore\Vocabulary\ReplayContinuity;
use Apntalk\EslCore\Vocabulary\RetryPosture;
use Apntalk\EslCore\Vocabulary\TerminalCause;
use Apntalk\EslCore\Vocabulary\TerminalPublication;
use Apntalk\EslReact\Runner\PreparedRuntimeRecoveryContext;
use Apntalk\EslReact\Runner\RuntimeLifecycleSemanticSnapshot;
use Apntalk\EslReact\Runner\RuntimeOperationSnapshot;
use Apntalk\EslReact\Runner\RuntimeRecoverySnapshot;
use Apntalk\EslReact\Runner\RuntimeTerminalPublicationSnapshot;

final class RuntimeTruthRegistry
{
    /** @var array<string, RuntimeOperationSnapshot> */
    private array $operations = [];

    /** @var list<RuntimeTerminalPublicationSnapshot> */
    private array $recentTerminalPublications = [];

    /** @var list<RuntimeLifecycleSemanticSnapshot> */
    private array $recentLifecycleSemantics = [];

    private int $operationSequence = 0;
    private RecoveryGenerationId $generationId;
    private ?float $generationStartedAtMicros;
    private RetryPosture $retryPosture = RetryPosture::NotRetryable;
    private DrainPosture $drainPosture = DrainPosture::NotDraining;
    private ReconstructionPosture $reconstructionPosture = ReconstructionPosture::Native;
    private ReplayContinuity $replayContinuity = ReplayContinuity::Continuous;
    private bool $preparedContextApplied = false;
    private bool $recoverableAfterReconnect = false;
    private bool $recoverableOnlyWithPreparedContext = false;
    private bool $terminallyNonRecoverable = false;
    private bool $recoveryInProgress = false;
    private ?string $lastRecoveryCause = null;
    private ?string $lastRecoveryOutcome = null;
    private ?string $lastDrainCause = null;
    private ?string $lastDrainOutcome = null;

    public function __construct(
        private readonly int $historyLimit = 12,
        private readonly ?PreparedRuntimeRecoveryContext $preparedContext = null,
    ) {
        $this->generationId = $preparedContext?->generationId() ?? RecoveryGenerationId::fromInteger(1);
        $this->generationStartedAtMicros = $preparedContext?->preparedAtMicros() ?? $this->nowMicros();
        $this->preparedContextApplied = $preparedContext !== null;
        $this->recoverableOnlyWithPreparedContext = $preparedContext?->recoverableOnlyWithPreparedContext() ?? false;
        $this->reconstructionPosture = $preparedContext?->reconstructionPosture() ?? ReconstructionPosture::Native;
        $this->replayContinuity = $preparedContext?->replayContinuity() ?? ReplayContinuity::Continuous;
    }

    public function nextOperationId(string $kind): InFlightOperationId
    {
        $sequence = ++$this->operationSequence;

        return InFlightOperationId::fromString(sprintf(
            '%s-g%s-%d',
            $kind,
            $this->generationId->toString(),
            $sequence,
        ));
    }

    public function recordAcceptedOperation(
        InFlightOperationId $operationId,
        string $kind,
        QueueState $queueState,
        int $connectionGeneration,
        ?string $jobUuid = null,
    ): void {
        $this->operations[$operationId->toString()] = new RuntimeOperationSnapshot(
            operationId: $operationId,
            kind: $kind,
            queueState: $queueState,
            connectionGeneration: $connectionGeneration,
            recoveryGenerationId: $this->generationId->toString(),
            acceptedAtMicros: $this->nowMicros(),
            lastProgressAtMicros: $this->nowMicros(),
            jobUuid: $jobUuid,
        );
    }

    public function promoteQueuedOperation(InFlightOperationId $operationId, int $connectionGeneration): void
    {
        $key = $operationId->toString();

        if (!isset($this->operations[$key])) {
            return;
        }

        $current = $this->operations[$key];
        $this->operations[$key] = new RuntimeOperationSnapshot(
            operationId: $current->operationId,
            kind: $current->kind,
            queueState: QueueState::InFlight,
            connectionGeneration: $connectionGeneration,
            recoveryGenerationId: $current->recoveryGenerationId,
            acceptedAtMicros: $current->acceptedAtMicros,
            lastProgressAtMicros: $this->nowMicros(),
            jobUuid: $current->jobUuid,
        );
    }

    public function markOperationDraining(InFlightOperationId $operationId, int $connectionGeneration): void
    {
        $key = $operationId->toString();

        if (!isset($this->operations[$key])) {
            return;
        }

        $current = $this->operations[$key];
        $this->operations[$key] = new RuntimeOperationSnapshot(
            operationId: $current->operationId,
            kind: $current->kind,
            queueState: QueueState::Draining,
            connectionGeneration: $connectionGeneration,
            recoveryGenerationId: $current->recoveryGenerationId,
            acceptedAtMicros: $current->acceptedAtMicros,
            lastProgressAtMicros: $this->nowMicros(),
            jobUuid: $current->jobUuid,
        );
    }

    public function assignBgapiJobUuid(InFlightOperationId $operationId, string $jobUuid, int $connectionGeneration): void
    {
        $key = $operationId->toString();

        if (!isset($this->operations[$key])) {
            return;
        }

        $current = $this->operations[$key];
        $this->operations[$key] = new RuntimeOperationSnapshot(
            operationId: $current->operationId,
            kind: $current->kind,
            queueState: QueueState::InFlight,
            connectionGeneration: $connectionGeneration,
            recoveryGenerationId: $current->recoveryGenerationId,
            acceptedAtMicros: $current->acceptedAtMicros,
            lastProgressAtMicros: $this->nowMicros(),
            jobUuid: $jobUuid,
        );
    }

    public function settleOperation(
        InFlightOperationId $operationId,
        PublicationSource $source,
        TerminalCause $cause,
        FinalityMarker $finality,
        int $connectionGeneration,
        ?string $subjectId = null,
    ): void {
        $key = $operationId->toString();

        if (!isset($this->operations[$key])) {
            return;
        }

        $current = $this->operations[$key];
        unset($this->operations[$key]);

        $publication = new TerminalPublication(
            publicationId: PublicationId::fromString(sprintf('publication-%s-%d', $operationId->toString(), $connectionGeneration)),
            finality: $finality,
            terminalCause: $cause,
            source: $source,
            publishedAtMicros: (int) $this->nowMicros(),
            orderingIdentity: OrderingIdentity::fromSourceAndValue('runtime-operation', $operationId->toString()),
            variance: match ($finality) {
                FinalityMarker::Ambiguous => BoundedVarianceMarker::Ambiguous,
                FinalityMarker::ProvisionalFinal => BoundedVarianceMarker::Provisional,
                default => BoundedVarianceMarker::None,
            },
        );

        $this->rememberTerminalPublication(new RuntimeTerminalPublicationSnapshot(
            publication: $publication,
            operationId: $current->operationId->toString(),
            subjectId: $subjectId,
        ));
    }

    public function recordLifecycleSemantic(
        LifecycleTransition $transition,
        string $orderingValue,
        ?string $subjectId,
        LifecycleSemanticState $state = LifecycleSemanticState::Confirmed,
        BoundedVarianceMarker $variance = BoundedVarianceMarker::None,
    ): void {
        $this->rememberLifecycleSemantic(new RuntimeLifecycleSemanticSnapshot(
            observation: new LifecycleSemanticObservation(
                transition: $transition,
                state: $state,
                orderingIdentity: OrderingIdentity::fromSourceAndValue('event-sequence', $orderingValue),
                subjectId: $subjectId,
                variance: $variance,
            ),
            observedAtMicros: $this->nowMicros(),
        ));
    }

    public function recordExternalTerminalPublication(
        PublicationSource $source,
        TerminalCause $cause,
        string $orderingValue,
        ?string $subjectId,
        FinalityMarker $finality = FinalityMarker::Final,
    ): void {
        $publication = new TerminalPublication(
            publicationId: PublicationId::fromString(sprintf('publication-external-%s-%s', $cause->value, $orderingValue)),
            finality: $finality,
            terminalCause: $cause,
            source: $source,
            publishedAtMicros: (int) $this->nowMicros(),
            orderingIdentity: OrderingIdentity::fromSourceAndValue('event-sequence', $orderingValue),
            variance: $finality === FinalityMarker::Ambiguous
                ? BoundedVarianceMarker::Ambiguous
                : BoundedVarianceMarker::None,
        );

        $this->rememberTerminalPublication(new RuntimeTerminalPublicationSnapshot(
            publication: $publication,
            subjectId: $subjectId,
        ));
    }

    public function beginReconnect(string $cause): void
    {
        $this->recoveryInProgress = true;
        $this->retryPosture = RetryPosture::Retrying;
        $this->reconstructionPosture = ReconstructionPosture::HookRequired;
        $this->replayContinuity = ReplayContinuity::GapDetected;
        $this->recoverableAfterReconnect = true;
        $this->terminallyNonRecoverable = false;
        $this->lastRecoveryCause = $cause;
        $this->lastRecoveryOutcome = 'reconnecting';
    }

    public function markReconnected(int $generation): void
    {
        if (!($generation === 1 && $this->hasPreparedRecoveryContext())) {
            $this->generationId = RecoveryGenerationId::fromInteger($generation);
        }
        $this->generationStartedAtMicros = $this->nowMicros();
        $this->retryPosture = RetryPosture::Retryable;
        if ($this->recoveryInProgress) {
            $this->recoverableAfterReconnect = true;
            $this->lastRecoveryOutcome = 'recovered_after_reconnect';
        }
        $this->recoveryInProgress = false;
    }

    public function markPreparedRecoveryApplied(): void
    {
        if ($this->preparedContext === null || !$this->preparedContext->isExplicitRecoveryBootstrap()) {
            return;
        }

        $this->generationId = $this->preparedContext->generationId();
        $this->generationStartedAtMicros = $this->preparedContext->preparedAtMicros() ?? $this->generationStartedAtMicros;
        $this->reconstructionPosture = $this->preparedContext->reconstructionPosture();
        $this->replayContinuity = $this->preparedContext->replayContinuity();
        $this->preparedContextApplied = true;
        $this->recoverableOnlyWithPreparedContext = $this->preparedContext->recoverableOnlyWithPreparedContext();
        $this->lastRecoveryOutcome = 'prepared_context_applied';
    }

    public function hasPreparedRecoveryContext(): bool
    {
        return $this->preparedContext?->isExplicitRecoveryBootstrap() ?? false;
    }

    public function markRecoveryFailedTerminal(string $cause, bool $recoverableOnlyWithPreparedContext): void
    {
        $this->recoveryInProgress = false;
        $this->retryPosture = RetryPosture::Exhausted;
        $this->lastRecoveryCause = $cause;
        $this->lastRecoveryOutcome = $recoverableOnlyWithPreparedContext
            ? 'recoverable_only_with_prepared_context'
            : 'terminal_non_recoverable';
        $this->recoverableAfterReconnect = false;
        $this->recoverableOnlyWithPreparedContext = $recoverableOnlyWithPreparedContext;
        $this->terminallyNonRecoverable = !$recoverableOnlyWithPreparedContext;
        $this->reconstructionPosture = $recoverableOnlyWithPreparedContext
            ? ReconstructionPosture::HookRequired
            : ReconstructionPosture::Unsupported;
        $this->replayContinuity = $recoverableOnlyWithPreparedContext
            ? ReplayContinuity::GapDetected
            : ReplayContinuity::Ambiguous;
    }

    public function markIdleRetryPosture(bool $retryEnabled): void
    {
        $this->retryPosture = $retryEnabled ? RetryPosture::Retryable : RetryPosture::NotRetryable;
    }

    public function requestDrain(string $cause): void
    {
        $this->drainPosture = DrainPosture::Requested;
        $this->lastDrainCause = $cause;
        $this->lastDrainOutcome = null;
    }

    public function beginDraining(string $cause): void
    {
        $this->drainPosture = DrainPosture::Draining;
        $this->lastDrainCause = $cause;
        $this->lastDrainOutcome = null;
    }

    public function markDrainComplete(): void
    {
        $this->drainPosture = DrainPosture::Drained;
        $this->lastDrainOutcome = 'completed';
    }

    public function markDrainTimedOut(): void
    {
        $this->drainPosture = DrainPosture::Interrupted;
        $this->lastDrainOutcome = 'timeout';
    }

    /**
     * @return list<RuntimeOperationSnapshot>
     */
    public function activeOperations(): array
    {
        return array_values($this->operations);
    }

    /**
     * @return list<RuntimeTerminalPublicationSnapshot>
     */
    public function recentTerminalPublications(): array
    {
        return $this->recentTerminalPublications;
    }

    /**
     * @return list<RuntimeLifecycleSemanticSnapshot>
     */
    public function recentLifecycleSemantics(): array
    {
        return $this->recentLifecycleSemantics;
    }

    public function recoverySnapshot(int $connectionGeneration): RuntimeRecoverySnapshot
    {
        return new RuntimeRecoverySnapshot(
            generationId: $this->generationId,
            connectionGeneration: $connectionGeneration,
            retryPosture: $this->retryPosture,
            drainPosture: $this->drainPosture,
            reconstructionPosture: $this->reconstructionPosture,
            replayContinuity: $this->replayContinuity,
            preparedContextApplied: $this->preparedContextApplied,
            isRecoverableAfterReconnect: $this->recoverableAfterReconnect,
            isRecoverableOnlyWithPreparedContext: $this->recoverableOnlyWithPreparedContext,
            isTerminallyNonRecoverable: $this->terminallyNonRecoverable,
            lastRecoveryCause: $this->lastRecoveryCause,
            lastRecoveryOutcome: $this->lastRecoveryOutcome,
            lastDrainCause: $this->lastDrainCause,
            lastDrainOutcome: $this->lastDrainOutcome,
            generationStartedAtMicros: $this->generationStartedAtMicros,
            preparedContext: $this->preparedContext,
        );
    }

    private function rememberTerminalPublication(RuntimeTerminalPublicationSnapshot $snapshot): void
    {
        $this->recentTerminalPublications[] = $snapshot;

        if (count($this->recentTerminalPublications) > $this->historyLimit) {
            array_shift($this->recentTerminalPublications);
        }
    }

    private function rememberLifecycleSemantic(RuntimeLifecycleSemanticSnapshot $snapshot): void
    {
        $this->recentLifecycleSemantics[] = $snapshot;

        if (count($this->recentLifecycleSemantics) > $this->historyLimit) {
            array_shift($this->recentLifecycleSemantics);
        }
    }

    private function nowMicros(): float
    {
        return microtime(true) * 1_000_000.0;
    }
}
