#!/usr/bin/env python3
"""Build and publish the 2-hour live-evaluation SYNAPSE Black-Box Testing sheet.

Reads ``papers/blackbox/defense-sheet.json``, cross-checks 100% of all 70
Use Cases (``use-cases.json``) and mapped technical case IDs against the
validated 266-case specification, builds a concise plain-English DOCX matching
``sample-BlackBox-Testing.docx`` (US Letter, narrow 0.5" margins, 10.5/11 pt
Times New Roman), and publishes hardened DOCX + PDF via LibreOffice.
"""

from __future__ import annotations

import argparse
import dataclasses
import sys
import tempfile
from pathlib import Path
from typing import Any, Sequence

TOOLS_DIR = Path(__file__).resolve().parent
if str(TOOLS_DIR) not in sys.path:
    sys.path.insert(0, str(TOOLS_DIR))

import docx_template
import publish
import secure_io
import validate

Document = docx_template.Document
WD_ALIGN_VERTICAL = docx_template.WD_ALIGN_VERTICAL
WD_ALIGN_PARAGRAPH = docx_template.WD_ALIGN_PARAGRAPH
Inches = docx_template.Inches
Pt = docx_template.Pt

DEFENSE_JSON_REL = "papers/blackbox/defense-sheet.json"
DEFENSE_DOCX_REL = "papers/blackbox/SYNAPSE-BlackBox-Testing-Defense.docx"
DEFENSE_PDF_REL = "papers/blackbox/SYNAPSE-BlackBox-Testing-Defense.pdf"

TRACK_COL_WIDTHS = (1100, 1750, 5250, 1200, 1500)  # sum = 10800 dxa
DEF_MATRIX_COL_WIDTHS = (1050, 950, 2000, 4650, 2150)  # sum = 10800 dxa
DEF_CASE_COL_WIDTHS = (1100, 1750, 3950, 2400, 600, 1000)  # sum = 10800 dxa


class DefenseBuildError(RuntimeError):
    """Raised when the defense sheet JSON fails coverage or schema checks."""


@dataclasses.dataclass
class DefenseSheet:
    title: str
    version: str
    allocated_time: str
    technical_appendix: str
    tracks: list[dict[str, Any]]
    cases: list[dict[str, Any]]
    suite_data: validate.SuiteData


def _default_root() -> Path:
    return Path(__file__).resolve().parents[2]


