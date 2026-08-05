#!/usr/bin/env python3
"""Tests for tools/architecture_records.py.

Standard-library ``unittest`` only, so this needs no new dependency:

    python3 -m unittest discover -s tools -p '*_test.py'
"""

from __future__ import annotations

import os
import shutil
import sys
import tempfile
import textwrap
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import architecture_records as ar  # noqa: E402


def write(path: str, text: str) -> None:
    os.makedirs(os.path.dirname(path), exist_ok=True)
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(textwrap.dedent(text).lstrip())


class Base(unittest.TestCase):
    def setUp(self) -> None:
        self.root = tempfile.mkdtemp(prefix="arch-records-")
        os.makedirs(os.path.join(self.root, ar.ADR_DIR), exist_ok=True)
        os.makedirs(os.path.join(self.root, ar.CHANGES_DIR), exist_ok=True)

    def tearDown(self) -> None:
        shutil.rmtree(self.root, ignore_errors=True)

    def adr(self, filename: str, **over) -> None:
        ident = over.pop("id", None)
        if ident is None:
            parts = filename.split("-")
            ident = f"ADR-{parts[1]}-{parts[2]}"
        number = over.pop("tracking_issue", ident.split("-")[1])
        title = over.pop("title", "A decision")
        heading = over.pop("h1", f"{ident}: {title}")
        write(os.path.join(self.root, ar.ADR_DIR, filename), f"""
            ---
            id: {ident}
            title: "{title}"
            status: {over.pop('status', 'Proposed')}
            date: {over.pop('date', '2026-08-05')}
            tracking_issue: {number}
            deciders:
              - "@erseco"
            related:
              prs: [{over.pop('prs', '')}]
              changes: [{over.pop('changes', '')}]
              adrs: [{over.pop('adrs', '')}]
            supersedes: [{over.pop('supersedes', '')}]
            superseded_by: [{over.pop('superseded_by', '')}]
            ai_assistance:
              tool: "none"
              model: "none"
            ---

            # {heading}

            ## Context

            Text.
            """)

    def change(self, directory: str, name: str = "proposal.md", extra: str = "") -> None:
        number = directory.split("-")[0]
        write(os.path.join(self.root, ar.CHANGES_DIR, directory, name), f"""
            ---
            tracking_issue: {number}
            title: "A change"
            status: draft
            date: 2026-08-05
            authors:
              - "@erseco"
            {extra}
            ai_assistance:
              tool: "none"
              model: "none"
            ---

            # A change
            """)

    def problems(self):
        adrs, adr_errors = ar.discover_adrs(self.root)
        changes, change_errors = ar.discover_changes(self.root)
        return adr_errors + change_errors + ar.validate(adrs, changes)


class TestFrontmatter(Base):
    def test_returns_none_without_frontmatter(self):
        self.assertIsNone(ar.parse_frontmatter("# Heading\n"))

    def test_parses_the_supported_subset(self):
        data, body = ar.parse_frontmatter(textwrap.dedent("""
            ---
            id: ADR-1234-02
            title: "Use asset:// references"
            empty: []
            inline: [ADR-1234-01, ADR-1234-03]
            deciders:
              - "@erseco"
              - "@other"
            related:
              prs: [90]
              adrs: []
            ai_assistance:
              tool: "Claude Code"
              model: "claude-opus-5"
            ---

            # Body
            """).lstrip())
        self.assertEqual(data["id"], "ADR-1234-02")
        self.assertEqual(data["title"], "Use asset:// references")
        self.assertEqual(data["empty"], [])
        self.assertEqual(data["inline"], ["ADR-1234-01", "ADR-1234-03"])
        self.assertEqual(data["deciders"], ["@erseco", "@other"])
        self.assertEqual(data["related"], {"prs": ["90"], "adrs": []})
        self.assertEqual(data["ai_assistance"], {"tool": "Claude Code", "model": "claude-opus-5"})
        self.assertEqual(body.strip(), "# Body")


