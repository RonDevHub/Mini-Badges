# Changelog

## [1.2.0] – 2025-09-15
### Added
- New styles added: `classic`, `social`, `minimalist` & `pill`
- New metrics to display open and closed issues of a repo `issues-open`, `issues-closed`
- New metric to display the number of sponsors `sponsors` and the name of the last sponsor `last-sponsor` *(Query only possible with API)*

### Changed
- The metrics for `follower` and `following` have been reworked, and the `-name` function has been removed for both. Github's REST API doesn't allow listing the last name sorted by date. The number of followers and followings will still be displayed accurately.

### Fixed
- The cache has been optimized

