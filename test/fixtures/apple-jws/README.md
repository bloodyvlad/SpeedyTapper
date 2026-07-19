# Apple JWS verifier fixtures

This directory contains an isolated, self-signed P-256 test chain. The custom
leaf and intermediate extensions mirror the two Apple certificate OIDs checked
by `AppleJwsVerifier`.

The private keys are intentionally committed test material. They are not Apple
keys, are not trusted by production, and must never be loaded outside the
standalone verifier tests. Production trust is pinned separately to the Apple
Root CA G2/G3 certificates in `server/certs/`.
