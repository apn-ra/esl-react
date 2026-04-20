# Release Prep — v0.2.11

This note summarizes the `v0.2.11` patch release after the successful
`apntalk/esl-core` `v0.2.13` consumer compatibility and release-readiness
passes plus the subsequent runtime hardening corrections that landed before
release finalization.

It is intended as a concise tag-prep aid and release-note source for the patch
release after `v0.2.10`.

## Scope of this patch

This release is a bounded upstream-consumer compatibility and regression-proof
release.

Implemented in this release:

- upgraded the runtime dependency to `apntalk/esl-core ^0.2.13`
- aligned inbound reply routing with the `v0.2.13` public
  `ClassifiedMessageInterface` contract
- removed the obsolete dependency on a distinct public auth-rejected
  classifier outcome
- hardened fake-server and hand-built `text/event-plain` fixtures for the
  stricter upstream parser expectations
- revalidated replay-envelope compatibility, including the additive
  `ReplayEnvelopeInterface` truth accessors exposed by `esl-core v0.2.13`
- hardened `api()` timeout handling to fail closed so ambiguous late replies
  cannot cross-wire into later commands on the same compromised connection
- hardened subscription/filter mutation promise semantics so normal runtime
  gating failures reject the returned promise instead of leaking synchronous
  throws on those promise-returning methods
- added deterministic proof for `handshake_protocol_failure` as a terminal
  reconnect stop reason on the runner feedback/status surfaces
- added deterministic proof that late orphaned `bgapi()` completion remains a
  no-op after timeout
- refreshed release-facing docs so dependency/support notes match the tested
  runtime truth

This patch does not introduce:

- new runtime features
- public API expansion
- reconnect policy redesign
- replay persistence or replay execution
- framework-specific integration behavior

## Downstream impact

Behavioral posture for downstream consumers:

- no intentional public API change in `esl-react`
- public behavior is sharpened in two bounded areas:
  - `api()` timeout is now fail-closed for the affected connection/session
    rather than allowing ambiguous reply-slot reuse
  - subscription/filter mutation gating now surfaces consistently through
    promise rejection on the returned `PromiseInterface`
- downstream users should now consume the tested `apntalk/esl-core ^0.2.13`
  line through this package
- environments consuming `esl-core ^0.2.10+` need `ext-dom` available because
  that requirement is now inherited transitively from the upstream package

Runtime-owned implementation work in this patch remains bounded:

- reply routing now consumes the public classified-message result contract using
  the supported reply-construction seam for `esl-core v0.2.13`
- the command bus now fails closed after `api()` timeout to restore a clean
  reply boundary before later command flow resumes
- subscription/filter mutation helpers now preserve their promise-returning
  contract consistently for normal runtime gating failures

## Validation status for this patch

The release surface has been verified with:

- `composer validate --strict`
- `composer show apntalk/esl-core`
- focused PHPUnit for the compatibility-affected areas
- full `composer test`
- `composer analyse`

Recommended release-prep gate:

```bash
composer validate --strict
composer show apntalk/esl-core
composer test
composer analyse
git diff --check
```

Live harnesses remain opt-in and environment-dependent. They are not part of
the mandatory patch-release gate for this release.

## Suggested GitHub release text

- Upgrade the runtime dependency to `apntalk/esl-core ^0.2.13` and align reply routing with the supported `v0.2.13` public classified-message contract.
- Harden `api()` timeout handling to fail closed on the affected connection so ambiguous late replies cannot cross-wire into later commands.
- Harden subscription/filter mutation gating so normal runtime-gating failures reject the returned promise consistently instead of leaking synchronous throws.
- Add deterministic proof for `handshake_protocol_failure` as a terminal reconnect-stop reason and for late orphaned `bgapi()` completion remaining a no-op after timeout.
- Refresh release-facing docs and stability notes so the patch behavior and support requirements match the verified runtime truth.

## Tagging note

Latest released tag on this line is `v0.2.10`.

The next patch tag for this accumulated surface is `v0.2.11`.
