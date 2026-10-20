# Third-party files served from this site

These files used to be loaded from other websites (jsDelivr and Google Fonts). They are kept here so the
system looks and works the same even when the school's internet is slow, blocked or offline, and so no visitor
is sent to a third-party server. Each folder name is the exact version; to upgrade, add the new version next
to the old one, point the views at it, then delete the old folder.

| Folder | Version | Source | Licence |
|---|---|---|---|
| `bootstrap/5.3.3` | 5.3.3 | npm package `bootstrap` (dist/css, dist/js) | MIT |
| `bootstrap-icons/1.11.3` | 1.11.3 | npm package `bootstrap-icons` (font/) | MIT |
| `chartjs/4.4.4` | 4.4.4 | npm package `chart.js` (dist/chart.umd.js) | MIT |
| `inter/5.3.0` | 5.3.0 | npm package `@fontsource-variable/inter` (latin and latin-ext) | SIL OFL 1.1 |

Only change made to the original files: the final `sourceMappingURL` comment line was removed from the
Bootstrap and Chart.js files so browsers do not look for `.map` files that are not shipped.
