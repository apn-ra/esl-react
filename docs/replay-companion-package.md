# Companion Replay Package Design Note

## Recommended package

Recommended future package: `apntalk/esl-replay`

This package should sit above the runtime and storage boundary:

- `apntalk/esl-core` = protocol and replay-envelope substrate
- `apntalk/esl-react` = live runtime and observational replay hooks
- `apntalk/esl-replay` = durable replay storage, artifact recovery, and replay execution

## Purpose

`apntalk/esl-replay` should own the concerns that do not belong in the live runtime:

- durable artifact storage
- storage adapter integration
- process-restart artifact recovery
- replay scheduling and replay execution
- replay-time reconstruction helpers
- replay-time re-injection into fake or purpose-built transports

`apntalk/esl-react` should continue to emit runtime artifacts only. It should not become the storage or replay execution owner.

## Storage adapter responsibilities

The companion package should own adapters such as:

- filesystem append-only writers/readers
- database persistence adapters
- object-store or queue-backed artifact sinks

These adapters should accept the stable replay artifacts emitted by `apntalk/esl-react` and persist them without requiring the live runtime to know anything about the storage backend.

## Artifact reader responsibilities

The companion package should provide:

- artifact readers for persisted replay streams
- integrity and ordering checks
- filtering by session, connection generation, artifact type, or job correlation
- resumable iteration for large replay streams

This reader layer is where process-restart continuity belongs. The live runtime should not attempt to rebuild pending runtime state from persisted artifacts inside its own process.

## Process-restart recovery semantics

Process-restart recovery should be explicit and storage-backed:

- recover persisted replay artifacts for a chosen runtime/session scope
- reconstruct the last durable point known to storage
- decide which pending work can be treated as incomplete, orphaned, or replayable

This is not the same as live reconnect inside `apntalk/esl-react`. Live reconnect is transient transport recovery. Process-restart recovery is a higher-layer orchestration concern and should remain outside the runtime package.

## Replay executor / re-injection semantics

Replay execution should also live in the companion package:

- consume persisted artifacts
- rebuild replay context
- feed artifacts into reconstruction hooks or a replay harness
- optionally re-inject protocol traffic into a fake ESL server or dedicated replay transport

This must not share the same control path as the live runtime instance. Replay execution is a separate mode, not an extension of the live connection supervisor.

## Relationship to existing packages

- `apntalk/esl-core`
  - owns replay-safe envelope primitives and reconstruction-hook contracts
- `apntalk/esl-react`
  - emits live runtime artifacts and nothing more
- `apntalk/esl-replay`
  - should own durable recording, artifact recovery, and replay execution

## Explicit non-goal for this repo

This repository should not implement:

- storage adapters
- process-restart replay recovery
- replay scheduling
- replay execution / re-injection

Those concerns belong in the future companion package, not in `apntalk/esl-react`.