class TestGrammars(Base):
    def test_accepts_the_tracking_number_filename(self):
        match = ar.ADR_FILENAME_RE.match("ADR-90-02-use-asset-uri-references.md")
        self.assertIsNotNone(match)
        self.assertEqual(match.groups(), ("90", "02", "use-asset-uri-references"))

    def test_rejects_bad_filenames(self):
        for bad in (
            "ADR-0035-file-attachment.md",   # retired global numbering
            "ADR-90-2-short-sequence.md",    # sequence must be two digits
            "ADR-90-02-Use-Caps.md",         # slug must be kebab-case
            "ADR-090-02-leading-zero.md",    # no leading zeros
            "SDD-0009-a-design.md",
        ):
            self.assertIsNone(ar.ADR_FILENAME_RE.match(bad), bad)

    def test_change_directory_grammar(self):
        self.assertTrue(ar.CHANGE_DIR_RE.match("90-stale-content-url-redirects"))
        self.assertFalse(ar.CHANGE_DIR_RE.match("Not-Kebab"))

    def test_legacy_pattern_ignores_current_identifiers(self):
        self.assertTrue(ar.LEGACY_ID_RE.search("see ADR-0035 for details"))
        self.assertTrue(ar.LEGACY_ID_RE.search("see SDD-0009 for details"))
        self.assertFalse(ar.LEGACY_ID_RE.search("see ADR-1234-01 for details"))

    def test_dates(self):
        self.assertTrue(ar.is_valid_date("2024-02-29"))
        for bad in ("2026-13-01", "2026-02-30", "2026-8-5", "yesterday", ""):
            self.assertFalse(ar.is_valid_date(bad), bad)


class TestDiscovery(Base):
    def test_skips_policy_files(self):
        for name in ar.SKIP_ADR_FILES:
            write(os.path.join(self.root, ar.ADR_DIR, name), "# Not a record\n")
        self.adr("ADR-90-01-a-decision.md")
        adrs, errors = ar.discover_adrs(self.root)
        self.assertEqual(len(adrs), 1)
        self.assertEqual(errors, [])

    def test_reports_retired_numbering_actionably(self):
        write(os.path.join(self.root, ar.ADR_DIR, "ADR-0042-a.md"), "---\nid: ADR-0042\n---\n\n# x\n")
        _, errors = ar.discover_adrs(self.root)
        self.assertIn("retired global numbering", errors[0].message)

    def test_change_directory_needs_a_document(self):
        os.makedirs(os.path.join(self.root, ar.CHANGES_DIR, "90-empty"))
        changes, errors = ar.discover_changes(self.root)
        self.assertEqual(changes, [])
        self.assertIn("no recognised document", errors[0].message)

    def test_canonical_is_the_first_recognised_document(self):
        self.change("90-a-change", "design.md")
        self.change("90-a-change", "proposal.md")
        changes, _ = ar.discover_changes(self.root)
        self.assertEqual(changes[0].documents[0][0], "proposal.md")


class TestValidation(Base):
    def test_accepts_a_well_formed_corpus(self):
        self.adr("ADR-90-01-first.md", title="First")
        self.change("90-a-change")
        self.assertEqual(self.problems(), [])

    def test_rejects_id_filename_mismatch(self):
        self.adr("ADR-90-01-first.md", id="ADR-90-02")
        self.assertTrue(any("does not match filename" in p.message for p in self.problems()))

    def test_rejects_tracking_number_mismatch(self):
        self.adr("ADR-90-01-first.md", tracking_issue="91")
        self.assertTrue(any("does not match filename number" in p.message for p in self.problems()))

    def test_detects_duplicate_local_sequence(self):
        self.adr("ADR-90-01-first.md", title="First")
        self.adr("ADR-90-01-second.md", title="Second")
        self.assertTrue(any("duplicate ADR id" in p.message for p in self.problems()))

    def test_same_sequence_under_different_numbers_is_fine(self):
        self.adr("ADR-90-01-first.md", title="First")
        self.adr("ADR-91-01-second.md", title="Second")
        self.assertEqual(self.problems(), [])

    def test_rejects_unknown_status_and_date(self):
        self.adr("ADR-90-01-a.md", status="InProgress")
        self.assertTrue(any("is not one of" in p.message for p in self.problems()))
        self.setUp()
        self.adr("ADR-90-01-a.md", date="2026-13-99")
        self.assertTrue(any("not a valid YYYY-MM-DD" in p.message for p in self.problems()))

    def test_rejects_dangling_references(self):
        self.adr("ADR-90-01-a.md", adrs="ADR-99-01")
        self.assertTrue(any("unknown ADR" in p.message for p in self.problems()))

    def test_rejects_non_numeric_pr(self):
        self.adr("ADR-90-01-a.md", prs='"#90"')
        self.assertTrue(any("not a positive integer" in p.message for p in self.problems()))

    def test_rejects_one_sided_supersession(self):
        self.adr("ADR-90-01-old.md", title="Old")
        self.adr("ADR-91-01-new.md", title="New", supersedes="ADR-90-01")
        self.assertTrue(any("does not list superseded_by" in p.message for p in self.problems()))

    def test_accepts_symmetric_supersession(self):
        self.adr("ADR-90-01-old.md", title="Old", status="Superseded", superseded_by="ADR-91-01")
        self.adr("ADR-91-01-new.md", title="New", supersedes="ADR-90-01")
        self.assertEqual(self.problems(), [])

    def test_flags_superseded_record_left_at_wrong_status(self):
        self.adr("ADR-90-01-old.md", title="Old", superseded_by="ADR-91-01")
        self.adr("ADR-91-01-new.md", title="New", supersedes="ADR-90-01")
        self.assertTrue(any('not "Superseded"' in p.message for p in self.problems()))

    def test_rejects_h1_that_disagrees_with_frontmatter(self):
        self.adr("ADR-90-01-a.md", h1="Something else")
        self.assertTrue(any("H1 is" in p.message for p in self.problems()))

    def test_rejects_implementation_prs_outside_canonical_document(self):
        self.change("90-a-change", "proposal.md", extra="implementation_prs: [90]")
        self.change("90-a-change", "design.md", extra="implementation_prs: [90]")
        self.assertTrue(any("canonical metadata carrier" in p.message for p in self.problems()))

    def test_rejects_change_tracking_number_mismatch(self):
        self.change("90-a-change")
        path = os.path.join(self.root, ar.CHANGES_DIR, "90-a-change", "proposal.md")
        with open(path, encoding="utf-8") as handle:
            text = handle.read()
        with open(path, "w", encoding="utf-8") as handle:
            handle.write(text.replace("tracking_issue: 90", "tracking_issue: 91"))
        self.assertTrue(any("does not match change directory number" in p.message for p in self.problems()))

    def test_rejects_self_referencing_change(self):
        self.change("90-a-change", extra='related_changes: ["90-a-change"]')
        self.assertTrue(any("cannot reference itself" in p.message for p in self.problems()))


