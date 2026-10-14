# Security policy

## Supported versions

| Version | Security fixes |
| --- | --- |
| 1.x | Supported |
| 0.x prereleases | Upgrade to 1.x |

No independent human security audit has been completed. Flowguard does not
implement authentication, provider signature verification, remote exactly-once
effects or application approval identities. Hosts must validate those boundaries
before production deployment; see [the threat model](docs/THREAT_MODEL.md).

Do not submit credentials, customer payloads or exploitable details in public
issues. Contact the maintainer privately through the contact channels on
https://github.com/mykholy to coordinate disclosure. Include the affected version,
a minimal synthetic reproduction and impact. No response-time SLA is promised.

Security fixes preserve the supported contract when possible. Necessary behavior
changes will include upgrade guidance. Never move a published tag to hide a fix.
