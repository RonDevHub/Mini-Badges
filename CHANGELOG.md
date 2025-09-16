# Changelog

## [1.2.0] – 2025-09-16
### Added
- [2025-09-12] New styles added: `classic`, `social`, `minimalist` & `pill`
- [2025-09-12] New metrics to display open and closed issues of a repo `issues-open`, `issues-closed`
- [2025-09-15] New metric to display the number of sponsors `sponsors` *(Query only possible with API)*
- [2025-09-15] New metrics for discussions of a repo have been added `discussions` for count in addition to `-lastdate`, `-lastupdate`, `-lasttitle`, `-lastauthor` *(Query only possible with API)*
- [2025-09-16] New metrics for `commits` to output all commits of a repo, with example `commits@branchname` only all commits of the branch, further metrics `-all`, `-last`, `-last-info`
- [2025-09-16] New metrics for `codesize` which prints the code size of a repo, with `-all` all repos *(only the default branch)*

### Changed
- [2025-09-08] The metrics for `follower` and `following` have been reworked, and the `-name` function has been removed for both. Github's REST API doesn't allow listing the last name sorted by date. The number of followers and followings will still be displayed accurately.

### Fixed
- [2025-09-15] The cache has been optimized
- [2025-09-15] The `allowedOwners` function in the configuration has been fixed and is now working again
- [2025-09-16] Fixed an issue with calls via the API key when specified

