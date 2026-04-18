# Release Prep — v0.2.11 candidate

This note summarizes the patch-release candidate after the successful
`apntalk/esl-core` `v0.2.13` consumer compatibility and release-readiness
passes.

It is intended as a concise tag-prep aid and release-note source for the next
patch release after `v0.2.10`.

## Scope of this patch

This candidate is a bounded upstream-consumer compatibility and regression-proof
release.

Implemented in this candidate:

- upgraded the runtime dependency to `apntalk/esl-core ^0.2.13`
- aligned inbound reply routing with the `v0.2.13` public
  `ClassifiedMessageInterface` contract
- removed the obsolete dependency on a distinct public auth-rejected
  classifier outcome
- hardened fake-server and hand-built `text/event-plain` fixtures for the
  stricter upstream parser expectations
- revalidated replay-envelope compatibility, including the additive
  `ReplayEnvelopeInterface` truth accessors exposed by `esl-core v0.2.13`
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

- no intentional public behavior change in `esl-react`
- no intentional public API change in `esl-react`
- downstream users should now consume the tested `apntalk/esl-core ^0.2.13`
  line through this package
- environments consuming `esl-core ^0.2.10+` need `ext-dom` available because
  that requirement is now inherited transitively from the upstream package

The only runtime-owned code adjustment in this patch is internal:

- reply routing now consumes the public classified-message result contract using
  the supported reply-construction seam for `esl-core v0.2.13`

## Validation status for this patch

The patch-release surface has been verified with:

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
the mandatory patch-release gate for this candidate.

## Tagging note

Latest released tag on this line is `v0.2.10`.

The next patch-release candidate for this accumulated surface is therefore
`v0.2.11`, assuming no additional feature work is merged before tag prep.
