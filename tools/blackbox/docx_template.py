#!/usr/bin/env python3
"""Build a template-matched DOCX specification for the SYNAPSE black-box suite.

Models the visual table specification of ``sample-BlackBox-Testing.docx`` while
using US Letter Portrait with narrow 0.5" margins (7.50" / 10800 dxa content
width) and 10.5 pt / 11 pt Times New Roman typography.
"""

from __future__ import annotations

import datetime as dt
import re
from collections import Counter
from pathlib import Path
from typing import Any, Sequence

from docx import Document  # pyrefly: ignore[missing-import]
from docx.enum.table import WD_ALIGN_VERTICAL, WD_TABLE_ALIGNMENT  # pyrefly: ignore[missing-import]
from docx.enum.text import WD_ALIGN_PARAGRAPH  # pyrefly: ignore[missing-import]
from docx.oxml import OxmlElement, parse_xml  # pyrefly: ignore[missing-import]
from docx.oxml.ns import nsdecls, qn  # pyrefly: ignore[missing-import]
from docx.shared import Inches, Pt, RGBColor  # pyrefly: ignore[missing-import]

PAGE_WIDTH_DXA = 12240
PAGE_HEIGHT_DXA = 15840
MARGIN_DXA = 720
CONTENT_WIDTH_DXA = PAGE_WIDTH_DXA - (2 * MARGIN_DXA)  # 10800 dxa = 7.50 in

SUMMARY_COL_WIDTHS = (500, 3100, 2900, 3300, 1000)
MATRIX_COL_WIDTHS = (1050, 950, 1950, 1850, 2850, 2150)
CASE_COL_WIDTHS = (1150, 1650, 3900, 2400, 650, 1050)

BLACK = RGBColor(0x00, 0x00, 0x00)
CHARCOAL = RGBColor(0x22, 0x22, 0x22)
MUTED = RGBColor(0x33, 0x33, 0x33)
WHITE = RGBColor(0xFF, 0xFF, 0xFF)
FOOTER_GRAY = RGBColor(0x44, 0x44, 0x44)

FIXED_TIMESTAMP = dt.datetime(2026, 11, 26, 0, 0, 0)


def _clean_text(value: str | None) -> str:
    """Convert inline Markdown fragments into clean Word prose."""
    if not value:
        return ""
    text = value.replace("\r\n", "\n").replace("\r", "\n")
    text = re.sub(r"`([^`]+)`", r"\1", text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"\1", text)
    text = re.sub(r"\s*\n+\s*", " ", text)
    text = re.sub(r"\s{2,}", " ", text)
    return text.strip()


def _set_cell_shading(cell: Any, fill_hex: str) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    for existing in tc_pr.findall(qn("w:shd")):
        tc_pr.remove(existing)
    tc_pr.append(
        parse_xml(f'<w:shd {nsdecls("w")} w:val="clear" w:color="auto" w:fill="{fill_hex}"/>')
    )


def _set_cell_margins(
    cell: Any,
    *,
    top: int = 55,
    bottom: int = 55,
    left: int = 85,
    right: int = 85,
) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    for existing in tc_pr.findall(qn("w:tcMar")):
        tc_pr.remove(existing)
    tc_mar = OxmlElement("w:tcMar")
    for edge, dxa in (("top", top), ("left", left), ("bottom", bottom), ("right", right)):
        node = OxmlElement(f"w:{edge}")
        node.set(qn("w:w"), str(dxa))
        node.set(qn("w:type"), "dxa")
        tc_mar.append(node)
    tc_pr.append(tc_mar)


def _set_cell_width(cell: Any, width_dxa: int) -> None:
    tc_pr = cell._tc.get_or_add_tcPr()
    for existing in tc_pr.findall(qn("w:tcW")):
        tc_pr.remove(existing)
    tc_w = OxmlElement("w:tcW")
    tc_w.set(qn("w:w"), str(width_dxa))
    tc_w.set(qn("w:type"), "dxa")
    tc_pr.append(tc_w)


