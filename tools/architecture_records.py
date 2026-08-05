#!/usr/bin/env python3
"""Validate and list architecture decision records.

Records live in ``docs/architecture/adr/`` and change directories in
``docs/architecture/changes/``. Both are identified by the GitHub tracking
number of the change they belong to -- the issue when there is one, and the
pull request otherwise. Issues are disabled on this repository, so in practice
every number here is a pull request number.

Usage::

    python3 tools/architecture_records.py check   # validate; non-zero on failure
    python3 tools/architecture_records.py list    # print the index to stdout

The index is deliberately *not* a committed file. It is derived entirely from
frontmatter, and a generated file in version control conflicts on every
concurrent branch -- exactly the problem the tracking-number convention exists
to remove.

Depends on the standard library only: this repository already ships
``tools/*.py`` and adding a YAML dependency for a documentation linter is not
warranted.
"""

from __future__ import annotations

import datetime
import os
import re
import subprocess
import sys
from dataclasses import dataclass, field

ADR_DIR = "docs/architecture/adr"
CHANGES_DIR = "docs/architecture/changes"
POLICY = "docs/architecture/adr/README.md"

ADR_STATUSES = ("Proposed", "Accepted", "Rejected", "Superseded")
CHANGE_STATUSES = ("draft", "in-review", "accepted", "implemented", "superseded", "abandoned")
CHANGE_DOCUMENTS = ("proposal.md", "spec.md", "design.md", "research.md", "tasks.md")
SKIP_ADR_FILES = ("README.md", "template.md", "records.md")

ADR_FILENAME_RE = re.compile(r"^ADR-([1-9][0-9]*)-([0-9]{2})-([a-z0-9]+(?:-[a-z0-9]+)*)\.md$")
CHANGE_DIR_RE = re.compile(r"^([1-9][0-9]*)-([a-z0-9]+(?:-[a-z0-9]+)*)$")
# A retired identifier is ADR-NNNN / SDD-NNNN *not* followed by a two-digit
# local sequence; without the lookahead, ADR-1234-01 matches its own prefix.
LEGACY_ID_RE = re.compile(r"\b(?:ADR|SDD)-[0-9]{4}(?!-[0-9]{2})\b")
LEGACY_FILENAME_RE = re.compile(r"^(?:ADR|SDD)-[0-9]{4}-")
POSITIVE_INT_RE = re.compile(r"^[1-9][0-9]*$")

# Files allowed to name a retired identifier, because documenting the convention
# requires describing the form it replaces.
LEGACY_ALLOWLIST = (
    "docs/architecture/adr/README.md",
    "docs/architecture/changes/README.md",
    "docs/architecture/adr/template.md",
    "docs/architecture/changes/template.md",
    "tools/architecture_records.py",
    "tools/architecture_records_test.py",
)

BANNER = "<!-- Produced by `make architecture-records`. Not a committed file. -->"


@dataclass
class Diagnostic:
    file: str
    message: str


@dataclass
class Adr:
    path: str
    filename: str
    id: str
    number: int
    sequence: str
    data: dict
    h1: str | None


@dataclass
class Change:
    name: str
    number: int
    slug: str
    documents: list[tuple[str, dict]] = field(default_factory=list)


# --------------------------------------------------------------------------- #
# Frontmatter
# --------------------------------------------------------------------------- #

def parse_frontmatter(text: str):
    """Parse the bounded YAML subset used by architecture frontmatter.

    Handles scalars, inline lists, block lists and one level of nested mappings.
    Returns ``(data, body)`` or ``None`` when there is no frontmatter.
    """
    match = re.match(r"^---\r?\n(.*?)\r?\n---\r?\n?(.*)$", text, re.DOTALL)
    if not match:
        return None

    data: dict = {}
    key = None
    seq: list | None = None
    mapping: dict | None = None

    def flush():
        nonlocal key, seq, mapping
        if key is None:
            return
        if seq is not None:
            data[key] = seq
        elif mapping is not None:
            data[key] = mapping
        key, seq, mapping = None, None, None

    for line in match.group(1).splitlines():
        if not line.strip() or line.strip().startswith("#"):
            continue

        top = re.match(r"^([A-Za-z_][A-Za-z0-9_]*):(.*)$", line)
        if top:
            flush()
            rest = top.group(2).strip()
            if rest == "":
                key = top.group(1)
            else:
                data[top.group(1)] = _scalar_or_list(rest)
            continue

        item = re.match(r"^\s+-\s*(.*)$", line)
        if item is not None and key is not None:
            mapping = None
            seq = [] if seq is None else seq
            seq.append(_unquote(item.group(1).strip()))
            continue

        nested = re.match(r"^\s+([A-Za-z_][A-Za-z0-9_]*):(.*)$", line)
        if nested is not None and key is not None:
            seq = None
            mapping = {} if mapping is None else mapping
            mapping[nested.group(1)] = _scalar_or_list(nested.group(2).strip())

    flush()
    return data, match.group(2)


