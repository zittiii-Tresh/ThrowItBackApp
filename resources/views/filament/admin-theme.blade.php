{{-- Custom admin-panel theme overlay. Loaded via renderHook(HEAD_END) in
     AdminPanelProvider so it lives in <head> after Filament's own styles
     and wins specificity for the rules below. No Vite build required. --}}
<style>
    /* =========================================================================
       Brand palette (matches tailwind.config.js + Filament primary)
       --------------------------------------------------------------------- */
    :root {
        --sa-brand-50:  #F1EFFB;
        --sa-brand-100: #E0DCF6;
        --sa-brand-200: #C2BAEE;
        --sa-brand-300: #A197E3;
        --sa-brand-400: #8276D4;
        --sa-brand-500: #6457C4;
        --sa-brand-600: #534AB7;
        --sa-brand-700: #443C97;
        --sa-brand-800: #36307A;
        --sa-brand-900: #2A265F;
        --sa-brand-950: #17132F;

        /* Light-mode text: deep indigo-purple instead of pitch black. */
        --sa-text-strong: #1F1B3C;
        --sa-text-muted:  #514B7A;
    }

    /* =========================================================================
       Body — softer text + a faint brand wash on the page background
       --------------------------------------------------------------------- */
    .fi-body {
        color: var(--sa-text-strong);
        background:
            radial-gradient(ellipse 1100px 600px at 12% -5%,  rgba(193, 186, 238, 0.55), transparent 55%),
            radial-gradient(ellipse 900px 500px  at 95% 25%,  rgba(232, 200, 246, 0.40), transparent 55%),
            radial-gradient(ellipse 1000px 700px at 50% 110%, rgba(162, 151, 227, 0.35), transparent 55%),
            linear-gradient(180deg, #FDFCFF 0%, #F4F0FE 100%) !important;
        background-attachment: fixed;
    }
    .dark .fi-body {
        color: rgb(228 228 231);
        background:
            radial-gradient(ellipse 1100px 600px at 12% -5%,  rgba(123, 87, 219, 0.30), transparent 55%),
            radial-gradient(ellipse 900px 500px  at 95% 25%,  rgba(168, 85, 247, 0.22), transparent 55%),
            radial-gradient(ellipse 1000px 700px at 50% 110%, rgba(83, 74, 183, 0.30), transparent 55%),
            linear-gradient(180deg, #0B0813 0%, #160E2E 100%) !important;
        background-attachment: fixed;
    }

    /* =========================================================================
       Sections / cards / widgets — subtle purple-tinted gradient + border,
       with a vibrant brand glow on hover (matches the user-side treatment).
       NOTE: NOT applied to .fi-fo-component-ctn — that wraps every form
       field and would create doubled borders around inputs.
       --------------------------------------------------------------------- */
    .fi-section,
    .fi-wi-stats-overview-stat,
    .fi-ta-ctn,
    .sa-notify-card,
    .sa-notify-item {
        background-image: linear-gradient(135deg, rgba(241, 239, 251, 0.45), rgba(255, 255, 255, 0) 60%);
        border: 1px solid rgba(193, 186, 238, 0.45);
        box-shadow: 0 1px 2px rgba(83, 74, 183, 0.04);
        transition: border-color 250ms ease, box-shadow 350ms ease, transform 250ms ease;
    }
    .dark .fi-section,
    .dark .fi-wi-stats-overview-stat,
    .dark .fi-ta-ctn,
    .dark .sa-notify-card,
    .dark .sa-notify-item {
        background-image: linear-gradient(135deg, rgba(67, 60, 151, 0.14), rgba(0, 0, 0, 0) 65%);
        border: 1px solid rgba(83, 74, 183, 0.30);
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.30);
    }

    /* Hover glow — light mode: lavender border + soft purple halo */
    .fi-section:hover,
    .fi-wi-stats-overview-stat:hover,
    .fi-ta-ctn:hover,
    .sa-notify-card:hover,
    .sa-notify-item:hover {
        border-color: #8276D4 !important;  /* brand-400 */
        box-shadow:
            0 0 0 1px rgba(130, 118, 212, 0.45),
            0 8px 28px -6px rgba(123, 87, 219, 0.45),
            0 0 32px -4px rgba(168, 85, 247, 0.30) !important;
    }

    /* Hover glow — dark mode: punchier violet for contrast against near-black */
    .dark .fi-section:hover,
    .dark .fi-wi-stats-overview-stat:hover,
    .dark .fi-ta-ctn:hover,
    .dark .sa-notify-card:hover,
    .dark .sa-notify-item:hover {
        border-color: #A78BFA !important;
        box-shadow:
            0 0 0 1px rgba(167, 139, 250, 0.55),
            0 12px 40px -8px rgba(124, 58, 237, 0.55),
            0 0 48px -4px rgba(168, 85, 247, 0.35) !important;
    }

    /* Stat cards lift slightly on hover for that interactive feel */
    .fi-wi-stats-overview-stat:hover {
        transform: translateY(-2px);
    }

    /* =========================================================================
       Tables — center every cell + header consistently across resource
       tables AND dashboard widget tables. Gradient lives on the header
       ROW so it spans the full width without per-cell seams.
       --------------------------------------------------------------------- */

    /* Cells (data) — make the cell's direct child a centered flex
       container. Works uniformly for text, icons, badges, toggles, and
       custom views regardless of which wrapper Filament uses, because
       we're laying out the wrapper itself, not relying on text-align
       reaching the icon inside. */
    .fi-ta-cell {
        text-align: center !important;
    }
    .fi-ta-cell > * {
        display: flex !important;
        justify-content: center !important;
        align-items: center !important;
        width: 100%;
        text-align: center !important;
    }
    /* Override Filament's per-column alignment utility classes so even
       columns without ->alignment('center') in PHP get visually centered.
       Filament emits .fi-align-start / .fi-align-end / .fi-align-justify
       and Tailwind's .text-start / .text-end on cell wrappers. */
    .fi-ta-cell .fi-align-start,
    .fi-ta-cell .fi-align-end,
    .fi-ta-cell .fi-align-justify,
    .fi-ta-cell .text-start,
    .fi-ta-cell .text-end {
        justify-content: center !important;
        text-align: center !important;
    }
    /* Toggle column — keep the toggle widget inline (don't full-width it). */
    .fi-ta-cell > .fi-ta-toggle {
        width: auto;
        margin-inline: auto;
    }

    /* Headers — wipe per-cell backgrounds so the ROW gradient shows through */
    .fi-ta-header-cell {
        background: transparent !important;
        text-align: center !important;
        color: var(--sa-brand-800);
        font-weight: 600;
    }
    .dark .fi-ta-header-cell {
        color: var(--sa-brand-200);
    }
    /* Centre the inner label. The sort BUTTON is set to full width with
       the chevron absolute-positioned so the label sits at the true
       column center regardless of whether the chevron is showing —
       otherwise the chevron's space pushes the label off-center. */
    .fi-ta-header-cell-label,
    .fi-ta-header-cell-label-ctn {
        justify-content: center !important;
        text-align: center !important;
        width: 100%;
    }
    .fi-ta-header-cell-sort-btn {
        position: relative;
        width: 100%;
        justify-content: center !important;
        text-align: center !important;
    }
    /* Float the sort chevron to the right edge of the header cell so it
       doesn't interfere with the centered label text. */
    .fi-ta-header-cell-sort-btn > svg,
    .fi-ta-header-cell-sort-btn .fi-icon {
        position: absolute;
        right: 0.5rem;
        top: 50%;
        transform: translateY(-50%);
        opacity: 0.5;
    }
    .fi-ta-header-cell-sort-btn:hover > svg,
    .fi-ta-header-cell-sort-btn:hover .fi-icon {
        opacity: 1;
    }

    /* The header ROW carries the continuous gradient band */
    .fi-ta-header,
    .fi-ta-table > thead > tr,
    .fi-ta-table thead {
        background-image: linear-gradient(180deg, rgba(241, 239, 251, 0.85), rgba(224, 220, 246, 0.45));
        border-bottom: 1px solid rgba(162, 151, 227, 0.55);
    }
    .dark .fi-ta-header,
    .dark .fi-ta-table > thead > tr,
    .dark .fi-ta-table thead {
        background-image: linear-gradient(180deg, rgba(67, 60, 151, 0.22), rgba(42, 38, 95, 0.10));
        border-bottom: 1px solid rgba(83, 74, 183, 0.35);
    }

    /* Row hover + dividers */
    .fi-ta-row:hover {
        background-color: rgba(241, 239, 251, 0.55) !important;
    }
    .dark .fi-ta-row:hover {
        background-color: rgba(67, 60, 151, 0.10) !important;
    }
    .fi-ta-row {
        border-top: 1px solid rgba(193, 186, 238, 0.30);
    }
    .dark .fi-ta-row {
        border-top: 1px solid rgba(83, 74, 183, 0.18);
    }

    /* =========================================================================
       Forms — leave field containers alone (no doubled borders), just
       give inputs a soft purple focus ring instead of the default gray.
       --------------------------------------------------------------------- */
    .fi-input:focus,
    .fi-input-wrp:focus-within,
    .fi-select-input:focus {
        border-color: var(--sa-brand-400) !important;
        box-shadow: 0 0 0 2px rgba(162, 151, 227, 0.25) !important;
    }

    /* =========================================================================
       Sidebar + topbar — gradient brand accents
       --------------------------------------------------------------------- */
    .fi-sidebar {
        border-right: 1px solid rgba(193, 186, 238, 0.40);
    }
    .dark .fi-sidebar {
        border-right: 1px solid rgba(83, 74, 183, 0.25);
    }
    .fi-sidebar-item-active .fi-sidebar-item-button {
        background-image: linear-gradient(135deg, rgba(83, 74, 183, 0.12), rgba(162, 151, 227, 0.20));
    }
    .fi-topbar {
        border-bottom: 1px solid;
        border-image: linear-gradient(90deg, rgba(83, 74, 183, 0.45), rgba(162, 151, 227, 0.25), rgba(83, 74, 183, 0.10)) 1;
    }

    /* =========================================================================
       Stat-overview cards — gradient numbers
       --------------------------------------------------------------------- */
    .fi-wi-stats-overview-stat-value {
        background: linear-gradient(135deg, var(--sa-brand-700), var(--sa-brand-500));
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .dark .fi-wi-stats-overview-stat-value {
        background: linear-gradient(135deg, var(--sa-brand-200), var(--sa-brand-400));
        -webkit-background-clip: text;
        background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    /* =========================================================================
       Custom Notifications page — match the brand card treatment + breathing room
       --------------------------------------------------------------------- */
    .sa-notify-card {
        background-image: linear-gradient(135deg, rgba(241, 239, 251, 0.55), rgba(255, 255, 255, 0) 60%);
        border: 1px solid rgba(193, 186, 238, 0.55) !important;
    }
    .dark .sa-notify-card {
        background-image: linear-gradient(135deg, rgba(67, 60, 151, 0.16), rgba(0, 0, 0, 0) 65%);
        border: 1px solid rgba(83, 74, 183, 0.35) !important;
    }
    .sa-notify-item {
        background-image: linear-gradient(135deg, rgba(241, 239, 251, 0.40), rgba(255, 255, 255, 0) 60%);
        border-color: rgba(193, 186, 238, 0.50) !important;
        padding: 1rem 1.25rem !important;
    }
    .dark .sa-notify-item {
        background-image: linear-gradient(135deg, rgba(67, 60, 151, 0.12), rgba(0, 0, 0, 0) 65%);
        border-color: rgba(83, 74, 183, 0.30) !important;
    }

    /* =========================================================================
       Buttons — primary CTAs get a brand gradient + glow on hover
       --------------------------------------------------------------------- */
    .fi-btn-color-primary {
        background-image: linear-gradient(135deg, #6457C4 0%, #534AB7 50%, #443C97 100%) !important;
        box-shadow: 0 4px 14px -4px rgba(83, 74, 183, 0.55) !important;
        transition: box-shadow 250ms ease, transform 250ms ease, background-image 250ms ease !important;
    }
    .fi-btn-color-primary:hover {
        background-image: linear-gradient(135deg, #7568D8 0%, #6457C4 50%, #534AB7 100%) !important;
        box-shadow:
            0 6px 24px -4px rgba(123, 87, 219, 0.65),
            0 0 32px -4px rgba(168, 85, 247, 0.40) !important;
        transform: translateY(-1px);
    }

    /* Row hover — gentle purple glow lift on each table row */
    .fi-ta-row {
        transition: background-color 200ms ease, box-shadow 250ms ease;
    }

    /* Empty-state icon halo — matches Filament's TableWidget empty state.
       Defined explicitly here because Filament's compiled Tailwind doesn't
       include h-14/w-14 utility classes by default, so doing this with
       Tailwind alone leaves the div as a full-width block. */
    .sa-empty-icon-halo {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 3.5rem;
        height: 3.5rem;
        margin-left: auto;
        margin-right: auto;
        border-radius: 9999px;
        background-color: rgb(243 244 246);  /* gray-100 */
    }
    .dark .sa-empty-icon-halo {
        background-color: rgba(255, 255, 255, 0.05);
    }
    .sa-empty-icon-halo > svg,
    .sa-empty-icon-halo > .fi-icon {
        width: 1.75rem;
        height: 1.75rem;
    }
</style>
