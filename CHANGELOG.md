# Changelog

## [1.2.0-beta] – ❓
### Added Codeberg Integration
- [2025-10-03] New integration for badges from :codeberg: Codeberg 
- [2025-10-03] Metrics added per repo: `stars`, `name`, `license`, `language`, `created-at`, `updated-at`, `forks`, `issues`, `issues-open`, `issues-closed`, `prs`, `prs-open`, `prs-closed`, `size`, `watchers`, `branch-default`, `release`, `releases`, `release-tag` and `release@`
- [2025-10-03] Metrics added for all repos: `issues-all`, `issues-allopen`, `issues-allclosed`, `prs-all`, `prs-allopen`, `prs-allclosed`
- [2025-10-03] Metrics added for user info: `username`, `location`, *`created` is no longer supported and has been renamed to* `register`, `followers`, `following`, `stars-give`
- [2025-10-06] Metrics added for all repos: `stars-all`, `forks-all`, `watchers-all`, `size-all`, `releases-all`, `lastcommit`, `lastcommit-info`, `lastcommit-infos`, `milestones-all`, `milestones-allopen`, `milestones-allclosed`
- [2025-10-06] Metrics added per repo: `milestones`, `milestones-open`, `milestones-closed`, `milestonesinfo`, `milestonesinfo-open`, `milestonesinfo-closed`
- [2025-10-18] Metrics added per repo: `downloads`, `downloads-latest`
- [2025-10-18] Metrics added for all repo: `downloads-all`, `respos`
- [2025-10-21] Metrics added: `created-since`, `updated-since`, `register-since`

### Added generally
- [2025-10-20] Codeberg Badges added to Wiki page
- [2025-10-20] Languages ​​updated (en,de,es,it,fr,uk)
- [2025-10-20] The code has been cleaned up and optimized a bit

### Changed
- [2025-10-18] Unnecessary API queries
- [2025-10-21] Metric `created` in the user info has been renamed to `register`

### Fixed
- [2025-10-06] Functions have been adjusted so that the information is cached with fewer API calls
- [2025-10-21] Fixed a small bug in the calculation of the time elapsed since the account was created, in the Github badges