def _set_row_flags(row: Any, *, cant_split: bool = True, tbl_header: bool = False) -> None:
    tr_pr = row._tr.get_or_add_trPr()
    for tag in ("w:cantSplit", "w:tblHeader"):
        for existing in tr_pr.findall(qn(tag)):
            tr_pr.remove(existing)
    if cant_split:
        tr_pr.append(OxmlElement("w:cantSplit"))
    if tbl_header:
        tr_pr.append(OxmlElement("w:tblHeader"))


def _configure_fixed_table(table: Any, col_widths: Sequence[int]) -> None:
    total_width = sum(col_widths)
    table.alignment = WD_TABLE_ALIGNMENT.LEFT
    table.autofit = False

    tbl_pr = table._tbl.tblPr
    for tag in ("w:tblW", "w:tblLayout", "w:tblBorders"):
        for existing in tbl_pr.findall(qn(tag)):
            tbl_pr.remove(existing)

    tbl_w = OxmlElement("w:tblW")
    tbl_w.set(qn("w:w"), str(total_width))
    tbl_w.set(qn("w:type"), "dxa")
    tbl_pr.append(tbl_w)

    tbl_layout = OxmlElement("w:tblLayout")
    tbl_layout.set(qn("w:type"), "fixed")
    tbl_pr.append(tbl_layout)

    borders = parse_xml(
        f'<w:tblBorders {nsdecls("w")}>'
        '<w:top w:val="single" w:sz="6" w:space="0" w:color="000000"/>'
        '<w:left w:val="single" w:sz="6" w:space="0" w:color="000000"/>'
        '<w:bottom w:val="single" w:sz="6" w:space="0" w:color="000000"/>'
        '<w:right w:val="single" w:sz="6" w:space="0" w:color="000000"/>'
        '<w:insideH w:val="single" w:sz="6" w:space="0" w:color="000000"/>'
        '<w:insideV w:val="single" w:sz="6" w:space="0" w:color="000000"/>'
        "</w:tblBorders>"
    )
    tbl_pr.append(borders)

    tbl_grid = table._tbl.tblGrid
    for existing in list(tbl_grid):
        tbl_grid.remove(existing)
    for width in col_widths:
        grid_col = OxmlElement("w:gridCol")
        grid_col.set(qn("w:w"), str(width))
        tbl_grid.append(grid_col)


def _format_run(
    run: Any,
    *,
    size_pt: float = 10.5,
    bold: bool = False,
    italic: bool = False,
    color: RGBColor = BLACK,
) -> Any:
    run.font.name = "Times New Roman"
    run.font.size = Pt(size_pt)
    run.bold = bold
    run.italic = italic
    run.font.color.rgb = color
    r_pr = run._r.get_or_add_rPr()
    r_fonts = r_pr.find(qn("w:rFonts"))
    if r_fonts is None:
        r_fonts = OxmlElement("w:rFonts")
        r_pr.insert(0, r_fonts)
    for attr in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        r_fonts.set(qn(attr), "Times New Roman")
    return run


def _set_paragraph_metrics(
    paragraph: Any,
    *,
    align: WD_ALIGN_PARAGRAPH = WD_ALIGN_PARAGRAPH.LEFT,
    space_before_pt: float = 0.0,
    space_after_pt: float = 2.0,
    line_spacing: float = 1.08,
    keep_with_next: bool = False,
) -> Any:
    paragraph.alignment = align
    pf = paragraph.paragraph_format
    pf.space_before = Pt(space_before_pt)
    pf.space_after = Pt(space_after_pt)
    pf.line_spacing = line_spacing
    pf.keep_with_next = keep_with_next
    return paragraph


def _add_styled_paragraph(
    container: Any,
    text: str = "",
    *,
    size_pt: float = 10.5,
    bold: bool = False,
    italic: bool = False,
    color: RGBColor = BLACK,
    align: WD_ALIGN_PARAGRAPH = WD_ALIGN_PARAGRAPH.LEFT,
    space_before_pt: float = 0.0,
    space_after_pt: float = 2.0,
    line_spacing: float = 1.08,
    keep_with_next: bool = False,
    replace_first: bool = False,
) -> Any:
    if replace_first:
        paragraph = container.paragraphs[0]
        paragraph.text = ""
    else:
        paragraph = container.add_paragraph()
    _set_paragraph_metrics(
        paragraph,
        align=align,
        space_before_pt=space_before_pt,
        space_after_pt=space_after_pt,
        line_spacing=line_spacing,
        keep_with_next=keep_with_next,
    )
    if text:
        run = paragraph.add_run(text)
        _format_run(run, size_pt=size_pt, bold=bold, italic=italic, color=color)
    return paragraph