def _unquote(value: str) -> str:
    return re.sub(r'^["\'](.*)["\']$', r"\1", value)


def _scalar_or_list(raw: str):
    if raw.startswith("[") and raw.endswith("]"):
        inner = raw[1:-1].strip()
        if inner == "":
            return []
        return [_unquote(part.strip()) for part in inner.split(",")]
    return _unquote(raw)


def as_list(value) -> list[str]:
    if value is None:
        return []
    if isinstance(value, list):
        return [str(v) for v in value]
    if isinstance(value, dict):
        return []
    text = str(value).strip()
    return [text] if text else []


def as_text(value) -> str:
    if value is None or isinstance(value, (list, dict)):
        return ""
    return str(value)


def is_valid_date(value: str) -> bool:
    if not re.match(r"^\d{4}-\d{2}-\d{2}$", value):
        return False
    try:
        datetime.date.fromisoformat(value)
    except ValueError:
        return False
    return True


# --------------------------------------------------------------------------- #
# Discovery
# --------------------------------------------------------------------------- #

def discover_adrs(root: str):
    directory = os.path.join(root, ADR_DIR)
    adrs: list[Adr] = []
    errors: list[Diagnostic] = []
    if not os.path.isdir(directory):
        return adrs, errors

    for filename in sorted(os.listdir(directory)):
        if not filename.endswith(".md") or filename in SKIP_ADR_FILES:
            continue
        rel = f"{ADR_DIR}/{filename}"

        # The new grammar is tested first: ADR-1234-01-... also starts with four
        # digits, so a legacy-first check would reject every valid record.
        match = ADR_FILENAME_RE.match(filename)
        if not match:
            errors.append(Diagnostic(
                rel,
                "uses the retired global numbering. Rename to "
                f"ADR-<number>-<NN>-<decision-slug>.md (see {POLICY})."
                if LEGACY_FILENAME_RE.match(filename)
                else "filename does not match ADR-<number>-<NN>-<decision-slug>.md",
            ))
            continue

        with open(os.path.join(directory, filename), encoding="utf-8") as handle:
            parsed = parse_frontmatter(handle.read())
        if parsed is None:
            errors.append(Diagnostic(rel, "missing YAML frontmatter"))
            continue

        data, body = parsed
        heading = re.search(r"^# (.+)$", body, re.MULTILINE)
        adrs.append(Adr(
            path=rel,
            filename=filename,
            id=as_text(data.get("id")),
            number=int(match.group(1)),
            sequence=match.group(2),
            data=data,
            h1=heading.group(1) if heading else None,
        ))

    return adrs, errors


def discover_changes(root: str):
    directory = os.path.join(root, CHANGES_DIR)
    changes: list[Change] = []
    errors: list[Diagnostic] = []
    if not os.path.isdir(directory):
        return changes, errors

    for entry in sorted(os.listdir(directory)):
        full = os.path.join(directory, entry)
        if not os.path.isdir(full):
            continue
        rel = f"{CHANGES_DIR}/{entry}"

        match = CHANGE_DIR_RE.match(entry)
        if not match:
            errors.append(Diagnostic(rel, "directory name does not match <number>-<change-slug>"))
            continue

        documents = []
        for name in CHANGE_DOCUMENTS:
            path = os.path.join(full, name)
            if not os.path.exists(path):
                continue
            with open(path, encoding="utf-8") as handle:
                parsed = parse_frontmatter(handle.read())
            if parsed is None:
                errors.append(Diagnostic(f"{rel}/{name}", "missing YAML frontmatter"))
                continue
            documents.append((name, parsed[0]))

        if not documents:
            errors.append(Diagnostic(
                rel, f"contains no recognised document ({', '.join(CHANGE_DOCUMENTS)})"
            ))
            continue

        changes.append(Change(
            name=entry, number=int(match.group(1)), slug=match.group(2), documents=documents
        ))

    return changes, errors


