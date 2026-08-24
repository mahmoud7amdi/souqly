{{--
    The print stylesheet. Shared by both invoice designs and, in a reduced form,
    by the receipt.

    Three constraints shape everything below, and none of them are preferences:

    1. **DomPDF never sees `app.css`.** It renders this file and nothing else, so
       every colour is a literal hex and every rule is written out. The values are
       the design-system v2.2 ramp by hand, matching `purchase/pdf.blade.php` so
       the two documents a business sends out look like they came from the same
       company: #007867 (brand-700) for rules, the slate ramp for greys.

    2. **DomPDF has no flexbox and no grid.** Anything that needs two things
       side by side is a `<table>`. That is not laziness carried over from 2005 —
       it is the only box model both renderers agree on, and the alternative is
       two templates that drift.

    3. **Depth cannot be a shadow.** The design directive asks for layered depth,
       and on screen `app.css` delivers it with soft shadows — which is why the
       print block at `app.css:1963` strips them: a shadow prints as a grey
       smudge. So on paper the same hierarchy is carried by tint, rule weight and
       spacing instead. The accent band on the elegant design and the shaded
       header row on the classic one are doing the job a shadow does on screen.

    `$accent` arrives already validated as a hex colour — `highlight_color` is a
    free-text settings field, and interpolating it raw into a stylesheet is how a
    text input becomes a CSS injection. See `PrintService::accent()`.
--}}
<style>
    /* Arabic needs a font that actually has the glyphs. In the browser that is
       self-hosted Cairo, the same face as the rest of the app; DomPDF cannot load
       it, so it falls back to DejaVu Sans, the one bundled font covering Arabic
       script. Direction is set on <html>, so nothing here hardcodes a side. */
    * { font-family: {{ $forPdf ? "'DejaVu Sans', sans-serif" : "'Cairo', 'DejaVu Sans', sans-serif" }}; }

    @page { size: A4; margin: 12mm; }

    html, body { margin: 0; padding: 0; }

    body {
        font-size: 11px;
        line-height: 1.45;
        color: #0f172a;
        background: #ffffff;
    }

    /* On screen the document floats on the app's canvas colour so the page edge
       is visible; on paper the canvas is the paper. */
    @media screen {
        body { background: #f1f5f4; padding: 24px 12px; }
        .sheet {
            max-width: 210mm;
            margin: 0 auto;
            padding: 14mm;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .06), 0 12px 32px -12px rgba(15, 23, 42, .18);
        }
    }

    h1, h2, h3 { margin: 0; font-weight: 700; }

    .muted { color: #64748b; }
    .quiet { color: #94a3b8; }
    .strong { font-weight: 700; }
    .small { font-size: 10px; }
    .tiny { font-size: 9px; }

    /* Figures read left-to-right even on an Arabic invoice: "1,250.00" is not a
       word, and mirroring it produces "00.052,1". `direction: ltr` on the cell is
       what keeps the digits and their separators in the right order, while
       `text-align: end` keeps the column on the correct edge of an RTL table. */
    .num { text-align: end; direction: ltr; white-space: nowrap; }
    .start { text-align: start; }
    .end { text-align: end; }
    .center { text-align: center; }

    .rule { border: 0; border-top: 2px solid {{ $accent }}; margin: 0; }
    .hair { border: 0; border-top: 1px solid #e2e8e6; margin: 0; }

    /* Structural two-column blocks. `layout: fixed` because DomPDF's automatic
       table layout will happily give a 3-word address column 70% of the width. */
    table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.grid > tbody > tr > td { padding: 0; border: 0; vertical-align: top; }

    /* The product table. */
    table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
    table.items th {
        padding: 7px 6px;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        text-align: start;
        color: #475569;
    }
    table.items td { padding: 7px 6px; vertical-align: top; }
    table.items tr { page-break-inside: avoid; }
    table.items thead { display: table-header-group; }
    table.items .line-note { display: block; color: #64748b; font-size: 9px; }
    table.items img.thumb { width: 32px; height: 32px; object-fit: cover; }

    /* The totals ladder. 46% wide and pushed to the end edge — `margin-inline-start`
       is what makes that mirror correctly in Arabic. */
    table.totals { width: 46%; border-collapse: collapse; margin-top: 12px; margin-inline-start: auto; }
    table.totals td { padding: 4px 8px; border: 0; }
    table.totals tr.emphasis td {
        font-weight: 700;
        font-size: 12px;
        border-top: 1px solid #7b8d88;
    }

    .block-label {
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .06em;
        text-transform: uppercase;
        color: {{ $accent }};
        margin-bottom: 3px;
    }

    .logo { max-height: 64px; max-width: 200px; }
    .letter-head { width: 100%; max-height: 120px; margin-bottom: 10px; }
    .barcode { height: 40px; }

    .notes { margin-top: 16px; }

    /* Hidden on paper, shown on screen: the toolbar. Its counterpart in
       `app.css` is `.no-print`, and the name is kept identical so the two files
       do not teach two vocabularies for the same idea. */
    .no-print { }
    @media print { .no-print { display: none !important; } }
</style>