def _append_field(paragraph: Any, field_name: str, *, size_pt: float, color: RGBColor) -> None:
    fld = OxmlElement("w:fldSimple")
    fld.set(qn("w:instr"), f" {field_name} ")
    run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    r_fonts = OxmlElement("w:rFonts")
    for attr in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        r_fonts.set(qn(attr), "Times New Roman")
    r_pr.append(r_fonts)
    half_pts = str(int(round(size_pt * 2)))
    for sz_tag in ("w:sz", "w:szCs"):
        sz = OxmlElement(sz_tag)
        sz.set(qn("w:val"), half_pts)
        r_pr.append(sz)
    col = OxmlElement("w:color")
    col.set(qn("w:val"), f"{color[0]:02X}{color[1]:02X}{color[2]:02X}")
    r_pr.append(col)
    run.append(r_pr)
    t = OxmlElement("w:t")
    t.text = "1"
    run.append(t)
    fld.append(run)
    paragraph._p.append(fld)


def _configure_footer(section: Any) -> None:
    footer = section.footer
    footer.is_linked_to_previous = False
    p = _add_styled_paragraph(
        footer,
        "SYNAPSE Black-Box Testing — Page ",
        size_pt=9.5,
        color=FOOTER_GRAY,
        align=WD_ALIGN_PARAGRAPH.RIGHT,
        space_before_pt=0.0,
        space_after_pt=0.0,
        line_spacing=1.0,
        replace_first=True,
    )
    _append_field(p, "PAGE", size_pt=9.5, color=FOOTER_GRAY)
    r_of = p.add_run(" of ")
    _format_run(r_of, size_pt=9.5, color=FOOTER_GRAY)
    _append_field(p, "NUMPAGES", size_pt=9.5, color=FOOTER_GRAY)


def _populate_header_row(row: Any, headers: Sequence[str], col_widths: Sequence[int]) -> None:
    _set_row_flags(row, cant_split=True, tbl_header=True)
    for idx, (label, width) in enumerate(zip(headers, col_widths)):
        cell = row.cells[idx]
        cell.vertical_alignment = WD_ALIGN_VERTICAL.CENTER
        _set_cell_width(cell, width)
        _set_cell_shading(cell, "000000")
        _set_cell_margins(cell, top=65, bottom=65, left=75, right=75)
        _add_styled_paragraph(
            cell,
            label,
            size_pt=10.5,
            bold=True,
            color=WHITE,
            align=WD_ALIGN_PARAGRAPH.CENTER,
            space_before_pt=0.0,
            space_after_pt=0.0,
            line_spacing=1.05,
            replace_first=True,
        )


def _populate_cell_text(
    cell: Any,
    text: str,
    width_dxa: int,
    *,
    bold: bool = False,
    italic: bool = False,
    color: RGBColor = BLACK,
    size_pt: float = 10.5,
    align: WD_ALIGN_PARAGRAPH = WD_ALIGN_PARAGRAPH.LEFT,
    fill_hex: str | None = None,
) -> None:
    cell.vertical_alignment = WD_ALIGN_VERTICAL.TOP
    _set_cell_width(cell, width_dxa)
    _set_cell_margins(cell, top=55, bottom=55, left=85, right=85)
    if fill_hex:
        _set_cell_shading(cell, fill_hex)
    _add_styled_paragraph(
        cell,
        text,
        size_pt=size_pt,
        bold=bold,
        italic=italic,
        color=color,
        align=align,
        space_before_pt=0.0,
        space_after_pt=1.0,
        line_spacing=1.08,
        replace_first=True,
    )


