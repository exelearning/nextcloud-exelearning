---
id: ADR-68-01
title: "Let the package's own download button work in the opaque viewer"
status: Proposed
date: 2026-10-07
tracking_issue: 68
deciders:
  - "@erseco"
reviewers:
  - "@erseco"
related:
  prs: [68]
  changes: []
  adrs: []
supersedes: []
superseded_by: []
ai_assistance:
  tool: "Claude Code"
  model: "claude-opus-5-5"
---

# ADR-68-01: Let the package's own download button work in the opaque viewer

## Context

eXeLearning packages can include the download-source-file iDevice. Its button
calls the package's global `downloadElpx()`, which refetches every file of the
package, compresses them with fflate and saves the result as an `.elpx`.

Before this PR the viewer iframe carried `allow-downloads`
(`src/elpx/iframe-renderer.ts` on `main`). The opaque viewer introduced here
dropped it from the secure token set, and its published Content-Security-Policy
has no `worker-src`.

## Problem

Inside the opaque viewer the button breaks in two ways:

1. fflate's asynchronous `zip()` compresses in Web Workers created from `blob:`
   URLs. With no `worker-src`, the browser falls back to `script-src`, which has
   no `blob:`, so the workers are blocked and the button hangs at
   "Processing... 100%" (exelearning/exelearning#2488).
2. A sandboxed frame without `allow-downloads` cannot start a download; Chrome
   drops it and logs that the flag is not set.

## Decision drivers

- Keep the document opaque: no `allow-same-origin`, no
  `allow-popups-to-escape-sandbox`.
- Do not regress a feature that worked on `main`.
- Stay aligned with the WordPress and Omeka S plugins, which made the same fix.

## Options considered

### Option 1: Add `allow-downloads` and `worker-src 'self' blob:`

The package's own rebuild completes and saves.

### Option 2: Route the button to the original upload from the parent

The WordPress and Omeka S plugins also do this. It needs the parent to reach the
package's globals, which only works for a same-origin frame, so it cannot apply
to the opaque viewer.

### Option 3: Leave the button broken

Rejected: it worked before this PR.

## Evidence

- WordPress: ADR-156-01 in exelearning/wp-exelearning (#156).
- Omeka S: ADR-63-01 in exelearning/omeka-s-exelearning (#63), carried into its
  opaque viewer in exelearning/omeka-s-exelearning#21.
- exelearning/exelearning#2488 (the hang under the content CSP).

## Decision

We will add `allow-downloads` to the secure and legacy sandbox token sets (the
iframe attribute in `src/elpx/iframe-renderer.ts` and `IframeSandbox` in PHP,
whose secure set also feeds the response-level CSP `sandbox` directive), and
`worker-src 'self' blob:` to the published Content-Security-Policy.

The editor preview CSP (`lib/Service/Preview/PreviewPolicy.php`) is unchanged; it
mirrors eXe core's `previewCspHeader()` and changes there first.

## Consequences

### Positive

- The package's download button works in the opaque viewer.

### Negative

- None for isolation. Package scripts already run with `'unsafe-inline'` and
  `'unsafe-eval'`, so `blob:` workers add no capability, and `allow-downloads`
  gives no access to the Nextcloud origin.

### Neutral

- The download is the package's in-browser rebuild, not the original file.

## Risks

- A package can start a download without user activation. It could already
  offer the same bytes through a link opened in a popup.

## Validation

- `IframeSandboxTest` pins `allow-downloads` and `worker-src 'self' blob:`.
- `iframe-renderer.test.ts` pins the secure iframe token set.
- Manual: open a package that contains the download-source-file iDevice in the
  viewer and confirm its button saves an `.elpx`.

## Follow-up work

- If eXe core adds `allow-downloads` to `previewCspHeader()`, mirror it in
  `PreviewPolicy`.

## References

- exelearning/exelearning#2488
- exelearning/wp-exelearning#156
- exelearning/omeka-s-exelearning#63, exelearning/omeka-s-exelearning#21