def validate_defense_sheet(root: Path, results: Path | None = None) -> DefenseSheet:
    root = secure_io.absolute_lexical(root)
    suite_data, report = validate.validate_project(root, results)
    if not report.ok or suite_data is None:
        detail = "\n".join(report.errors[:10])
        raise DefenseBuildError(f"base black-box suite failed validation:\n{detail}")

    catalog = suite_data.use_case_catalog
    if not isinstance(catalog, dict):
        raise DefenseBuildError("base suite is missing use-cases.json catalog")

    defense_path = root / "papers" / "blackbox" / "defense-sheet.json"
    try:
        raw = secure_io.load_strict_json_file(
            defense_path,
            root=root,
            label="defense sheet JSON",
            max_bytes=2 * 1024 * 1024,
            max_depth=32,
        )
    except (OSError, secure_io.FileSafetyError, secure_io.StrictJSONError) as exc:
        raise DefenseBuildError(f"could not load {DEFENSE_JSON_REL}: {exc}") from exc

    if not isinstance(raw, dict):
        raise DefenseBuildError(f"{DEFENSE_JSON_REL} must contain a JSON object")

    valid_roles = {str(r["id"]) for r in catalog.get("roles", [])}
    valid_modules = set(catalog.get("modules", []))
    valid_ucs = {str(uc["id"]) for uc in catalog.get("use_cases", [])}
    valid_tech_cases = {c.case_id for c in suite_data.cases}

    tracks = raw.get("tracks")
    if not isinstance(tracks, list) or not tracks:
        raise DefenseBuildError("defense sheet must define at least one evaluator track")
    track_roles: set[str] = set()
    for idx, tr in enumerate(tracks, start=1):
        if not isinstance(tr, dict):
            raise DefenseBuildError(f"track #{idx} must be an object")
        roles_list = tr.get("roles")
        if not isinstance(roles_list, list) or not roles_list:
            raise DefenseBuildError(f"track #{idx} must declare a non-empty roles array")
        for r_id in roles_list:
            if r_id not in valid_roles:
                raise DefenseBuildError(f"track #{idx} references unknown role {r_id!r}")
            if r_id in track_roles:
                raise DefenseBuildError(f"role {r_id!r} assigned to multiple tracks")
            track_roles.add(r_id)
    missing_track_roles = sorted(valid_roles - track_roles)
    if missing_track_roles:
        raise DefenseBuildError(f"roles missing from evaluator tracks: {', '.join(missing_track_roles)}")

    cases = raw.get("cases")
    if not isinstance(cases, list) or not cases:
        raise DefenseBuildError("defense sheet must define a non-empty cases list")

    seen_ids: set[str] = set()
    covered_ucs: set[str] = set()
    covered_roles: set[str] = set()
    for idx, case in enumerate(cases, start=1):
        if not isinstance(case, dict):
            raise DefenseBuildError(f"defense case #{idx} must be an object")
        cid = str(case.get("id") or "").strip()
        if not cid:
            raise DefenseBuildError(f"defense case #{idx} missing id")
        if cid in seen_ids:
            raise DefenseBuildError(f"duplicate defense case id {cid!r}")
        seen_ids.add(cid)

        role = str(case.get("role") or "")
        if role not in valid_roles:
            raise DefenseBuildError(f"{cid}: unknown role {role!r}")
        covered_roles.add(role)

        module = str(case.get("module") or "")
        if module not in valid_modules:
            raise DefenseBuildError(f"{cid}: unknown module {module!r}")

        ucs = case.get("use_cases")
        if not isinstance(ucs, list) or not ucs:
            raise DefenseBuildError(f"{cid}: must reference at least one use case")
        for uc_id in ucs:
            if uc_id not in valid_ucs:
                raise DefenseBuildError(f"{cid}: unknown use case {uc_id!r}")
            covered_ucs.add(uc_id)

        mapped = case.get("mapped_cases")
        if not isinstance(mapped, list) or not mapped:
            raise DefenseBuildError(f"{cid}: must reference at least one technical mapped_cases ID")
        for tech_id in mapped:
            if tech_id not in valid_tech_cases:
                raise DefenseBuildError(f"{cid}: mapped_cases references unknown technical case {tech_id!r}")

        scenario = str(case.get("scenario") or "").strip()
        expected = str(case.get("expected") or "").strip()
        steps = case.get("steps")
        if not scenario or not expected:
            raise DefenseBuildError(f"{cid}: scenario and expected must be non-empty")
        if not isinstance(steps, list) or len(steps) < 2 or any(not str(s).strip() for s in steps):
            raise DefenseBuildError(f"{cid}: must define at least 2 non-empty steps")

    uncovered_ucs = sorted(valid_ucs - covered_ucs)
    if uncovered_ucs:
        raise DefenseBuildError(
            f"defense sheet does not cover {len(uncovered_ucs)} use case(s): {', '.join(uncovered_ucs)}"
        )

    uncovered_roles = sorted(valid_roles - covered_roles)
    if uncovered_roles:
        raise DefenseBuildError(
            f"defense sheet does not cover {len(uncovered_roles)} role group(s): {', '.join(uncovered_roles)}"
        )

    return DefenseSheet(
        title=str(raw.get("title") or "Black Box Testing — Live Evaluation Sheet"),
        version=str(raw.get("version") or "2026.11-defense.1"),
        allocated_time=str(raw.get("allocated_time") or "2 Hours"),
        technical_appendix=str(raw.get("technical_appendix") or "papers/blackbox/SYNAPSE-BlackBox-Testing.md"),
        tracks=tracks,
        cases=cases,
        suite_data=suite_data,
    )


