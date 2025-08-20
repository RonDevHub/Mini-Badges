# Mini‑Badges

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
  `badge.php?text1=Ronny❤️PHP&text2=Awesome&style=flat&color1=black&color2=blue`

- GitHub Stars (de):  
  `badge.php?type=github&metric=stars&owner=badges&repo=shields&lang=de`

- Top‑Sprache (Repo):  
  `badge.php?type=github&metric=top_language&owner=badges&repo=shields`

## Parameter
- `type=static|github` – Standard: `static`
- `text1`, `text2` – Texte für linkes/rechtes Feld (bei `static`)
- `color1`, `color2` – Hintergrundfarben (Hex oder Farbnamen)
- `textColor1`, `textColor2` – Textfarben
- `style=flat|flat-square|plastic|for-the-badge`
- `lang=en|de`
- `icon` – Name einer SVG in `icons/` (ohne `.svg`), wird mit `currentColor` gefärbt
- `iconColor` – Farbe für das Icon (Standard: `#fff`)
- `iconPos=1|2` – Icon in linkem oder rechtem Feld
- `iconText=...` – Zusätzlicher Text direkt neben dem Icon im ausgewählten Feld

### GitHub‑spezifisch
- `metric=stars|forks|issues|watchers|release|license|top_language`
- `owner=...` – GitHub User/Org
- `repo=...` – Repository

## Icons
Dieser Download enthält **keine** Icons. Lege deine SVGs (mit `fill="currentColor"`) in `icons/` ab,
z. B. `icons/star.svg`. Dann `&icon=star` nutzen.

## Lizenz
MIT
