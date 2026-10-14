# Composer distribution and release procedure

The clean-install matrix builds the Composer ZIP, validates its contents, and
installs that extracted distribution into Laravel 12 and 13 on SQLite, MySQL
and PostgreSQL. This tests the files consumers receive, including migrations,
configuration and package discovery. Tests, benchmarks, workflow configuration
and development tooling are excluded with `.gitattributes`.

Reproduce the archive check from the repository root with PHP's ZIP extension:

```bash
composer validate --strict
composer archive --format=zip --dir=dist --file=flowguard
php tools/check-distribution.php dist/flowguard.zip
sha256sum dist/flowguard.zip
```

The Laravel 13/SQLite clean-install job retains the ZIP and SHA-256 checksum as
`flowguard-composer-distribution`, associated with the workflow's exact commit.
An Actions artifact is a tested build, not a GitHub release or a Packagist
publication. Review all matrix results for that same commit before promotion.

## Stable publication

1. Review the bounded library scope and evidence requirements in
   `RELEASE_CRITERIA.md`. Keep independent human review and application-specific
   production acceptance status explicit; do not claim unperformed work passed.
2. Finalize the supported API and changelog, keeping host responsibilities and
   at-least-once delivery semantics explicit.
3. Verify all quality, clean-install demo-host, and native-restore workflows for the exact
   release commit. Inspect the Composer archive and checksum artifact.
4. Create the immutable `v1.0.0` tag at that reviewed commit and a GitHub release
   describing compatibility, migrations, host integration and known limits.
5. Submit `https://github.com/mykholy/laravel-flowguard` to Packagist under the
   maintainer's account and enable its GitHub update hook.
6. In a fresh application, install `mykholy/laravel-flowguard:^1.0`, migrate,
   run `flowguard:doctor`, and verify the installed source reference matches
   the reviewed tag. The staged README identifies the intended version; only report publication
   complete after this public-install check succeeds.

No Packagist credentials belong in the repository, workflow output or artifacts.
If a release fails validation, correct it in a new version; never move a
published stable tag to a different commit.

## Automated versioned publication

`.release/preview.json` and its matching notes select the version and channel (`beta` or `stable`).
The publication workflow validates the manifest on pull requests. After merge
to main it waits for successful CI, clean-install, demo-host, and native-restore workflows
on the **same commit**, then builds and validates the Composer archive and
publishes a GitHub release with ZIP and checksum attachments. Beta channels are
marked prerelease; stable channels are marked non-prerelease and latest. Existing tags
are never overwritten. The manifest validator accepts explicit 0.x beta tags or 1.x stable tags with
matching release notes and a dated changelog entry. It rejects channel/tag
mismatches; stable publication requires the documented v1 contract and scope.

Packagist indexes the Git tag through its configured update hook. Confirm the
indexed version and source SHA before reporting that Composer publication has
completed. A successful GitHub release does not by itself prove indexing.