def _merged_span_cell(row: Any, col_widths: Sequence[int]) -> Any:
    first_cell = row.cells[0]
    for other in row.cells[1:]:
        first_cell.merge(other)
    _set_cell_width(first_cell, sum(col_widths))
    return first_cell


def _build_role_catalog(suite: Any) -> tuple[list[dict[str, Any]], list[str], list[dict[str, Any]]]:
    catalog = suite.use_case_catalog
    if not isinstance(catalog, dict):
        return (
            [
                {
                    "id": "unclassified",
                    "label": "All Test Cases",
                    "kind": "shared_group",
                    "inherits": None,
                    "summary": "All cases in the suite.",
                }
            ],
            [],
            [],
        )
    roles = list(catalog.get("roles") or [])
    modules = list(catalog.get("modules") or [])
    use_cases = list(catalog.get("use_cases") or [])
    return roles, modules, use_cases


def _format_inherits(role_entry: dict[str, Any], role_by_id: dict[str, dict[str, Any]]) -> str:
    inherits = role_entry.get("inherits")
    if not inherits:
        return "—"
    parent_ids = inherits if isinstance(inherits, list) else [inherits]
    labels = [
        str(role_by_id[pid].get("label", pid)) if pid in role_by_id else str(pid)
        for pid in parent_ids
    ]
    return ", ".join(labels)


def _add_role_summary_table(
    doc: Any,
    suite: Any,
    roles: Sequence[dict[str, Any]],
    modules_order: Sequence[str],
) -> None:
    _add_styled_paragraph(
        doc,
        "1. Role Coverage Summary",
        size_pt=11.0,
        bold=True,
        color=BLACK,
        space_before_pt=6.0,
        space_after_pt=4.0,
        keep_with_next=True,
    )

    role_by_id = {str(r["id"]): r for r in roles}
    module_rank = {m: idx for idx, m in enumerate(modules_order)}
    cases_by_role: dict[str, list[Any]] = {str(r["id"]): [] for r in roles}
    for case in suite.cases:
        cases_by_role.setdefault(case.role or "unclassified", []).append(case)

    table = doc.add_table(rows=1, cols=len(SUMMARY_COL_WIDTHS))
    _configure_fixed_table(table, SUMMARY_COL_WIDTHS)
    _populate_header_row(
        table.rows[0],
        ("#", "User Role / Actor Group", "UML Inherits From", "Modules Covered", "Test Cases"),
        SUMMARY_COL_WIDTHS,
    )

    for index, role in enumerate(roles, start=1):
        rid = str(role["id"])
        role_cases = cases_by_role.get(rid, [])
        seen_modules = sorted(
            {c.module for c in role_cases if c.module},
            key=lambda m: (module_rank.get(m, 999), m),
        )
        row = table.add_row()
        _set_row_flags(row, cant_split=True, tbl_header=False)
        inherits_text = _format_inherits(role, role_by_id)
        modules_text = ", ".join(seen_modules) if seen_modules else "—"
        values: tuple[tuple[str, WD_ALIGN_PARAGRAPH, bool], ...] = (
            (str(index), WD_ALIGN_PARAGRAPH.CENTER, False),
            (str(role.get("label", rid)), WD_ALIGN_PARAGRAPH.LEFT, True),
            (inherits_text, WD_ALIGN_PARAGRAPH.LEFT, False),
            (modules_text, WD_ALIGN_PARAGRAPH.LEFT, False),
            (str(len(role_cases)), WD_ALIGN_PARAGRAPH.CENTER, True),
        )
        for col_idx, (text, align, bold) in enumerate(values):
            _populate_cell_text(
                row.cells[col_idx],
                text,
                SUMMARY_COL_WIDTHS[col_idx],
                bold=bold,
                size_pt=10.5,
                align=align,
            )

    total_row = table.add_row()
    _set_row_flags(total_row, cant_split=True, tbl_header=False)
    total_values = ("", "Total Black-Box Test Cases", "21 Actor / Role Groups", "15 Canonical Modules", str(len(suite.cases)))
    for col_idx, text in enumerate(total_values):
        align = WD_ALIGN_PARAGRAPH.CENTER if col_idx == 4 else WD_ALIGN_PARAGRAPH.LEFT
        _populate_cell_text(
            total_row.cells[col_idx],
            text,
            SUMMARY_COL_WIDTHS[col_idx],
            bold=True,
            size_pt=10.5,
            align=align,
            fill_hex="EFEFEF",
        )


