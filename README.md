# Mini-Badges <sup>![I❤️PHP](https://mini-badges.rondevhub.de/icon/php/I❤️U-000000-fff/pill/-787CB5)</sup> 
> 🫵 Please note that this is not a complete alternative to Shields.io, nor is it intended to replace or extend it. This is a small project I created for personal use. It is aimed at anyone who wants to run their own badges privately. It is not designed to be used as a service for multiple users.
> Please also keep in mind that, due to API calls, this project may eventually reach its limits. Nevertheless, I hope that some people will  enjoy it and perhaps make use of it.
> The [**Wiki**](https://commitcloud.net/RonDevHub/Mini-Badges/wiki) is still **under construction**.
> Support: [**Matrix Chat**](https://matrix.to/#/#mini-badges:matrix.s3cr.net)
---
![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/created_at)
![GitHub Repo stars](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/stars)
![Issues](https://mini-badges.rondevhub.de/forgejo/RonDevHub/Mini-Badges/issues)
![GitHub Repo language](https://mini-badges.rondevhub.de/forgejo/RonDevHub/Mini-Badges/language)
![GitHub Repo license](https://mini-badges.rondevhub.de/forgejo/RonDevHub/Mini-Badges/license/*/*/*/c1d82f-222)
![Release](https://mini-badges.rondevhub.de/forgejo/RonDevHub/Mini-Badges/release)
![Forks](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/forks)
![Watchers](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/watchers)
![Last update](https://mini-badges.rondevhub.de/forgejo/RonDevHub/Mini-Badges/updated-since "Last update")
![GitHub Repo downlods](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/branches)
![Milestones Info](https://mini-badges.rondevhub.de/forgejo/RonDevHub/Mini-Badges/milestonesinfo "Milestones Info")
[![status-badge](https://ci.commitcloud.net/api/badges/1/status.svg?events=push%2Cmanual)](https://ci.commitcloud.net/repos/1)

[![Buy me a coffee](https://mini-badges.rondevhub.de/icon/cuptogo/Buy_me_a_Coffee-c1d82f-222/social "Buy me a coffee")](https://www.buymeacoffee.com/RonDev)
[![Buy me a coffee](https://mini-badges.rondevhub.de/icon/cuptogo/ko--fi.com-c1d82f-222/social "Buy me a coffee")](https://ko-fi.com/U6U31EV2VS)
[![Sponsor me](https://mini-badges.rondevhub.de/icon/hearts-red/Sponsor_me/social "Sponsor me")](https://github.com/sponsors/RonDevHub)
[![Pizza Power](https://mini-badges.rondevhub.de/icon/pizzaslice/Buy_me_a_pizza/social "Pizza Power")](https://www.paypal.com/paypalme/Depressionist1/4,99)

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

### :computer: GitHub

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

### :computer: GitHub-specific
- `{metric}` - For example `stars`, `license`, `issues` ... more metrics are listed in the **wiki**
- `{owner}` – GitHub user/org
- `{repo}` – GitHub repository

---

### :codeberg:  Codeberg-specific
- `{metric}` - For example `stars`, `license`, `issues` ... more metrics are listed in the **CHANGELOG.md**
- `{owner}` – Codeberg user/org
- `{repo}` – Codeberg repository

---

## 🖼️ Icons
> **⚠️ Note:**  
> This download contains **no icons**.  
> Place your SVGs (with `fill="currentColor"`) in the `icons/` folder, e.g. `icons/star.svg`.  
> Then use `/star/` in the URL.

---

## 📜 License
![GitHub Repo license](https://mini-badges.rondevhub.de/github/RonDevHub/Mini-Badges/license) 