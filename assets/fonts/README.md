# Bundled web fonts

These `.woff2` files are bundled with the plugin and served from the site's own
server — there are **no external/CDN font requests** at runtime (GDPR-safe,
wordpress.org-compliant). They are loaded only when the corresponding theme is
active (see `assets/css/fonts-clinical.css` and `assets/css/fonts-warm.css`).

Each file is the **latin-subset variable font** (the single woff2 carries the
whole weight axis), declared in the `@font-face` sheets with a weight range.

| File | Family | Used by theme | License |
|------|--------|---------------|---------|
| `public-sans-variable.woff2` | Public Sans (roman) | Clinical | SIL OFL 1.1 |
| `public-sans-italic.woff2` | Public Sans (italic) | Clinical | SIL OFL 1.1 |
| `newsreader-variable.woff2` | Newsreader (roman) | Patient-friendly | SIL OFL 1.1 |
| `newsreader-italic.woff2` | Newsreader (italic) | Patient-friendly | SIL OFL 1.1 |
| `mulish-variable.woff2` | Mulish (roman) | Patient-friendly | SIL OFL 1.1 |
| `mulish-italic.woff2` | Mulish (italic) | Patient-friendly | SIL OFL 1.1 |

## License

All three families are licensed under the **SIL Open Font License, Version 1.1**
(GPL-compatible). The full license text is available at
<https://openfontlicense.org/> and with each font's source:

- Public Sans — <https://github.com/uswds/public-sans>
- Newsreader — <https://github.com/productiontype/Newsreader>
- Mulish — <https://github.com/googlefonts/mulish>

## Updating

The latin-subset woff2 files were obtained from the Google Fonts static host
(`fonts.gstatic.com`) at build time and committed to the repository. They are
**not** fetched at runtime. To refresh, re-download the latin subset of each
family's variable font and replace the files in place (filenames are referenced
by the `@font-face` sheets, so keep them stable).