def _add_use_case_matrix_table(
    doc: Any,
    suite: Any,
    roles: Sequence[dict[str, Any]],
    use_cases: Sequence[dict[str, Any]],
) -> None:
    if not use_cases:
        return
    _add_styled_paragraph(
        doc,
        "2. Use Case to Black-Box Traceability Matrix",
        size_pt=11.0,
        bold=True,
        color=BLACK,
        space_before_pt=10.0,
        space_after_pt=4.0,
        keep_with_next=True,
    )

    role_label = {str(r["id"]): str(r.get("label", r["id"])) for r in roles}
    uc_to_cases: dict[str, list[str]] = {str(uc["id"]): [] for uc in use_cases}
    for case in suite.cases:
        for uc_id in case.use_cases:
            uc_to_cases.setdefault(uc_id, []).append(case.case_id)

    table = doc.add_table(rows=1, cols=len(MATRIX_COL_WIDTHS))
    _configure_fixed_table(table, MATRIX_COL_WIDTHS)
    _populate_header_row(
        table.rows[0],
        ("UC ID", "Figure", "Primary Actor Role", "Module", "Use Case", "Black-Box Test Cases"),
        MATRIX_COL_WIDTHS,
    )

    for uc in use_cases:
        uc_id = str(uc["id"])
        row = table.add_row()
        _set_row_flags(row, cant_split=True, tbl_header=False)
        covering = ", ".join(uc_to_cases.get(uc_id, ())) or "—"
        cells_data: tuple[tuple[str, WD_ALIGN_PARAGRAPH, bool], ...] = (
            (uc_id, WD_ALIGN_PARAGRAPH.CENTER, True),
            (str(uc.get("figure", "")), WD_ALIGN_PARAGRAPH.CENTER, False),
            (role_label.get(str(uc.get("role", "")), str(uc.get("role", ""))), WD_ALIGN_PARAGRAPH.LEFT, False),
            (str(uc.get("module", "")), WD_ALIGN_PARAGRAPH.LEFT, False),
            (str(uc.get("title", "")), WD_ALIGN_PARAGRAPH.LEFT, False),
            (covering, WD_ALIGN_PARAGRAPH.LEFT, False),
        )
        for col_idx, (text, align, bold) in enumerate(cells_data):
            _populate_cell_text(
                row.cells[col_idx],
                text,
                MATRIX_COL_WIDTHS[col_idx],
                bold=bold,
                size_pt=10.5,
                align=align,
            )