# --------------------------------------------------------------------------- #
# Validation
# --------------------------------------------------------------------------- #

def validate(adrs: list[Adr], changes: list[Change]) -> list[Diagnostic]:
    problems: list[Diagnostic] = []
    known_ids = {a.id for a in adrs if a.id}
    known_changes = {c.name for c in changes}
    seen_ids: dict[str, str] = {}

    for adr in adrs:
        expected = f"ADR-{adr.number}-{adr.sequence}"
        data = adr.data

        if not adr.id:
            problems.append(Diagnostic(adr.path, "missing required field `id`"))
        elif adr.id != expected:
            problems.append(Diagnostic(
                adr.path, f'frontmatter id "{adr.id}" does not match filename (expected "{expected}")'
            ))

        title = as_text(data.get("title"))
        if not title:
            problems.append(Diagnostic(adr.path, "missing required field `title`"))

        date = as_text(data.get("date"))
        if not date:
            problems.append(Diagnostic(adr.path, "missing required field `date`"))
        elif not is_valid_date(date):
            problems.append(Diagnostic(adr.path, f'date "{date}" is not a valid YYYY-MM-DD date'))

        status = as_text(data.get("status"))
        if not status:
            problems.append(Diagnostic(adr.path, "missing required field `status`"))
        elif status not in ADR_STATUSES:
            problems.append(Diagnostic(
                adr.path, f'status "{status}" is not one of {", ".join(ADR_STATUSES)}'
            ))

        tracking = as_text(data.get("tracking_issue"))
        if not tracking:
            problems.append(Diagnostic(adr.path, "missing required field `tracking_issue`"))
        elif not POSITIVE_INT_RE.match(tracking):
            problems.append(Diagnostic(
                adr.path, f'tracking_issue "{tracking}" is not a positive integer'
            ))
        elif int(tracking) != adr.number:
            problems.append(Diagnostic(
                adr.path,
                f"tracking_issue {tracking} does not match filename number {adr.number}",
            ))

        if not as_list(data.get("deciders")):
            problems.append(Diagnostic(adr.path, "missing required field `deciders`"))

        ai = data.get("ai_assistance")
        if not isinstance(ai, dict) or not as_text(ai.get("tool")) or not as_text(ai.get("model")):
            problems.append(Diagnostic(
                adr.path,
                "missing `ai_assistance.tool` / `ai_assistance.model` (use `none` if no AI tool was used)",
            ))

        if adr.h1 is None:
            problems.append(Diagnostic(adr.path, "missing H1 heading"))
        elif adr.h1 != f"{expected}: {title}":
            problems.append(Diagnostic(
                adr.path, f'H1 is "{adr.h1}" but should be "{expected}: {title}"'
            ))

        if adr.id:
            if adr.id in seen_ids:
                problems.append(Diagnostic(
                    adr.path, f'duplicate ADR id "{adr.id}" (also in {seen_ids[adr.id]})'
                ))
            else:
                seen_ids[adr.id] = adr.path

        related = data.get("related") if isinstance(data.get("related"), dict) else {}
        for ref in as_list(related.get("adrs")):
            if ref not in known_ids:
                problems.append(Diagnostic(adr.path, f'related.adrs references unknown ADR "{ref}"'))
        for ref in as_list(related.get("changes")):
            if ref not in known_changes:
                problems.append(Diagnostic(
                    adr.path, f'related.changes references unknown change "{ref}"'
                ))
        for pr in as_list(related.get("prs")):
            if not POSITIVE_INT_RE.match(pr):
                problems.append(Diagnostic(
                    adr.path, f'related.prs value "{pr}" is not a positive integer'
                ))

        for ref in as_list(data.get("supersedes")):
            if ref == adr.id:
                problems.append(Diagnostic(adr.path, "ADR cannot supersede itself"))
            elif ref not in known_ids:
                problems.append(Diagnostic(adr.path, f'supersedes references unknown ADR "{ref}"'))
            else:
                target = next(a for a in adrs if a.id == ref)
                if adr.id not in as_list(target.data.get("superseded_by")):
                    problems.append(Diagnostic(
                        adr.path,
                        f'supersedes "{ref}" but {target.path} does not list superseded_by: [{adr.id}]',
                    ))
                if as_text(target.data.get("status")) != "Superseded":
                    problems.append(Diagnostic(
                        target.path,
                        f'is superseded by {adr.id} but status is '
                        f'"{as_text(target.data.get("status"))}", not "Superseded"',
                    ))

        for ref in as_list(data.get("superseded_by")):
            if ref == adr.id:
                problems.append(Diagnostic(adr.path, "ADR cannot be superseded by itself"))
            elif ref not in known_ids:
                problems.append(Diagnostic(
                    adr.path, f'superseded_by references unknown ADR "{ref}"'
                ))
            elif adr.id not in as_list(
                next(a for a in adrs if a.id == ref).data.get("supersedes")
            ):
                problems.append(Diagnostic(
                    adr.path, f'superseded_by "{ref}" but that ADR does not list supersedes: [{adr.id}]'
                ))

    for change in changes:
        canonical_name, canonical = change.documents[0]
        canonical_path = f"{CHANGES_DIR}/{change.name}/{canonical_name}"

        if not as_text(canonical.get("title")):
            problems.append(Diagnostic(canonical_path, "missing required field `title`"))

        date = as_text(canonical.get("date"))
        if not date:
            problems.append(Diagnostic(canonical_path, "missing required field `date`"))
        elif not is_valid_date(date):
            problems.append(Diagnostic(canonical_path, f'date "{date}" is not a valid YYYY-MM-DD date'))

        status = as_text(canonical.get("status"))
        if not status:
            problems.append(Diagnostic(canonical_path, "missing required field `status`"))
        elif status not in CHANGE_STATUSES:
            problems.append(Diagnostic(
                canonical_path, f'status "{status}" is not one of {", ".join(CHANGE_STATUSES)}'
            ))

        for ref in as_list(canonical.get("related_adrs")):
            if ref not in known_ids:
                problems.append(Diagnostic(
                    canonical_path, f'related_adrs references unknown ADR "{ref}"'
                ))
        for ref in as_list(canonical.get("related_changes")):
            if ref == change.name:
                problems.append(Diagnostic(canonical_path, "change cannot reference itself"))
            elif ref not in known_changes:
                problems.append(Diagnostic(
                    canonical_path, f'related_changes references unknown change "{ref}"'
                ))

        for name, data in change.documents:
            path = f"{CHANGES_DIR}/{change.name}/{name}"
            tracking = as_text(data.get("tracking_issue"))
            if not tracking:
                problems.append(Diagnostic(path, "missing required field `tracking_issue`"))
            elif not POSITIVE_INT_RE.match(tracking):
                problems.append(Diagnostic(
                    path, f'tracking_issue "{tracking}" is not a positive integer'
                ))
            elif int(tracking) != change.number:
                problems.append(Diagnostic(
                    path,
                    f"tracking_issue {tracking} does not match change directory number {change.number}",
                ))

            for pr in as_list(data.get("implementation_prs")):
                if not POSITIVE_INT_RE.match(pr):
                    problems.append(Diagnostic(
                        path, f'implementation_prs value "{pr}" is not a positive integer'
                    ))

            if name != canonical_name and data.get("implementation_prs") is not None:
                problems.append(Diagnostic(
                    path,
                    f"declares implementation_prs, but {canonical_name} is the canonical "
                    "metadata carrier for this change",
                ))

    return problems


