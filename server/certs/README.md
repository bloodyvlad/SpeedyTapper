# Pinned Apple and Game Center trust certificates

`AppleRootCA-G2.pem` and `AppleRootCA-G3.pem` are PEM conversions of the DER
certificates published by the [Apple PKI](https://www.apple.com/certificateauthority/).

Expected SHA-256 DER fingerprints:

- Apple Root CA G2: `C2B9B042DD57830E7D117DAC55AC8AE19407D38E41D88F3215BC3A890444A050`
- Apple Root CA G3: `63343ABFB89A6A03EBB57E9B3F5FA7BE7C4F5C756F3017B3A8C488C3653E9179`

These are public trust anchors, not App Store Connect credentials. Never place
the private App Store Connect `.p8` key in this directory or in Git.

`DigiCertTrustedRootG4.pem` and
`DigiCertTrustedG4CodeSigningRSA4096SHA3842021CA1.pem` are the reviewed root and
intermediate currently needed to validate Game Center identity-signature leaf
certificates returned by `static.gc.apple.com`. They were downloaded over HTTPS
from DigiCert's public certificate repository:

- <https://cacerts.digicert.com/DigiCertTrustedRootG4.crt.pem>
- <https://cacerts.digicert.com/DigiCertTrustedG4CodeSigningRSA4096SHA3842021CA1.crt.pem>

Expected SHA-256 DER fingerprints:

- DigiCert Trusted Root G4: `552F7BDCF1A7AF9E6CE672017F4F12ABF77240C78E761AC203D1D9D20AC89988`
- DigiCert Trusted G4 Code Signing RSA4096 SHA384 2021 CA1: `46011EDE1C147EB2BC731A539B7C047B7EE93E48B9D3C3BA710CE132BBDFAC6B`

These files are public certificate-chain material, not private signing keys.
Replace them only after reviewing an Apple/DigiCert chain rotation and updating
the deterministic fingerprint tests.