def _configure_defense_footer(section: Any) -> None:
    footer = section.footer
    footer.is_linked_to_previous = False
    p = docx_template._add_styled_paragraph(
        footer,
        "SYNAPSE Black-Box Testing (Live Evaluation) — Page ",
        size_pt=9.5,
        color=docx_template.FOOTER_GRAY,
        align=WD_ALIGN_PARAGRAPH.RIGHT,
        space_before_pt=0.0,
        space_after_pt=0.0,
        line_spacing=1.0,
        replace_first=True,
    )
    docx_template._append_field(p, "PAGE", size_pt=9.5, color=docx_template.FOOTER_GRAY)
    r_of = p.add_run(" of ")
    docx_template._format_run(r_of, size_pt=9.5, color=docx_template.FOOTER_GRAY)
    docx_template._append_field(p, "NUMPAGES", size_pt=9.5, color=docx_template.FOOTER_GRAY)


def build_defense_docx(sheet: DefenseSheet, output_path: Path) -> Path:
    catalog = sheet.suite_data.use_case_catalog
    assert isinstance(catalog, dict)
    roles = list(catalog["roles"])
    modules_order = list(catalog["modules"])
    use_cases = list(catalog["use_cases"])

    role_by_id = {str(r["id"]): r for r in roles}
    role_to_track = {}
    for tr in sheet.tracks:
        for rid in tr["roles"]:
            role_to_track[str(rid)] = str(tr["id"])

    cases_by_role: dict[str, list[dict[str, Any]]] = {str(r["id"]): [] for r in roles}
    for case in sheet.cases:
        cases_by_role.setdefault(str(case["role"]), []).append(case)

    doc = Document()
    core = doc.core_properties
    core.title = sheet.title
    core.author = "SYNAPSE Capstone Team"
    core.comments = "Concise 2-hour live evaluation Black-Box Testing sheet aligned with Figure 5a-5f."
    core.created = docx_template.FIXED_TIMESTAMP
    core.modified = docx_template.FIXED_TIMESTAMP

    normal = doc.styles["Normal"]
    normal.font.name = "Times New Roman"
    normal.font.size = Pt(11)
    normal.font.color.rgb = docx_template.BLACK
    normal.paragraph_format.space_before = Pt(0)
    normal.paragraph_format.space_after = Pt(4)
    normal.paragraph_format.line_spacing = 1.15
    normal_rpr = normal._element.get_or_add_rPr()
    normal_rfonts = normal_rpr.find(docx_template.qn("w:rFonts"))
    if normal_rfonts is None:
        normal_rfonts = docx_template.OxmlElement("w:rFonts")
        normal_rpr.insert(0, normal_rfonts)
    for attr in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        normal_rfonts.set(docx_template.qn(attr), "Times New Roman")

    section = doc.sections[0]
    section.page_width = Inches(8.5)
    section.page_height = Inches(11.0)
    section.top_margin = Inches(0.5)
    section.bottom_margin = Inches(0.5)
    section.left_margin = Inches(0.5)
    section.right_margin = Inches(0.5)
    section.header_distance = Inches(0.3)
    section.footer_distance = Inches(0.3)
    _configure_defense_footer(section)

    # Title & plain-English intro
    docx_template._add_styled_paragraph(
        doc,
        "Black Box Testing",
        size_pt=13.0,
        bold=True,
        color=docx_template.BLACK,
        space_before_pt=0.0,
        space_after_pt=6.0,
        line_spacing=1.15,
    )
    docx_template._add_styled_paragraph(
        doc,
        (
            "Black Box Testing was conducted to verify SYNAPSE's functional behavior and role-based "
            "access controls based on user inputs and visible outputs. Organized by User Role, Module, "
            "and Functionality to match the Use Case Diagrams (Figure 5a–5f), this live evaluation sheet "
            f"covers all {len(use_cases)} use cases across {len(roles)} user roles and grant groups in "
            f"{len(sheet.cases)} concise test scenarios designed for a {sheet.allocated_time} review. "
            f"Extended technical/API security cases ({len(sheet.suite_data.cases)} total) remain documented "
            f"in {sheet.technical_appendix}."
        ),
        size_pt=11.0,
        color=docx_template.BLACK,
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        space_before_pt=0.0,
        space_after_pt=6.0,
        line_spacing=1.15,
    )

    # 1. 2-Hour Evaluator Split Plan
    docx_template._add_styled_paragraph(
        doc,
        "1. 2-Hour Live Evaluation Split Plan",
        size_pt=11.0,
        bold=True,
        color=docx_template.BLACK,
        space_before_pt=2.0,
        space_after_pt=4.0,
        keep_with_next=True,
    )
    track_table = doc.add_table(rows=1, cols=len(TRACK_COL_WIDTHS))
    docx_template._configure_fixed_table(track_table, TRACK_COL_WIDTHS)
    docx_template._populate_header_row(
        track_table.rows[0],
        ("Track", "Assigned Evaluator", "Assigned User Roles", "Cases", "Est. Time"),
        TRACK_COL_WIDTHS,
    )
    total_mins = 0
    for tr in sheet.tracks:
        tr_roles = [str(r) for r in tr["roles"]]
        tr_case_count = sum(len(cases_by_role.get(rid, [])) for rid in tr_roles)
        role_labels = ", ".join(str(role_by_id[rid]["label"]).split(" (")[0] for rid in tr_roles)
        mins = int(tr.get("estimated_minutes") or 0)
        total_mins += mins
        row = track_table.add_row()
        docx_template._set_row_flags(row, cant_split=True, tbl_header=False)
        vals: tuple[tuple[str, WD_ALIGN_PARAGRAPH, bool], ...] = (
            (str(tr["id"]), WD_ALIGN_PARAGRAPH.CENTER, True),
            (str(tr["evaluator"]), WD_ALIGN_PARAGRAPH.LEFT, False),
            (role_labels, WD_ALIGN_PARAGRAPH.LEFT, False),
            (str(tr_case_count), WD_ALIGN_PARAGRAPH.CENTER, True),
            (f"~{mins} mins", WD_ALIGN_PARAGRAPH.CENTER, False),
        )
        for ci, (txt, aln, bld) in enumerate(vals):
            docx_template._populate_cell_text(
                row.cells[ci], txt, TRACK_COL_WIDTHS[ci], bold=bld, size_pt=10.5, align=aln
            )
    tot_row = track_table.add_row()
    docx_template._set_row_flags(tot_row, cant_split=True, tbl_header=False)
    for ci, (txt, aln) in enumerate(
        (
            ("Total", WD_ALIGN_PARAGRAPH.CENTER),
            ("3 Evaluators (Parallel)", WD_ALIGN_PARAGRAPH.LEFT),
            ("All 16 RBAC Roles + Shared & Explicit Grants (100% of 70 Use Cases)", WD_ALIGN_PARAGRAPH.LEFT),
            (str(len(sheet.cases)), WD_ALIGN_PARAGRAPH.CENTER),
            ("~60–80 mins", WD_ALIGN_PARAGRAPH.CENTER),
        )
    ):
        docx_template._populate_cell_text(
            tot_row.cells[ci], txt, TRACK_COL_WIDTHS[ci], bold=True, size_pt=10.5, align=aln, fill_hex="EFEFEF"
        )

    # 2. Quick Use Case Traceability Table
    docx_template._add_styled_paragraph(
        doc,
        "2. Use Case Coverage Checklist (All 70 Use Cases)",
        size_pt=11.0,
        bold=True,
        color=docx_template.BLACK,
        space_before_pt=8.0,
        space_after_pt=4.0,
        keep_with_next=True,
    )
    uc_to_def_ids: dict[str, list[str]] = {str(uc["id"]): [] for uc in use_cases}
    for case in sheet.cases:
        for uc_id in case["use_cases"]:
            uc_to_def_ids.setdefault(str(uc_id), []).append(str(case["id"]))

    uc_table = doc.add_table(rows=1, cols=len(DEF_MATRIX_COL_WIDTHS))
    docx_template._configure_fixed_table(uc_table, DEF_MATRIX_COL_WIDTHS)
    docx_template._populate_header_row(
        uc_table.rows[0],
        ("UC ID", "Figure", "User Role", "Use Case", "Test Case(s)"),
        DEF_MATRIX_COL_WIDTHS,
    )
    for uc in use_cases:
        uc_id = str(uc["id"])
        row = uc_table.add_row()
        docx_template._set_row_flags(row, cant_split=True, tbl_header=False)
        role_lbl = str(role_by_id[str(uc["role"])]["label"]).split(" (Explicit")[0]
        test_refs = ", ".join(uc_to_def_ids.get(uc_id, ()))
        vals = (
            (uc_id, WD_ALIGN_PARAGRAPH.CENTER, True),
            (str(uc["figure"]), WD_ALIGN_PARAGRAPH.CENTER, False),
            (role_lbl, WD_ALIGN_PARAGRAPH.LEFT, False),
            (str(uc["title"]), WD_ALIGN_PARAGRAPH.LEFT, False),
            (test_refs, WD_ALIGN_PARAGRAPH.LEFT, True),
        )
        for ci, (txt, aln, bld) in enumerate(vals):
            docx_template._populate_cell_text(
                row.cells[ci], txt, DEF_MATRIX_COL_WIDTHS[ci], bold=bld, size_pt=10.0, align=aln
            )

    # 3. Role-by-Role Plain-English Test Case Tables
    doc.add_page_break()
    docx_template._add_styled_paragraph(
        doc,
        "3. Black-Box Test Cases by User Role and Module",
        size_pt=11.0,
        bold=True,
        color=docx_template.BLACK,
        space_before_pt=0.0,
        space_after_pt=4.0,
        keep_with_next=True,
    )

    module_rank = {m: idx for idx, m in enumerate(modules_order)}
    for role_idx, role in enumerate(roles, start=1):
        rid = str(role["id"])
        role_cases = cases_by_role.get(rid, [])
        if not role_cases:
            continue

        table = doc.add_table(rows=2, cols=len(DEF_CASE_COL_WIDTHS))
        docx_template._configure_fixed_table(table, DEF_CASE_COL_WIDTHS)

        banner_row = table.rows[0]
        docx_template._set_row_flags(banner_row, cant_split=True, tbl_header=True)
        banner_cell = docx_template._merged_span_cell(banner_row, DEF_CASE_COL_WIDTHS)
        docx_template._set_cell_shading(banner_cell, "E6E6E6")
        docx_template._set_cell_margins(banner_cell, top=65, bottom=65, left=80, right=80)
        banner_cell.vertical_alignment = WD_ALIGN_VERTICAL.CENTER

        role_label = str(role["label"])
        tr_name = role_to_track.get(rid, "")
        plural = "Case" if len(role_cases) == 1 else "Cases"
        docx_template._add_styled_paragraph(
            banner_cell,
            f"{role_idx}. {role_label} ({len(role_cases)} Test {plural}) — {tr_name}",
            size_pt=11.0,
            bold=True,
            italic=True,
            color=docx_template.BLACK,
            space_before_pt=0.0,
            space_after_pt=1.0,
            line_spacing=1.08,
            replace_first=True,
        )
        inherits_text = docx_template._format_inherits(role, role_by_id)
        if inherits_text != "—":
            docx_template._add_styled_paragraph(
                banner_cell,
                f"Inherits capabilities from: {inherits_text}",
                size_pt=9.5,
                color=docx_template.CHARCOAL,
                space_before_pt=0.0,
                space_after_pt=0.0,
                line_spacing=1.05,
            )

        docx_template._populate_header_row(
            table.rows[1],
            ("Test Case ID", "Module / Function", "Test Scenario", "Expected Results", "Pass", "Remarks"),
            DEF_CASE_COL_WIDTHS,
        )

        by_mod: dict[str, list[dict[str, Any]]] = {}
        for case in role_cases:
            by_mod.setdefault(str(case["module"]), []).append(case)
        ordered_mods = sorted(by_mod.keys(), key=lambda m: (module_rank.get(m, 999), m))

        for mod_name in ordered_mods:
            mod_cases = by_mod[mod_name]
            mod_row = table.add_row()
            docx_template._set_row_flags(mod_row, cant_split=True, tbl_header=False)
            mod_cell = docx_template._merged_span_cell(mod_row, DEF_CASE_COL_WIDTHS)
            docx_template._set_cell_shading(mod_cell, "F2F2F2")
            docx_template._set_cell_margins(mod_cell, top=45, bottom=45, left=80, right=80)
            docx_template._add_styled_paragraph(
                mod_cell,
                f"Module: {mod_name}",
                size_pt=10.5,
                bold=True,
                color=docx_template.BLACK,
                space_before_pt=0.0,
                space_after_pt=0.0,
                line_spacing=1.05,
                keep_with_next=True,
                replace_first=True,
            )

            for case in mod_cases:
                row = table.add_row()
                docx_template._set_row_flags(row, cant_split=True, tbl_header=False)
                for ci in range(len(DEF_CASE_COL_WIDTHS)):
                    row.cells[ci].vertical_alignment = WD_ALIGN_VERTICAL.TOP
                    docx_template._set_cell_width(row.cells[ci], DEF_CASE_COL_WIDTHS[ci])
                    docx_template._set_cell_margins(row.cells[ci], top=50, bottom=50, left=75, right=75)

                # Col 0: ID + UC tag
                docx_template._add_styled_paragraph(
                    row.cells[0],
                    str(case["id"]),
                    size_pt=10.5,
                    bold=True,
                    color=docx_template.BLACK,
                    space_before_pt=0.0,
                    space_after_pt=1.0,
                    line_spacing=1.05,
                    replace_first=True,
                )
                docx_template._add_styled_paragraph(
                    row.cells[0],
                    ", ".join(case["use_cases"]),
                    size_pt=9.0,
                    color=docx_template.MUTED,
                    space_before_pt=0.0,
                    space_after_pt=0.0,
                    line_spacing=1.0,
                )

                # Col 1: Module / Function
                docx_template._add_styled_paragraph(
                    row.cells[1],
                    str(case["function"]),
                    size_pt=10.5,
                    color=docx_template.BLACK,
                    space_before_pt=0.0,
                    space_after_pt=0.0,
                    line_spacing=1.08,
                    replace_first=True,
                )

                # Col 2: Test Scenario + Test Steps
                c2 = row.cells[2]
                docx_template._add_styled_paragraph(
                    c2,
                    str(case["scenario"]),
                    size_pt=10.5,
                    bold=True,
                    color=docx_template.BLACK,
                    space_before_pt=0.0,
                    space_after_pt=2.0,
                    line_spacing=1.08,
                    replace_first=True,
                )
                docx_template._add_styled_paragraph(
                    c2,
                    "Test Steps:",
                    size_pt=10.5,
                    bold=True,
                    color=docx_template.BLACK,
                    space_before_pt=0.0,
                    space_after_pt=1.0,
                    line_spacing=1.05,
                )
                for step_i, step_text in enumerate(case["steps"], start=1):
                    docx_template._add_styled_paragraph(
                        c2,
                        f"{step_i}. {step_text}",
                        size_pt=10.5,
                        color=docx_template.BLACK,
                        space_before_pt=0.0,
                        space_after_pt=0.5,
                        line_spacing=1.08,
                    )

                # Col 3: Expected Results
                docx_template._add_styled_paragraph(
                    row.cells[3],
                    str(case["expected"]),
                    size_pt=10.5,
                    color=docx_template.BLACK,
                    space_before_pt=0.0,
                    space_after_pt=0.0,
                    line_spacing=1.08,
                    replace_first=True,
                )

                # Col 4: Pass (blank for manual sign-off)
                docx_template._add_styled_paragraph(
                    row.cells[4],
                    "",
                    size_pt=10.5,
                    align=WD_ALIGN_PARAGRAPH.CENTER,
                    space_before_pt=0.0,
                    space_after_pt=0.0,
                    replace_first=True,
                )

                # Col 5: Remarks
                docx_template._add_styled_paragraph(
                    row.cells[5],
                    str(case.get("remarks") or ""),
                    size_pt=9.5,
                    color=docx_template.MUTED,
                    space_before_pt=0.0,
                    space_after_pt=0.0,
                    line_spacing=1.05,
                    replace_first=True,
                )

        docx_template._add_styled_paragraph(
            doc, "", size_pt=10.5, space_before_pt=0.0, space_after_pt=5.0, line_spacing=1.0
        )

    doc.save(str(output_path))
    return output_path