def tracked_files(root: str) -> list[str]:
    """Tracked files plus not-yet-added ones, honouring .gitignore.

    Untracked files matter: otherwise a brand-new file passes ``check`` locally
    and only fails in CI once it has been committed.
    """
    try:
        out = subprocess.run(
            ["git", "ls-files", "--cached", "--others", "--exclude-standard"],
            cwd=root, capture_output=True, text=True, check=False,
        )
    except OSError:
        return []
    if out.returncode != 0:
        return []
    return sorted(set(line for line in out.stdout.splitlines() if line))


def find_legacy_references(root: str, files: list[str]) -> list[Diagnostic]:
    problems: list[Diagnostic] = []
    for rel in files:
        if rel in LEGACY_ALLOWLIST:
            continue
        path = os.path.join(root, rel)
        if not os.path.isfile(path):
            continue
        try:
            with open(path, "rb") as handle:
                raw = handle.read()
        except OSError:
            continue
        if b"\0" in raw:
            continue
        try:
            text = raw.decode("utf-8")
        except UnicodeDecodeError:
            continue

        own_legacy = None
        if rel.endswith(".md"):
            parsed = parse_frontmatter(text)
            if parsed:
                own_legacy = as_text(parsed[0].get("legacy_id")) or None

        for number, line in enumerate(text.splitlines(), start=1):
            if "legacy_id:" in line:
                continue
            hit = LEGACY_ID_RE.search(line)
            if hit and hit.group(0) != own_legacy:
                problems.append(Diagnostic(
                    f"{rel}:{number}",
                    f'references retired identifier "{hit.group(0)}". '
                    f"Use the current identifier (see {POLICY}).",
                ))
    return problems