def _populate_case_row(row: Any, case: Any) -> None:
    _set_row_flags(row, cant_split=True, tbl_header=False)
    for col_idx in range(len(CASE_COL_WIDTHS)):
        row.cells[col_idx].vertical_alignment = WD_ALIGN_VERTICAL.TOP
        _set_cell_width(row.cells[col_idx], CASE_COL_WIDTHS[col_idx])
        _set_cell_margins(row.cells[col_idx], top=55, bottom=55, left=80, right=80)

    # Col 0: Test Case ID + UC cross-refs
    c0 = row.cells[0]
    _add_styled_paragraph(
        c0,
        case.case_id,
        size_pt=10.5,
        bold=True,
        color=BLACK,
        space_before_pt=0.0,
        space_after_pt=1.5,
        line_spacing=1.05,
        replace_first=True,
    )
    if case.use_cases:
        _add_styled_paragraph(
            c0,
            ", ".join(case.use_cases),
            size_pt=9.0,
            color=MUTED,
            space_before_pt=0.0,
            space_after_pt=0.0,
            line_spacing=1.0,
        )

    # Col 1: Module / Function
    surface = _clean_text(case.fields.get("Surface", ""))
    mod_func = f"{case.module} / {surface}" if case.module and surface else (case.module or surface)
    _add_styled_paragraph(
        row.cells[1],
        mod_func,
        size_pt=10.5,
        color=BLACK,
        space_before_pt=0.0,
        space_after_pt=0.0,
        line_spacing=1.08,
        replace_first=True,
    )

    # Col 2: Test Scenario (Title, Preconditions, Test Steps)
    c2 = row.cells[2]
    _add_styled_paragraph(
        c2,
        _clean_text(case.title),
        size_pt=10.5,
        bold=True,
        color=BLACK,
        space_before_pt=0.0,
        space_after_pt=2.0,
        line_spacing=1.08,
        replace_first=True,
    )
    preconditions = _clean_text(case.fields.get("Preconditions", ""))
    if preconditions and preconditions.lower() != "none":
        p_pre = _add_styled_paragraph(
            c2,
            "",
            space_before_pt=0.0,
            space_after_pt=2.0,
            line_spacing=1.05,
        )
        _format_run(p_pre.add_run("Preconditions: "), size_pt=9.5, italic=True, color=CHARCOAL)
        _format_run(p_pre.add_run(preconditions), size_pt=9.5, color=CHARCOAL)

    _add_styled_paragraph(
        c2,
        "Test Steps:",
        size_pt=10.5,
        bold=True,
        color=BLACK,
        space_before_pt=1.0,
        space_after_pt=1.0,
        line_spacing=1.05,
    )
    for step_idx, step_text in enumerate(case.steps, start=1):
        _add_styled_paragraph(
            c2,
            f"{step_idx}. {_clean_text(step_text)}",
            size_pt=10.5,
            color=BLACK,
            space_before_pt=0.0,
            space_after_pt=1.0,
            line_spacing=1.08,
        )

    # Col 3: Expected Results
    c3 = row.cells[3]
    expected_text = _clean_text(case.fields.get("Expected", ""))
    _add_styled_paragraph(
        c3,
        expected_text,
        size_pt=10.5,
        color=BLACK,
        space_before_pt=0.0,
        space_after_pt=2.0,
        line_spacing=1.08,
        replace_first=True,
    )
    neg_text = _clean_text(case.fields.get("Negative/non-disclosure", ""))
    if neg_text:
        p_neg = _add_styled_paragraph(
            c3,
            "",
            space_before_pt=0.0,
            space_after_pt=0.0,
            line_spacing=1.05,
        )
        _format_run(
            p_neg.add_run("Non-disclosure / Negative: "),
            size_pt=9.5,
            italic=True,
            color=CHARCOAL,
        )
        _format_run(p_neg.add_run(neg_text), size_pt=9.5, color=CHARCOAL)

    # Col 4: Pass (blank for NOT_RUN to match sample manual checklist)
    verdict = case.effective_verdict
    pass_text = "" if verdict == "NOT_RUN" else verdict
    _add_styled_paragraph(
        row.cells[4],
        pass_text,
        size_pt=10.5,
        bold=bool(pass_text),
        color=BLACK,
        align=WD_ALIGN_PARAGRAPH.CENTER,
        space_before_pt=0.0,
        space_after_pt=0.0,
        line_spacing=1.05,
        replace_first=True,
    )

    # Col 5: Remarks
    remarks_parts: list[str] = []
    if case.lifecycle in ("DRAFT", "CONDITIONAL"):
        remarks_parts.append(f"[{case.lifecycle}]")
    if case.effective_actual:
        remarks_parts.append(_clean_text(case.effective_actual))
    remarks_text = " ".join(remarks_parts)
    _add_styled_paragraph(
        row.cells[5],
        remarks_text,
        size_pt=9.5 if remarks_parts and remarks_parts[0].startswith("[") else 10.5,
        color=MUTED if remarks_parts else BLACK,
        space_before_pt=0.0,
        space_after_pt=0.0,
        line_spacing=1.05,
        replace_first=True,
    )