def publish_defense_sheet(root: Path, results: Path | None = None) -> tuple[Path, Path]:
    root = secure_io.absolute_lexical(root)
    secure_io.verify_absolute_directory(root)
    sheet = validate_defense_sheet(root, results)
    _, libreoffice = publish.check_prerequisites(require_pandoc=False)

    out_docx = root / "papers" / "blackbox" / "SYNAPSE-BlackBox-Testing-Defense.docx"
    out_pdf = root / "papers" / "blackbox" / "SYNAPSE-BlackBox-Testing-Defense.pdf"
    archive_path = root / "papers" / "blackbox" / "archive" / "2026-11-02"
    publish._protect_archive((out_docx, out_pdf), archive_path, root)

    stage_parent = out_docx.parent
    secure_io.verify_directory(root, stage_parent)
    with tempfile.TemporaryDirectory(prefix=".blackbox-defense-", dir=stage_parent) as temporary:
        stage = Path(temporary)
        secure_io.verify_directory(stage_parent, stage)
        home = stage / "home"
        resource = stage / "resource-empty"
        home.mkdir(mode=0o700)
        resource.mkdir(mode=0o700)
        staged_docx = stage / out_docx.name
        staged_pdf = stage / out_pdf.name
        build_defense_docx(sheet, staged_docx)
        docx_payload = publish._validate_docx(staged_docx, stage)

        environment = publish._safe_subprocess_environment(home)
        user_installation = (home / "libreoffice-profile").as_uri()
        publish._run(
            [
                libreoffice,
                f"-env:UserInstallation={user_installation}",
                "--headless",
                "--nologo",
                "--nodefault",
                "--nolockcheck",
                "--norestore",
                "--convert-to",
                "pdf:writer_pdf_Export",
                "--outdir",
                str(stage),
                str(staged_docx),
            ],
            "LibreOffice defense DOCX-to-PDF conversion",
            cwd=resource,
            environment=environment,
        )
        pdf_payload = publish._validate_pdf(staged_pdf, stage)
        publish._protect_archive((out_docx, out_pdf), archive_path, root)
        publish._publish_transaction(root, {out_docx: docx_payload, out_pdf: pdf_payload})

    print(
        f"Published Defense Sheet: {len(sheet.cases)} cases covering 100% of "
        f"{len(sheet.suite_data.use_case_catalog['use_cases'])} use cases."
    )
    print(f"Defense DOCX: {out_docx}")
    print(f"Defense PDF:  {out_pdf}")
    return out_docx, out_pdf


def main(argv: Sequence[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--root", type=Path, default=_default_root(), help="repository root")
    parser.add_argument("--results", type=Path, help="optional sanitized results overlay")
    args = parser.parse_args(argv)
    try:
        publish_defense_sheet(args.root, args.results)
    except (DefenseBuildError, publish.PublishError, OSError, secure_io.FileSafetyError) as exc:
        print(f"Defense sheet publication failed: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
