# Changelog

## [1.2.0] – 2025-09-15
### Added
- New styles added: `classic`, `social`, `minimalist` & `pill`
- New metrics to display open and closed issues of a repo `issues-open`, `issues-closed`
- New metric to display the number of sponsors `sponsors` *(Query only possible with API)*
- New metrics for discussions of a repo have been added `discussions` for count in addition to `-lastdate`, `-lastupdate`, `-lasttitle`, `-lastauthor` *(Query only possible with API)*

### Changed
- The metrics for `follower` and `following` have been reworked, and the `-name` function has been removed for both. Github's REST API doesn't allow listing the last name sorted by date. The number of followers and followings will still be displayed accurately.

### Fixed
- The cache has been optimized
- The `allowedOwners` function in the configuration has been fixed and is now working again

