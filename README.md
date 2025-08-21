# Mini‑Badges
![GitHub Repo stars](https://mini-badges.rondevhub.de/badge.php?type=github&metric=stars&owner=RonDevHub&repo=Mini-Badges) ![GitHub Repo language](https://mini-badges.rondevhub.de/badge.php?type=github&metric=top_language&owner=RonDevHub&repo=Mini-Badges) ![GitHub Repo license](https://mini-badges.rondevhub.de/badge.php?type=github&metric=license&owner=RonDevHub&repo=Mini-Badges) ![GitHub Repo release](https://mini-badges.rondevhub.de/badge.php?type=github&metric=release&owner=RonDevHub&repo=Mini-Badges) ![GitHub Repo release](https://mini-badges.rondevhub.de/badge.php?type=static&textLeft=PHP&textRight=♥️)

<a href="https://www.buymeacoffee.com/RonDev" target="_blank">![Buy me a coffee](https://mini-badges.rondevhub.de/badge.php?type=icon&icon=cup-togo&textIconRight=By%20me%20a%20Coffee&color2=0077b5)</a>  <a href='https://ko-fi.com/U6U31EV2VS' target='_blank'>![Buy me a coffee](https://mini-badges.rondevhub.de/badge.php?type=icon&icon=cup-togo&textIconRight=ko-fi.com&color2=0077b5)</a>

Ein kleines, eigenständiges Badge‑System in **PHP**, das ohne Redis/Docker/Node auskommt.
Unterstützt **statische** und **dynamische (GitHub)** Badges, **Styles** (ähnlich Shields),
**Farben**, **Sprachen** (de/en) und ein einfaches **Datei‑Caching**.

## Installation
1. Lade den Inhalt dieses Ordners auf deinen Webspace (z. B. `/www/htdocs/.../badges/`).
2. Stelle sicher, dass `cache/` beschreibbar ist (z. B. 0775 oder 0777).
3. Optional: Trage in `config.php` einen GitHub‑Token ein (höhere Rate Limits).
4. Rufe `examples.html` im Browser auf.

## Nutzung (Beispiele)
- Statisch:

![Statisch](https://mini-badges.rondevhub.de/badge.php?textLeft=RonDevHub❤️PHP&textRight=Awesome&style=flat&color1=000000&color2=3a6e8f)

  `badge.php?textLeft=RonDevHub❤️PHP&textRight=Awesome&style=flat&color1=000000&color2=3a6e8f`

- Mit Icon:

![Mit Icon](https://mini-badges.rondevhub.de/badge.php?type=icon&icon=github&textIconRight=GitHub&color2=000000)

  `badge.php?type=icon&icon=github&textIconRight=GitHub&color2=000000`

- GitHub Stars (de):

![GitHub Repo release](https://mini-badges.rondevhub.de/badge.php?type=github&metric=stars&owner=RonDevHub&repo=Mini-Badges&icon=star&lang=de)

  `badge.php?type=github&metric=stars&owner=RonDevHub&repo=Mini-Badges&icon=star&lang=de`

- Top‑Sprache (Repo):

![GitHub Repo release](https://mini-badges.rondevhub.de/badge.php?type=github&metric=top_language&owner=RonDevHub&repo=Mini-Badges)

  `badge.php?type=github&metric=top_language&owner=badges&repo=shields`

## Parameter
- `type=static|github|icon` – Standard: `static`
- `textLeft`, `textRight` – Texte für linkes/rechtes Feld (bei `static`)
- `color1`, `color2` – Hintergrundfarben (Hex oder Farbnamen)
- `textColor1`, `textColor2` – Textfarben
- `style=flat|flat-square|plastic|round|for-the-badge` - Standard: `flat`
- `lang=en|de` Standard: `en` Sprache für `type=github`, kann bearbeitet und um weitere Spachen erweitert werden
- `icon` – Name einer SVG in `icons/` (ohne `.svg`), wird mit `currentColor` gefärbt
- `iconColor` – Farbe für das Icon (Standard: `#fff`)
- `textIconLeft=...` – Zusätzlicher Text direkt neben dem Icon im ausgewählten Feld

### GitHub‑spezifisch
- `metric=stars|forks|issues|watchers|release|license|top_language`
- `owner=...` – GitHub User/Org
- `repo=...` – Repository

## Icons
Dieser Download enthält **keine** Icons. Lege deine SVGs (mit `fill="currentColor"`) in `icons/` ab,
z. B. `icons/star.svg`. Dann `&icon=star` nutzen.

## Lizenz
![GitHub Repo license](https://mini-badges.rondevhub.de/badge.php?type=github&metric=license&owner=RonDevHub&repo=Mini-Badges)