class TestLegacyScan(Base):
    def test_flags_a_retired_identifier(self):
        write(os.path.join(self.root, "notes.md"), "See ADR-0035 for the rationale.\n")
        problems = ar.find_legacy_references(self.root, ["notes.md"])
        self.assertEqual(len(problems), 1)
        self.assertEqual(problems[0].file, "notes.md:1")

    def test_does_not_flag_current_identifiers(self):
        write(os.path.join(self.root, "notes.md"), "See ADR-90-01 and ADR-91-02.\n")
        self.assertEqual(ar.find_legacy_references(self.root, ["notes.md"]), [])

    def test_allows_a_record_to_name_its_own_legacy_id(self):
        write(os.path.join(self.root, "design.md"), "---\nlegacy_id: SDD-0009\n---\n\nWritten as SDD-0009.\n")
        self.assertEqual(ar.find_legacy_references(self.root, ["design.md"]), [])

    def test_still_flags_a_different_legacy_id(self):
        write(os.path.join(self.root, "design.md"), "---\nlegacy_id: SDD-0009\n---\n\nSee ADR-0035.\n")
        self.assertEqual(len(ar.find_legacy_references(self.root, ["design.md"])), 1)

    def test_skips_the_allowlist(self):
        write(os.path.join(self.root, ar.POLICY), "The retired ADR-0001 form.\n")
        self.assertEqual(ar.find_legacy_references(self.root, [ar.POLICY]), [])


class TestCommittedIndex(Base):
    def test_rejects_a_committed_index(self):
        problems = ar.find_committed_indexes([f"{ar.ADR_DIR}/records.md", "README.md"])
        self.assertEqual(len(problems), 1)
        self.assertIn("must not be committed", problems[0].message)

    def test_accepts_a_tree_without_one(self):
        self.assertEqual(ar.find_committed_indexes(["README.md"]), [])


class TestRendering(Base):
    def test_is_deterministic_and_sorted(self):
        self.adr("ADR-91-01-later.md", title="Later")
        self.adr("ADR-90-02-second.md", title="Second")
        self.adr("ADR-90-01-first.md", title="First")
        adrs, _ = ar.discover_adrs(self.root)
        changes, _ = ar.discover_changes(self.root)
        first = ar.render_index(adrs, changes)
        self.assertEqual(first, ar.render_index(adrs, changes))
        self.assertLess(first.index("ADR-90-01"), first.index("ADR-90-02"))
        self.assertLess(first.index("ADR-90-02"), first.index("ADR-91-01"))
        self.assertIn("Not a committed file", first)

    def test_renders_an_empty_corpus(self):
        adrs, _ = ar.discover_adrs(self.root)
        changes, _ = ar.discover_changes(self.root)
        rendered = ar.render_index(adrs, changes)
        self.assertIn("_No ADRs yet._", rendered)
        self.assertIn("_No change directories yet._", rendered)


if __name__ == "__main__":
    unittest.main()