def find_committed_indexes(files: list[str]) -> list[Diagnostic]:
    """The index is derived, not stored. Committing it reintroduces exactly the
    merge-conflict class the tracking-number convention removes."""
    return [
        Diagnostic(
            rel,
            "the record index must not be committed — it is derived from frontmatter "
            "and conflicts on every concurrent branch. Delete it; "
            "`make architecture-records` prints it.",
        )
        for rel in files
        if rel in (f"{ADR_DIR}/records.md", f"{CHANGES_DIR}/records.md")
    ]


# --------------------------------------------------------------------------- #
# Rendering
# --------------------------------------------------------------------------- #

def render_index(adrs: list[Adr], changes: list[Change]) -> str:
    lines = [BANNER, "", "# Architecture record index", ""]

    lines += ["## Architecture Decision Records", ""]
    if not adrs:
        lines += ["_No ADRs yet._", ""]
    else:
        lines += ["| ID | Title | Status | Tracking | Date |", "|---|---|---|---|---|"]
        for adr in sorted(adrs, key=lambda a: (a.number, a.sequence)):
            lines.append(
                f"| [{adr.id}]({adr.filename}) | {as_text(adr.data.get('title'))} | "
                f"{as_text(adr.data.get('status'))} | #{adr.number} | "
                f"{as_text(adr.data.get('date'))} |"
            )
        lines.append("")

    lines += ["## Changes", ""]
    if not changes:
        lines += ["_No change directories yet._", ""]
    else:
        lines += ["| Change | Title | Status | Tracking | Documents |", "|---|---|---|---|---|"]
        for change in sorted(changes, key=lambda c: (c.number, c.slug)):
            name, canonical = change.documents[0]
            docs = ", ".join(doc for doc, _ in change.documents)
            lines.append(
                f"| `{change.name}` | {as_text(canonical.get('title'))} | "
                f"{as_text(canonical.get('status'))} | #{change.number} | {docs} |"
            )
        lines.append("")

    return "\n".join(lines)


# --------------------------------------------------------------------------- #
# CLI
# --------------------------------------------------------------------------- #

def report(title: str, problems: list[Diagnostic]) -> None:
    if not problems:
        return
    print(f"\n{title}", file=sys.stderr)
    for problem in problems:
        print(f"  ✗ {problem.file}: {problem.message}", file=sys.stderr)


def run(mode: str, root: str) -> int:
    adrs, adr_errors = discover_adrs(root)
    changes, change_errors = discover_changes(root)
    structural = adr_errors + change_errors

    if mode == "list":
        report("Structural problems:", structural)
        if structural:
            print("\nRefusing to list records while structural problems remain.", file=sys.stderr)
            return 1
        print(render_index(adrs, changes))
        return 0

    files = tracked_files(root)
    metadata = validate(adrs, changes)
    legacy = find_legacy_references(root, files)
    committed = find_committed_indexes(files)

    report("Structural problems:", structural)
    report("Metadata problems:", metadata)
    report("Retired identifier references:", legacy)
    report("Committed index:", committed)

    total = len(structural) + len(metadata) + len(legacy) + len(committed)
    if total == 0:
        print(f"Architecture records OK — {len(adrs)} ADRs, {len(changes)} changes.")
        return 0
    print(f"\n{total} problem(s) found.", file=sys.stderr)
    return 1


def main() -> int:
    if len(sys.argv) != 2 or sys.argv[1] not in ("check", "list"):
        print("Usage: python3 tools/architecture_records.py <check|list>", file=sys.stderr)
        return 2
    return run(sys.argv[1], os.getcwd())


if __name__ == "__main__":
    raise SystemExit(main())
