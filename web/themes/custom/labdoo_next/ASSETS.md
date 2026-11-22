# Bundled assets

Only Labdoo Next uses these pinned copies. The original theme continues to use
its existing map dependencies. Versions are unchanged from the previously loaded
CDN assets; this change is not a dependency upgrade. Retain upstream licenses
when updating the files.

The native Geolocation provider keeps Leaflet 1.9.4 and its lazy loader. Its
script, stylesheet and marker images are local copies of the pinned npm
package. `vendor/leaflet-1.9.4/UPSTREAM.json` records the verified SHA-512
package integrity; the dashboard retains its existing Leaflet version.

| Directory | Upstream | License file |
| --- | --- | --- |
| `vendor/leaflet-1.9.4` | https://github.com/Leaflet/Leaflet/tree/v1.9.4 | `LICENSE` (BSD-2-Clause) |
| `vendor/leaflet-1.0.3` | https://unpkg.com/leaflet@1.0.3/ | `LICENSE` (BSD-2-Clause) |
| `vendor/markercluster-1.5.3` | https://unpkg.com/leaflet.markercluster@1.5.3/ | `MIT-LICENCE.txt` |
| `vendor/chart.js-4.4.0` | https://unpkg.com/chart.js@4.4.0/ | `LICENSE.md` (MIT) |
| `vendor/chartjs-adapter-date-fns-3.0.0` | https://unpkg.com/chartjs-adapter-date-fns@3.0.0/ | `LICENSE.md` and `LICENSE.date-fns.md` (MIT) |
| `vendor/chartjs-plugin-datalabels-2.0.0` | https://unpkg.com/chartjs-plugin-datalabels@2.0.0/ | `LICENSE.md` (MIT) |

`images/labdoo-wordmark.webp` is a lossless WebP encoding of the official
1200 × 235 transparent Labdoo wordmark, replacing the enlarged 87 × 26 logo.
Source:
https://platform.labdoo.org/sites/default/files/content/labdoo-toolkit/logo-2-alpha.png
Attribution: Labdoo, https://platform.labdoo.org/content/labdoo-logos,
[CC BY-SA 3.0](https://creativecommons.org/licenses/by-sa/3.0/).
The lossless encoding changes the file format only. The transparent image is
rendered at 120 × 23.5 CSS pixels with its original aspect ratio.

Responsive wordmark variants at 120, 240 and 360 pixels retain the same source,
attribution and license. Lossless WebP variants also derive from the existing
Labdoo theme's hub, dootrip, laptop, school, footer and Stories artwork.
See [the reproducible build instructions](build/README.md) for their source
paths
and generated sizes. Original assets remain available for compatibility.

Simple bar charts use the generated Chart.js 4.4.0 subset
`vendor/chart.js-4.4.0/dist/chart-bar.js`; advanced charts upgrade to the
original
full bundle before loading adapters or plugins. Both retain the upstream MIT
license. The pinned npm build dependencies live in `build/package-lock.json`.