def _add_role_tables(
    doc: Any,
    suite: Any,
    roles: Sequence[dict[str, Any]],
    modules_order: Sequence[str],
) -> None:
    role_by_id = {str(r["id"]): r for r in roles}
    module_rank = {m: idx for idx, m in enumerate(modules_order)}
    cases_by_role: dict[str, list[Any]] = {str(r["id"]): [] for r in roles}
    for case in suite.cases:
        cases_by_role.setdefault(case.role or "unclassified", []).append(case)

    doc.add_page_break()
    _add_styled_paragraph(
        doc,
        "3. Black-Box Test Cases by User Role and Module",
        size_pt=11.0,
        bold=True,
        color=BLACK,
        space_before_pt=0.0,
        space_after_pt=4.0,
        keep_with_next=True,
    )

    for role_idx, role in enumerate(roles, start=1):
        rid = str(role["id"])
        role_cases = cases_by_role.get(rid, [])
        if not role_cases:
            continue

        table = doc.add_table(rows=2, cols=len(CASE_COL_WIDTHS))
        _configure_fixed_table(table, CASE_COL_WIDTHS)

        # Row 0: Role Banner (repeating across page splits)
        banner_row = table.rows[0]
        _set_row_flags(banner_row, cant_split=True, tbl_header=True)
        banner_cell = _merged_span_cell(banner_row, CASE_COL_WIDTHS)
        _set_cell_shading(banner_cell, "E6E6E6")
        _set_cell_margins(banner_cell, top=70, bottom=70, left=85, right=85)
        banner_cell.vertical_alignment = WD_ALIGN_VERTICAL.CENTER

        role_label = str(role.get("label", rid))
        plural = "Case" if len(role_cases) == 1 else "Cases"
        _add_styled_paragraph(
            banner_cell,
            f"{role_idx}. {role_label} ({len(role_cases)} Test {plural})",
            size_pt=11.0,
            bold=True,
            italic=True,
            color=BLACK,
            space_before_pt=0.0,
            space_after_pt=1.5,
            line_spacing=1.08,
            replace_first=True,
        )
        inherits_text = _format_inherits(role, role_by_id)
        summary_text = str(role.get("summary") or "").strip()
        sub_parts: list[str] = []
        if inherits_text != "—":
            sub_parts.append(f"Inherits from: {inherits_text}")
        if summary_text:
            sub_parts.append(summary_text)
        if sub_parts:
            _add_styled_paragraph(
                banner_cell,
                " — ".join(sub_parts),
                size_pt=9.5,
                color=CHARCOAL,
                space_before_pt=0.0,
                space_after_pt=0.0,
                line_spacing=1.05,
            )

        # Row 1: Black 6-column Header (repeating across page splits)
        _populate_header_row(
            table.rows[1],
            ("Test Case ID", "Module / Function", "Test Scenario", "Expected Results", "Pass", "Remarks"),
            CASE_COL_WIDTHS,
        )

        # Group cases within this role by Module in canonical order
        cases_by_module: dict[str, list[Any]] = {}
        for case in role_cases:
            cases_by_module.setdefault(case.module or "General", []).append(case)
        ordered_modules = sorted(
            cases_by_module.keys(),
            key=lambda m: (module_rank.get(m, 999), m),
        )

        for module_name in ordered_modules:
            mod_cases = cases_by_module[module_name]
            mod_row = table.add_row()
            _set_row_flags(mod_row, cant_split=True, tbl_header=False)
            mod_cell = _merged_span_cell(mod_row, CASE_COL_WIDTHS)
            _set_cell_shading(mod_cell, "F2F2F2")
            _set_cell_margins(mod_cell, top=50, bottom=50, left=85, right=85)
            mod_word = "case" if len(mod_cases) == 1 else "cases"
            _add_styled_paragraph(
                mod_cell,
                f"Module: {module_name} ({len(mod_cases)} {mod_word})",
                size_pt=10.5,
                bold=True,
                color=BLACK,
                space_before_pt=0.0,
                space_after_pt=0.0,
                line_spacing=1.05,
                keep_with_next=True,
                replace_first=True,
            )

            for case in mod_cases:
                data_row = table.add_row()
                _populate_case_row(data_row, case)

        _add_styled_paragraph(
            doc,
            "",
            size_pt=10.5,
            space_before_pt=0.0,
            space_after_pt=6.0,
            line_spacing=1.0,
        )


