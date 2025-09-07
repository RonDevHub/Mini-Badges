# Mini-Badges (beta) ![](https://mini-badges.rondevhub.de/icon/php/I❤️U-000000-fff/pill/-787CB5)
> **🫵 Note:**
> This script is currently in **Beta**.  
> If you like, you can try the `beta` branch and test it out.  
> Please keep in mind that bugs may still occur here and there.  
> The [**Wiki**](/wiki) is still **under construction**.
---
![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/created_at) ![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/stars) ![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/issues) ![GitHub Repo language](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/top_language) ![GitHub Repo license](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/license) ![GitHub Repo release](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/release) ![GitHub Repo release](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/forks) ![GitHub Repo downlods](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/downloads) ![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/watchers) ![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/commit-info) ![GitHub Repo downlods](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/branches)

[![Buy me a coffee](https://mini-badges.rondevhub.de/icon/cuptogo/Buy_me_a_Coffee-c1d82f-222/flat "Buy me a coffee")](https://www.buymeacoffee.com/RonDev)
[![Buy me a coffee](https://mini-badges.rondevhub.de/icon/cuptogo/ko--fi.com-c1d82f-222/flat "Buy me a coffee")](https://ko-fi.com/U6U31EV2VS)
[![Sponsor me](https://mini-badges.rondevhub.de/icon/hearts-red/Sponsor_me/flat "Sponsor me")](https://github.com/sponsors/RonDevHub)
[![Pizza Power](https://mini-badges.rondevhub.de/icon/pizzaslice/Buy_me_a_pizza/flat "Pizza Power")](https://www.paypal.com/paypalme/Depressionist1/4,99)

---

A small, standalone **PHP Badge System** – no Redis, Docker, or Node required.  
It supports **static** and **dynamic (GitHub)** badges, multiple **styles** (similar to Shields),  
**colors**, **languages** (de/en), and simple **file caching**.

---

## 🚀 Installation
1. Upload the contents of this folder to your webspace (e.g. `/www/htdocs/.../badges/`).
2. Make sure the `cache/` directory is writable (e.g. `0775` or `0777`).
3. *(Optional)* Add a GitHub token in `helpers/config.php` (for higher rate limits).
4. Open `examples.html` in your browser.

---

## 🎯 Usage (Examples)

| URL input                | Badge output    | 
| :----------------------- | :-------------  |
| Underscore `_`           | Space ` `       |
| Double underscore `__`   | Underscore `_`  | 
| Double dash `--`         | Dash `-`        |
| Star (asterisk) `*`      | Placeholder Default Value |

---

### 🔹 Static

**URL pattern:**  
`static/{textLabel}-{bgColor}-{textColor}/{textMessage}-{bgColor}-{textColor}/{style}`

Example:  
![Static](https://mini-badges.rondevhub.de/static/RonDevHub❤️PHP-000000/Awesome-3a6e8f/flat)  
`static/RonDevHub❤️PHP-000000/Awesome-3a6e8f/flat`

---

### 🔹 With Icon

**URL pattern:**  
`icon/{icon}-{iconColor}/{textMessage}-{bgColor}-{textColor}/{style}/{textLabel}-{bgColor}-{textColor}`

Examples:  
![With Icon](https://mini-badges.rondevhub.de/icon/github-gray/Github-*-000000/flat)  
`/icon/github-gray/Github-*-000000/flat`

![With Icon](https://mini-badges.rondevhub.de/icon/github/👍-teal/*/Github-6d6e70)  
`/icon/github/👍-teal/*/Github-6d6e70`

---

### 🔹 GitHub

**URL pattern:**  
`/github/{owner}/{repo}/{metric}/{style}/{icon}-{iconColor}/{lang}/{backgroundColorMessage}-{textColorMessage}/{backgroundLabelColor}-{textColorLabel}`

Examples:  
- Stars: ![GitHub Stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/stars/*/*/de)  
  `github/{owner}/{repo}/stars/*/*/de`

- Top language: ![GitHub Top Language](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/top_language/*/*/*/green)  
  `github/{owner}/{repo}/top_language/*/*/*/green`

- With icon: ![GitHub Forks](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/forks/round/codefork)  
  `github/{owner}/{repo}/forks/round/codefork/*/green`

---

## ⚙️ Parameters

- `{textMessage}` – Badge message text (right side)
- `{textLabel}` – Badge label text (left side)
- `{bgColor}` – Background color (default: Label `#555`, Message `#0B7DBE`)
- `{textColor}` – Text color (default: Label & Message `#fff`)
- `{style}=flat|flat-square|plastic|round|for-the-badge` (default: `flat`)  

Examples:  
![flat](https://mini-badges.rondevhub.de/static/Style/flat/flat) 
![flat-square](https://mini-badges.rondevhub.de/static/Style/flat--square/flat-square) 
![plastic](https://mini-badges.rondevhub.de/static/Style/plastic/plastic) 
![round](https://mini-badges.rondevhub.de/static/Style/round/round) 
![for-the-badge](https://mini-badges.rondevhub.de/static/Style/for--the--badge/for-the-badge) 
![for-the-badge](https://mini-badges.rondevhub.de/static/Style/classic/classic)
![for-the-badge](https://mini-badges.rondevhub.de/static/Style/social/social)
![for-the-badge](https://mini-badges.rondevhub.de/static/Style/minimalist/minimalist)
![for-the-badge](https://mini-badges.rondevhub.de/static/Style/pill/pill)


- `{lang}=en|de` (default: `en`) → Used for GitHub badges. Can be extended.
- `{icon}` – Name of an SVG in `icons/` (without `.svg`). Colored with `currentColor`.
- `{iconColor}` – Icon color (default: `#fff`)

---

### 🔧 GitHub-specific
- `{metric}` - For example `stars`, `license`, `issues` ... more metrics are listed in the **wiki**
- `{owner}` – GitHub user/org
- `{repo}` – GitHub repository

---

## 🖼️ Icons
> **⚠️ Note:**  
> This download contains **no icons**.  
> Place your SVGs (with `fill="currentColor"`) in the `icons/` folder, e.g. `icons/star.svg`.  
> Then use `/star/` in the URL.

---

## 📜 License
![GitHub Repo license](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/license)