def build_template_docx(
    suite: Any,
    output_path: Path,
    *,
    title: str = "Black Box Testing",
) -> Path:
    """Render ``suite`` into a hardened, template-matched DOCX file at ``output_path``."""
    doc = Document()
    core = doc.core_properties
    core.title = title
    core.author = "SYNAPSE Capstone Team"
    core.comments = "Generated from validated black-box sections and canonical use-case catalog."
    core.created = FIXED_TIMESTAMP
    core.modified = FIXED_TIMESTAMP

    # Normalize base document styles to Times New Roman 11 pt
    normal = doc.styles["Normal"]
    normal.font.name = "Times New Roman"
    normal.font.size = Pt(11)
    normal.font.color.rgb = BLACK
    normal.paragraph_format.space_before = Pt(0)
    normal.paragraph_format.space_after = Pt(4)
    normal.paragraph_format.line_spacing = 1.15
    normal_rpr = normal._element.get_or_add_rPr()
    normal_rfonts = normal_rpr.find(qn("w:rFonts"))
    if normal_rfonts is None:
        normal_rfonts = OxmlElement("w:rFonts")
        normal_rpr.insert(0, normal_rfonts)
    for attr in ("w:ascii", "w:hAnsi", "w:cs", "w:eastAsia"):
        normal_rfonts.set(qn(attr), "Times New Roman")

    # Configure US Letter Portrait with 0.5" narrow margins (10800 dxa usable width)
    section = doc.sections[0]
    section.page_width = Inches(8.5)
    section.page_height = Inches(11.0)
    section.top_margin = Inches(0.5)
    section.bottom_margin = Inches(0.5)
    section.left_margin = Inches(0.5)
    section.right_margin = Inches(0.5)
    section.header_distance = Inches(0.3)
    section.footer_distance = Inches(0.3)
    _configure_footer(section)

    roles, modules_order, use_cases = _build_role_catalog(suite)
    verdict_counts = Counter(case.effective_verdict for case in suite.cases)

    # Document Header Title & Narrative
    _add_styled_paragraph(
        doc,
        "Black Box Testing",
        size_pt=13.0,
        bold=True,
        color=BLACK,
        space_before_pt=0.0,
        space_after_pt=6.0,
        line_spacing=1.15,
    )
    _add_styled_paragraph(
        doc,
        (
            "Black Box Testing was conducted to evaluate SYNAPSE's functional, security, and "
            "role-boundary correctness based on user inputs, system workflows, and expected "
            "outputs without examining internal implementation logic. In alignment with the "
            "capstone technical adviser guidelines and Use Case Diagrams (Figure 5a–5f), all "
            f"{len(suite.cases)} test cases across {len(modules_order) or 15} modules and "
            f"{len(use_cases)} use cases are organized by User Role, Module, and Functionality "
            f"covering {len(roles)} actor and explicit-grant groups."
        ),
        size_pt=11.0,
        color=BLACK,
        align=WD_ALIGN_PARAGRAPH.JUSTIFY,
        space_before_pt=0.0,
        space_after_pt=4.0,
        line_spacing=1.15,
    )
    version = str(suite.metadata.get("version") or "draft")
    short_commit = suite.target_commit[:7] if suite.target_commit else "unknown"
    exec_parts = [f"{k}={verdict_counts[k]}" for k in ("NOT_RUN", "PASS", "FAIL", "BLOCKED", "NOT_IN_RUN") if verdict_counts[k]]
    _add_styled_paragraph(
        doc,
        f"Suite Version: {version}  |  Target Commit: {short_commit}  |  Total Cases: {len(suite.cases)}  |  Execution State: {', '.join(exec_parts)}",
        size_pt=10.5,
        italic=True,
        color=MUTED,
        space_before_pt=0.0,
        space_after_pt=6.0,
        line_spacing=1.1,
    )

    _add_role_summary_table(doc, suite, roles, modules_order)
    _add_use_case_matrix_table(doc, suite, roles, use_cases)
    _add_role_tables(doc, suite, roles, modules_order)

    doc.save(str(output_path))
    return output_path
